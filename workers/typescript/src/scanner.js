import path from "node:path";
import ts from "typescript";
import { isStackOverflow } from "./errors.js";
import {
    BUILT_IN_EXCLUSIONS,
    exclusionRules,
    setActiveExclusions,
} from "./exclusions.js";
import {
    InputReadRecorder,
    inRootFile,
    ReadAttribution,
    recordUnreadSourceFiles,
} from "./input-reads.js";
import { TypeScriptLanguageFactCollector } from "./language-fact-collector.js";
import {
    anchoredAt,
    programDiagnostics,
    programWideCarrier,
} from "./program-diagnostics.js";
import {
    createRestrictedProgram,
    outputSources,
    parseConfig,
    programConfig,
} from "./program-host.js";
import {
    belowNodeModules,
    normalize,
    offeredPath,
    relativeInside,
    sourceFilesFrom,
    validatedInside,
    validateRoot,
    walk,
} from "./project-paths.js";
import {
    maxFileBytesFrom,
    readableAsItself,
    validateRequestedFiles,
} from "./request-validation.js";
import { parsedContentHashes } from "./source-caches.js";
export { excludedBy } from "./exclusions.js";

// Every contribution's owner key is this prefix and the file's project path.
const OWNER_KEY_PREFIX = "knossos.typescript:file:";

// Each retained ts.Program holds its own parsed default library and type
// checker (~100-120MB). A repo with many tsconfigs builds one program per
// config within a single scan; retaining them all at once exhausts the
// worker's --max-old-space-size cap. Bound the cache so peak live programs
// stays small while still allowing incremental reuse across scans in watch
// mode. A program is only needed until its files are emitted, so evicting the
// least-recently-used program never affects correctness — an evicted config is
// simply rebuilt from scratch on its next scan.
const MAX_CACHED_PROGRAMS = 2;

/**
 * Whether a config's program must be built for this request.
 *
 * It is when a requested file is among the config's own root files, the
 * files its `files`, `include` and `exclude` name, whichever config owns
 * that file: the program's environment follows every root file, so an edit
 * that imports a global script into a file two configs both root changes
 * what every file of both programs sees, and the core learns that only from
 * the environment of each program built. It also is when a requested file
 * not yet emitted is a root file of no config at all: such a file is emitted
 * by the first config's program that reaches it through imports, so each
 * config's program is built in turn until one holds it, or the fallback
 * program of the file's own group takes it (see #scanFallback).
 */
function needsProgram(request, requested, configPath, parsed) {
    const rootNames = new Set(
        (parsed.ownFileNames ?? parsed.fileNames).map((fileName) =>
            relativeInside(request.root, fileName),
        ),
    );
    return requested.some((relative) => {
        const key = normalize(relative);
        if (rootNames.has(key)) return true;
        if (request.emitted.has(key)) return false;
        const owner = request.owners.get(key);
        return owner === undefined || owner === configPath;
    });
}

/**
 * Every discovered file no config lists, the project's own declaration files
 * among them, grouped as the fallback reads them: the whole group is a
 * fallback program's root list.
 *
 * @returns {Map<string, {files: string[], parsed: object | undefined}>} directory => group
 */
function fallbackMembers(root, request, parsedConfigs) {
    return fallbackGroups(
        root,
        request.sourceFiles.filter(
            (relative) => !request.owners.has(normalize(relative)),
        ),
        parsedConfigs,
        request.packageDirectories,
    );
}

/** The program key of the fallback program for a group's directory. */
function fallbackProgramKey(root, directory) {
    return `fallback:${relativeInside(root, directory) || "."}`;
}

/**
 * Whether a program's reads for a file are the file's own contribution's:
 * only when a config lists the file and the program being built is that
 * config's program. A file no config lists is emitted by whichever config's
 * program reaches it first, or else by the fallback program of its own group,
 * so no program can claim its reads, the fallback program of its own group
 * included; nor can any program claim the reads of a file the core never
 * discovered.
 *
 * @param {{owners: Map<string, string>}} request the request, whose owners
 *   are taken as they are now: the answer does not follow a later change
 * @returns {(relative: string, programKey: string) => boolean}
 */
function programOwnership(request) {
    const owners = request.owners;
    return (relative, programKey) =>
        owners.has(relative) && owners.get(relative) === programKey;
}

/**
 * Which files of a program it does not emit, whether it was built or failed:
 * a file outside the project or under node_modules, one the request did not
 * name, one already emitted, one another config lists (its own program
 * describes it under the options the project really uses) and, in a fallback
 * program, one of another fallback group, which its own group's program
 * emits (see #scanFallback). A failed program marks only the files it would
 * have emitted, so a file left to another program is answered by that one.
 *
 * @param {Map<string, string>} owners the config owners this program defers
 *   to: the request's for a config's program, none for a fallback program
 * @returns {(relative: string | null) => boolean}
 */
function emissionSkipped(request, owners, fallback) {
    const { requestedSet, emitted, owner } = request;
    return (relative) =>
        relative === null ||
        belowNodeModules(relative) ||
        !requestedSet.has(relative) ||
        emitted.has(relative) ||
        (owners.has(relative) && owners.get(relative) !== owner) ||
        (fallback &&
            request.fallbackGroupOf.get(relative) !==
                request.fallbackDirectory);
}

/**
 * The checker, noting the source file of every declaration behind a symbol,
 * type or signature it answers with, so a requested file is attributed the
 * files its facts were resolved against. `files` is replaced per file.
 */
function declarationTracker(checker) {
    const tracker = { files: new Set() };
    const wrapped = new Map();
    tracker.checker = new Proxy(checker, {
        get(target, property) {
            const value = target[property];
            if (typeof value !== "function") return value;
            let wrapper = wrapped.get(property);
            if (wrapper === undefined) {
                wrapper = (...args) => {
                    const answer = value.apply(target, args);
                    noteDeclarationFiles(answer, tracker.files);
                    return answer;
                };
                wrapped.set(property, wrapper);
            }
            return wrapper;
        },
    });
    return tracker;
}

/** Add the files declaring what a checker answer names. */
function noteDeclarationFiles(answer, files) {
    if (answer === null || typeof answer !== "object") return;
    if (Array.isArray(answer)) {
        for (const item of answer) noteDeclarationFiles(item, files);
        return;
    }
    const symbols = [
        answer.escapedName === undefined ? undefined : answer,
        answer.symbol,
        answer.aliasSymbol,
    ];
    const declarations = symbols.flatMap(
        (symbol) => symbol?.declarations ?? [],
    );
    if (answer.declaration !== undefined) declarations.push(answer.declaration);
    for (const declaration of declarations) {
        // A synthesized declaration hangs off no file.
        const file = declaration?.getSourceFile?.();
        if (file !== undefined) files.add(file);
    }
}

/**
 * Performs bounded compiler-backed scanning without executing target modules.
 * Instances retain TypeScript programs for incremental reuse.
 */
export class TypeScriptScanner {
    /**
     * @param {{observeHostPath?: (stage: "load"|"read", absolute: string) => void, collectGarbage?: () => void}} [options]
     *   `observeHostPath` is a test seam, handed to each request's recorder.
     *   `collectGarbage` runs a full collection; it defaults to the `gc` that
     *   `--expose-gc` provides, and does nothing when the flag was not given.
     */
    constructor({ observeHostPath, collectGarbage } = {}) {
        this.programCache = new Map();
        this.observeHostPath = observeHostPath;
        this.collectGarbage = collectGarbage ?? (() => globalThis.gc?.());
        // Reset per request; see #cacheProgram.
        this.programsBuiltThisRequest = 0;
        // Set when a program leaves the cache; see #collectReleasedPrograms.
        this.releasedSinceCollection = false;
    }

    /**
     * Stream deterministic owned contributions for the requested source files.
     *
     * `source_files` lists every file of the language the core discovered: a
     * program for files no config includes is rooted on the whole group such
     * a file sits in, and only a listed file's reads can be unattributed.
     * A contribution for a file no config lists carries `listed: false`,
     * since whichever program reaches it first emits it.
     *
     * The result's `reads` are what every file of the request shares;
     * `unattributed_reads` are what the programs' other discovered files read
     * in the program that describes them, confirmed by `input_hashes` and
     * owned by no contribution; `environments` maps every program the request
     * built to the digest of its global declarations.
     *
     * @param {{root: unknown, files: unknown, config_files?: unknown, limits?: unknown, typescript_versions?: unknown, source_files?: unknown, exclusions?: unknown}} params
     * @param {(contribution: object) => void} emit
     * @returns {{files_scanned: number, programs: number, programs_reused: number, input_hashes: Record<string, string|null>, reads: Record<string, string|null>, unattributed_reads: Record<string, string|null>, environments: Record<string, string>}}
     */
    scan(params, emit) {
        // Held until the request has finished reading: a later program can
        // read a path again and disagree, which turns its value null, and a
        // contribution's `reads` must carry the value `input_hashes` ends with.
        const contributions = [];
        setActiveExclusions(
            exclusionRules(params.exclusions ?? BUILT_IN_EXCLUSIONS),
        );
        const { result, attribution } = this.#scanRequest(
            params,
            (contribution) => contributions.push(contribution),
        );
        for (const contribution of contributions) {
            emit({
                ...contribution,
                ...(contribution.program === undefined
                    ? {}
                    : {
                          environment: attribution.environmentOf(
                              contribution.program,
                          ),
                      }),
                reads: attribution.readsOf(
                    contribution.owner_key.slice(OWNER_KEY_PREFIX.length),
                ),
            });
        }
        const requested = Array.isArray(params.files) ? params.files : [];
        return {
            ...result,
            reads: attribution.sharedReads(requested),
            unattributed_reads: attribution.unattributedReads(requested),
            environments: attribution.environments(),
        };
    }

    /**
     * Scan one request, handing each contribution to `emit` without its
     * `reads`, which `attribution` gives once every read is in.
     *
     * @returns {{result: {files_scanned: number, programs: number, programs_reused: number, input_hashes: Record<string, string|null>}, attribution: ReadAttribution}}
     */
    #scanRequest(params, emit) {
        const root = validateRoot(params.root);
        const { accepted: requested, rejected } = validateRequestedFiles(
            root,
            params.files,
            params.limits,
        );
        const reads = new InputReadRecorder(this.observeHostPath);
        const maxFileBytes = maxFileBytesFrom(params.limits);
        const sourceFiles = sourceFilesFrom(params.source_files);
        const attribution = new ReadAttribution(
            root,
            reads,
            maxFileBytes,
            new Set(sourceFiles),
        );
        // Emitted before anything else so a file this worker cannot read still
        // gets its own contribution: raising it to the request would discard the
        // facts every other file in the batch contributes.
        for (const rejection of rejected) {
            if (rejection.failedRead) reads.record(rejection.relative, null);
            // Refused on what the file says rather than on its name, so what
            // was read is evidence: a stable tree matches the hash, a script
            // swapped and restored around the probe does not.
            else if (rejection.refusalHash !== undefined)
                reads.record(rejection.relative, rejection.refusalHash);
            emit(
                unscannableContribution(rejection.relative, rejection.message),
            );
        }
        const requestedSet = new Set(requested.map((file) => normalize(file)));
        const configPaths = configFilesForScan(root, params.config_files);
        const emitted = new Set();
        let programs = 0;
        let programsReused = 0;
        this.programsBuiltThisRequest = 0;

        const request = {
            root,
            maxFileBytes,
            reads,
            attribution,
            requestedSet,
            emitted,
            emit,
            versions: params.typescript_versions,
            vueProjects: Array.isArray(params.vue_projects)
                ? params.vue_projects
                : [],
            sourceFiles,
            packageDirectories: Array.isArray(params.package_directories)
                ? params.package_directories.filter(
                      (directory) => typeof directory === "string",
                  )
                : [],
        };
        const tally = (outcome) => {
            if (outcome === undefined) return;
            ++programs;
            if (outcome.reused) ++programsReused;
        };

        const parsedConfigs = configPaths.map((configPath) => [
            configPath,
            parseConfig(root, configPath, reads),
        ]);
        request.owners = configOwners(root, parsedConfigs);
        request.outputSources = outputSources(root, parsedConfigs);
        request.fallbackMembers = fallbackMembers(root, request, parsedConfigs);
        request.ownedByProgram = programOwnership(request);
        this.#scanConfigPrograms(parsedConfigs, requested, request, tally);

        const remaining = requested.filter(
            (relative) => !emitted.has(normalize(relative)),
        );
        if (remaining.length > 0) {
            this.#scanFallback(root, remaining, parsedConfigs, request, tally);
        }

        // Backstop: the PHP side requires exactly one contribution per requested
        // file and treats a gap as a hard error. A file can still be absent from
        // every program — it grew past the byte cap after validateRequestedFiles
        // stat'd it, or a symlinked path resolved outside the root — so name the
        // gap here rather than letting it surface as "scanner omitted".
        for (const relative of requested) {
            const key = normalize(relative);
            if (emitted.has(key)) continue;
            // A file the host was asked for and could not read was recorded
            // there. One the compiler never asked for is recorded here only if
            // it is no longer readable as itself: a stable file the compiler
            // simply leaves out (as it did `FOO.TS` before offeredPath) must
            // not fail every scan.
            if (!readableAsItself(root, key, maxFileBytes)) {
                reads.record(key, null);
            }
            emit(
                unscannableContribution(
                    key,
                    "File was not included in any TypeScript program (it may have changed size or moved since discovery).",
                ),
            );
            emitted.add(key);
        }

        return {
            result: {
                files_scanned: emitted.size + rejected.length,
                programs,
                programs_reused: programsReused,
                input_hashes: reads.toResult(),
            },
            attribution,
        };
    }

    /**
     * Build each config's program the request needs (see needsProgram) and
     * emit the requested files it covers, in config order.
     *
     * A program emits a requested file another config includes only from
     * that config's own program, so a program that includes none of the
     * requested files, while each of the files still to emit has a config of
     * its own, would emit nothing and only read files for nobody.
     *
     * @param {Array<[string, object]>} parsedConfigs config path and its parsed config
     * @param {string[]} requested the accepted requested files
     */
    #scanConfigPrograms(parsedConfigs, requested, request, tally) {
        const { root } = request;
        for (const [configPath, parsed] of parsedConfigs) {
            if (!needsProgram(request, requested, configPath, parsed)) continue;
            request.owner = configPath;
            request.program = configPath;
            tally(
                this.#scanProgram(
                    `${root}\0${configPath}`,
                    programConfig(
                        request,
                        path.dirname(path.join(root, configPath)),
                        parsed,
                    ),
                    request,
                ),
            );
        }
    }

    /**
     * Whatever no config's program emitted, owned or not, read under the
     * options of the config beside it: a package's tests are often outside
     * its tsconfig's `include`, and its test runner still resolves them
     * through that package's aliases and paths.
     *
     * The program's root files are every discovered file of the group that
     * no config lists, not only the files this request named: a test sees
     * the globals its setup file declares and the augmentations it imports
     * whether or not the setup was requested with it, and the program's
     * environment then does not follow the request. A declaration file no
     * config lists is rooted with its group the same way, so an importer
     * requested on its own still has an ambient `declare module` satisfied
     * from inside its program.
     *
     * A fallback program emits only the files of its own group. It can
     * reach a file of another group through an import, but that file is
     * emitted by its own group's program, which holds its whole group under
     * the options of the config beside it; otherwise which program emitted
     * it would follow the order the groups are built in and the imports of
     * files no read of its own records.
     */
    #scanFallback(root, remaining, parsedConfigs, request, tally) {
        request.owner = undefined;
        const members = request.fallbackMembers;
        const groups = fallbackGroups(
            root,
            remaining,
            parsedConfigs,
            request.packageDirectories,
        );
        request.fallbackGroupOf = new Map();
        for (const source of [groups, members]) {
            for (const [directory, group] of source) {
                for (const relative of group.files) {
                    request.fallbackGroupOf.set(normalize(relative), directory);
                }
            }
        }
        for (const [directory, group] of groups) {
            // Sorted, so every batch of a request hands the compiler the same
            // root list and the program built for the first is reused by the
            // rest instead of being rebuilt for each.
            const files = [
                ...new Set([
                    ...group.files,
                    ...(members.get(directory)?.files ?? []),
                ]),
            ].sort((left, right) => (left < right ? -1 : left > right ? 1 : 0));
            request.program = fallbackProgramKey(root, directory);
            request.fallbackDirectory = directory;
            tally(
                this.#scanProgram(
                    `${directory}${FALLBACK_KEY}`,
                    programConfig(
                        request,
                        directory,
                        fallbackConfig(root, files, group.parsed, directory),
                    ),
                    request,
                ),
            );
        }
    }

    /**
     * Build one program and emit the requested files it covers.
     *
     * The compiler recurses once per import while it builds a program, so an
     * import chain deeper than the thread's stack throws a RangeError out of
     * `createProgram` (or out of the checker walking the same chain). That
     * costs the files of this one program, not the whole request: each gets a
     * facts-free contribution saying why, and the remaining configs and the
     * fallback still run. Whatever reads were recorded before the overflow stay
     * recorded. Any other error out of the build or the checker is contained
     * the same way, under TS_PROGRAM_FAILED, so one program the compiler cannot
     * handle never discards the facts of the others.
     *
     * @returns {{reused: boolean}|undefined} undefined when the program failed
     */
    #scanProgram(key, parsed, request) {
        const { root, maxFileBytes, reads, emitted, emit } = request;
        this.#reserveProgramSlot(key);
        this.#collectReleasedPrograms();
        const oldProgram = this.programCache.get(key);
        let program;
        try {
            program = createRestrictedProgram(
                root,
                parsed,
                oldProgram,
                maxFileBytes,
                reads,
            );
            this.#cacheProgram(key, program);
            recordUnreadSourceFiles(root, program, reads, maxFileBytes);
            this.#emitProgram(program, request, key.endsWith(FALLBACK_KEY));
            request.attribution.program(program, request.program, (relative) =>
                request.ownedByProgram(relative, request.program),
            );
        } catch (error) {
            const overflowed = isStackOverflow(error);
            const covered = [
                ...parsed.fileNames,
                ...(program?.getSourceFiles() ?? []).map(
                    (file) => file.fileName,
                ),
            ];
            // The same files the program would have emitted: one another
            // config lists, or one of another fallback group, is answered
            // by its own program, which this failure says nothing about.
            const fallback = key.endsWith(FALLBACK_KEY);
            const skipped = emissionSkipped(
                request,
                fallback ? new Map() : request.owners,
                fallback,
            );
            for (const fileName of covered) {
                const relative = relativeInside(root, fileName);
                if (skipped(relative)) continue;
                emit(
                    overflowed
                        ? factFreeContribution(
                              relative,
                              "TS_PROGRAM_TOO_DEEP",
                              "The TypeScript compiler exceeded its stack building the program for this file's configuration (for example, an import chain too deep to follow), so its facts are omitted.",
                          )
                        : factFreeContribution(
                              relative,
                              "TS_PROGRAM_FAILED",
                              `The TypeScript compiler failed building or checking the program for this file's configuration, so its facts are omitted: ${error instanceof Error ? error.message : String(error)}`,
                          ),
                );
                emitted.add(relative);
            }
            return undefined;
        }
        return { reused: oldProgram !== undefined };
    }

    // Free a slot before the next program is built. A program is constructed
    // while the cache still holds its entries, so inserting first and evicting
    // after leaves MAX_CACHED_PROGRAMS + 1 programs resident at the peak — the
    // overshoot the cap exists to prevent, and enough to OOM the worker's
    // 512 MB heap on a project with three tsconfigs over the same sources.
    //
    // The entry for `key` is never evicted: that program is handed back to
    // createRestrictedProgram for incremental reuse, so dropping it would
    // trade memory for a full rebuild of the very config being scanned.
    #reserveProgramSlot(key) {
        const limit = Math.max(0, MAX_CACHED_PROGRAMS - 1);
        for (const oldest of [...this.programCache.keys()]) {
            if (this.programCache.size <= limit) break;
            if (oldest === key) continue;
            this.programCache.delete(oldest);
            this.releasedSinceCollection = true;
        }
    }

    // A released program is only garbage until V8 collects it, and under the
    // worker's 2 GB heap cap V8 has no reason to: on a project with nine
    // programs per request the worker grew to 1.7 GB resident with under
    // 0.5 GB live, and a host memory guard (earlyoom) SIGTERMed it as the
    // largest process on the machine. Collecting before the next build keeps
    // the process near its live set, about two programs: on that project the
    // peak fell to 1.2 GB with no measurable change in scan time. Only when
    // something was released, so a request within the cache's bound pays
    // nothing.
    #collectReleasedPrograms() {
        if (!this.releasedSinceCollection) return;
        this.releasedSinceCollection = false;
        this.collectGarbage();
    }

    /**
     * Insert a program as most-recently-used, or release the cache entirely
     * once this request has outgrown it.
     *
     * The cache can only ever hit when a request builds no more programs than
     * it holds. Above that it is evicted down to the last programs built,
     * while the next request starts again at the first config, so it never
     * hits: measured on a project with six programs per request, reuse was
     * zero across six consecutive scans of an unchanged tree. Each retained
     * program carries its own default library and type checker, so that was
     * ~100-120MB apiece held back from a heap whose peak was already 1.35GB,
     * bought nothing, and made the scan likelier to die of heap exhaustion.
     *
     * So a request that stays within the cap keeps its programs, and one that
     * outgrows it drops them rather than paying to hold what it cannot use.
     */
    #cacheProgram(key, program) {
        this.programsBuiltThisRequest += 1;
        if (this.programsBuiltThisRequest > MAX_CACHED_PROGRAMS) {
            this.programCache.clear();
            this.releasedSinceCollection = true;
            return;
        }
        this.programCache.delete(key);
        this.programCache.set(key, program);
        while (this.programCache.size > MAX_CACHED_PROGRAMS) {
            const oldest = this.programCache.keys().next().value;
            this.programCache.delete(oldest);
            this.releasedSinceCollection = true;
        }
    }

    #emitProgram(program, request, fallback) {
        const { root, emitted, emit, maxFileBytes, owner } = request;
        // The fallback program emits whatever no config's program did, a
        // file some config lists included, so it is given no owners to defer
        // to; what it leaves to another program is a file of another group.
        // The request's own map is left as the configs filled it.
        const owners = fallback ? new Map() : request.owners;
        const tracker = declarationTracker(program.getTypeChecker());
        const skipped = emissionSkipped(request, owners, fallback);
        const { byFile: diagnosticsByFile, programLevel } = programDiagnostics(
            program,
            root,
            maxFileBytes,
            fallback,
        );

        // A diagnostic that names no file describes the whole program, so it
        // is reported once, on one fixed file of the program. The carrier is
        // chosen from the program itself, never from the request: a project is
        // sent in batches and an incremental scan sends only changed files, so
        // a carrier taken from each request repeats the diagnostic once per
        // batch and keeps every copy in the graph. Only a request that names
        // the carrier reports it.
        const carrier = programWideCarrier(program, root, owner, owners);
        const programWide = (relative) =>
            relative === carrier ? anchoredAt(programLevel, relative) : [];
        for (const sourceFile of program.getSourceFiles()) {
            const relative = relativeInside(root, sourceFile.fileName);
            if (skipped(relative)) continue;

            const redirect = sourceFile.redirectInfo;
            if (redirect !== undefined && !redirectReadsAgree(redirect)) {
                emit(
                    factFreeContribution(
                        relative,
                        "TS_REDIRECTED_SOURCE_UNVERIFIED",
                        "TypeScript resolved this file as a duplicate of another copy of the same package whose bytes differ, so its facts would describe the other copy.",
                    ),
                );
                emitted.add(relative);
                continue;
            }

            const probes = [];
            tracker.files = new Set();
            const contribution = collectFile(
                root,
                program,
                sourceFile,
                { tracker, probes },
                {
                    own: diagnosticsByFile.get(relative) ?? [],
                    programWide: programWide(relative),
                },
            );
            // Every SourceFile the restricted host creates has an entry. One
            // without would reach the core with facts but no hash, which it
            // refuses as a contract violation instead of trusting the read.
            // A redirect is a view of its target; with the reads agreeing, its
            // own bytes are what the facts describe.
            const contentHash = parsedContentHashes.get(
                redirect?.unredirected ?? sourceFile,
            );
            if (contentHash !== undefined) {
                contribution.content_hash = contentHash;
            }
            contribution.program = request.program;
            // A file no config lists is emitted by whichever program reaches
            // it first, so its facts follow other files' imports, which no
            // read of its own records; the core rebuilds it whenever a
            // program a config describes was rebuilt.
            if (!owners.has(relative)) contribution.listed = false;
            request.attribution.requested(
                program,
                sourceFile,
                relative,
                tracker.files,
                probes,
                request.program,
            );
            emit(contribution);
            emitted.add(relative);
        }
    }
}

/**
 * One requested file's contribution, without its hash or reads.
 *
 * Collection is isolated per file: a single adversarial or minified file can
 * overflow the visitor recursion (RangeError), and one bad file must degrade
 * to a diagnostic, not discard the facts of every other file in the request.
 *
 * @param {{tracker: {checker: ts.TypeChecker}, probes: string[]}} reads the
 *   checker that notes declaration files, and where the paths the file's facts
 *   asked about beyond its imports are listed
 * @param {{own: object[], programWide: object[]}} diagnostics the compiler's
 *   diagnostics on this file, and the program-wide ones it carries
 */
function collectFile(
    root,
    program,
    sourceFile,
    { tracker, probes },
    diagnostics,
) {
    const relative = relativeInside(root, sourceFile.fileName);
    try {
        const collector = new FactCollector(root, sourceFile, tracker.checker, {
            options: program.getCompilerOptions(),
            sourceFileAt: (fileName) => {
                probes.push(fileName);
                return program.getSourceFile(fileName);
            },
            fileExists: (fileName) => {
                probes.push(fileName);
                return inRootFile(root, fileName);
            },
            // Whether the compiler resolved an import's specifier to any
            // file, a dependency's included. An internal compiler method.
            resolved: (specifier) =>
                program.getResolvedModuleFromModuleSpecifier(
                    specifier,
                    sourceFile,
                )?.resolvedModule !== undefined,
        });
        collector.collect();
        return {
            owner_key: `${OWNER_KEY_PREFIX}${relative}`,
            nodes: collector.nodes,
            edges: collector.edges,
            diagnostics: [...diagnostics.own, ...diagnostics.programWide],
        };
    } catch (error) {
        return {
            owner_key: `${OWNER_KEY_PREFIX}${relative}`,
            nodes: [],
            edges: [],
            diagnostics: [
                {
                    severity: "error",
                    code: "TS_INTERNAL_ERROR",
                    message:
                        error instanceof Error ? error.message : String(error),
                    evidence: { path: relative, start_line: 1, end_line: 1 },
                },
                ...diagnostics.programWide,
            ],
        };
    }
}

class FactCollector {
    constructor(root, sourceFile, checker, project = {}) {
        this.language = new TypeScriptLanguageFactCollector(
            root,
            sourceFile,
            checker,
            project,
        );
    }

    get nodes() {
        return this.language.nodes;
    }

    get edges() {
        return this.language.edges;
    }

    collect() {
        this.language.initialize();
        this.visit(this.language.sourceFile);
        this.language.finish();
    }

    visit(node) {
        const pushed = this.language.enter(node);
        this.language.handle(node);
        ts.forEachChild(node, (child) => this.visit(child));
        this.language.leave(pushed);
    }
}

/** How a fallback program's cache key ends: no tsconfig includes its files. */
const FALLBACK_KEY = "\0<fallback>";

/**
 * Whether a redirect SourceFile's facts provably come from its own path's bytes.
 *
 * TypeScript loads one copy of a package name@version and makes every further
 * copy a redirect to it, so a duplicate path's facts are computed from the
 * first copy. That is only a fact about the duplicate when the host read both
 * and the two reads hashed the same. Attaching the target's hash instead would
 * fail verification on every scan of a project whose copies differ.
 */
function redirectReadsAgree(redirect) {
    const own =
        redirect.unredirected === undefined
            ? undefined
            : parsedContentHashes.get(redirect.unredirected);
    const target = parsedContentHashes.get(redirect.redirectTarget);
    return own !== undefined && own === target;
}

// A contribution that carries nothing but the reason one file was skipped.
function unscannableContribution(relative, message) {
    return factFreeContribution(relative, "TS_UNSCANNABLE_FILE", message);
}

// A contribution with no facts, only a diagnostic saying why.
function factFreeContribution(relative, code, message) {
    return {
        owner_key: `${OWNER_KEY_PREFIX}${relative}`,
        nodes: [],
        edges: [],
        diagnostics: [
            {
                severity: "error",
                code,
                message,
                evidence: { path: relative, start_line: 1, end_line: 1 },
            },
        ],
    };
}

/**
 * Which config describes each file: the first whose own file list includes it.
 *
 * A program reaches far more than that (a solution config's references, an
 * import across a package boundary), and a file emitted by whichever program
 * reached it first was checked under options its project never uses.
 */
function configOwners(root, parsedConfigs) {
    const owners = new Map();
    for (const [configPath, parsed] of parsedConfigs) {
        for (const fileName of parsed.ownFileNames ?? parsed.fileNames) {
            const relative = relativeInside(root, fileName);
            if (relative !== null && !owners.has(relative))
                owners.set(relative, configPath);
        }
    }
    return owners;
}

/**
 * Files no config's program emitted, grouped by the package they sit in: the
 * nearest config's directory, or the nearest package.json's where that is
 * nearer (a nested package with no tsconfig of its own), else the root. A
 * group takes a config's options only from a config at or below its package;
 * a nested package is not governed by a parent's tsconfig.
 *
 * @returns {Map<string, {files: string[], parsed: object | undefined}>} directory => group
 */
function fallbackGroups(root, remaining, parsedConfigs, packageDirectories) {
    const configs = parsedConfigs.map(([configPath, parsed]) => ({
        directory: path.dirname(path.join(root, configPath)),
        parsed,
    }));
    const packages = packageDirectories.map((directory) =>
        path.join(root, directory),
    );
    const nearest = (directories, absolute) =>
        directories
            .filter((directory) => absolute.startsWith(directory + path.sep))
            .sort((a, b) => b.length - a.length)[0];
    const groups = new Map();
    for (const relative of remaining) {
        const absolute = path.join(root, relative);
        const configDirectory = nearest(
            configs.map(({ directory }) => directory),
            absolute,
        );
        const packageDirectory = nearest(packages, absolute);
        const governed =
            configDirectory !== undefined &&
            (packageDirectory === undefined ||
                configDirectory.length >= packageDirectory.length);
        const directory = governed
            ? configDirectory
            : (packageDirectory ?? root);
        const group = groups.get(directory) ?? {
            files: [],
            parsed: governed
                ? configs.find((config) => config.directory === directory)
                      ?.parsed
                : undefined,
        };
        group.files.push(relative);
        groups.set(directory, group);
    }
    return groups;
}

// What a file outside a config's `include` takes from it: how to resolve
// and parse, as its test runner or bundler reads it. Not how strictly to
// check, which the config applies to its own files only, and not its build
// layout (`rootDir`, `composite`), which such a file would break. Nor its
// `module`/`moduleResolution`: a test runner resolves as a bundler does,
// and a legacy `node` resolution loses the package `imports` field and, under
// the bundled compiler, the `@types` a test reads (`Buffer`, `process`). Nor
// the environment (`lib`, `target`, `types`): a test runs in its runner's,
// not in the one the config describes for its own files.
const RESOLUTION_OPTIONS = [
    "baseUrl",
    // A deprecated option inherited above is reported again for the fallback
    // unless the config's own remedy for that deprecation comes with it.
    "ignoreDeprecations",
    "paths",
    // Where `paths` resolve from when no `baseUrl` is set: the config's own
    // directory, which TypeScript records here and nowhere else.
    "pathsBasePath",
    "moduleSuffixes",
    "customConditions",
    "resolvePackageJsonExports",
    "resolvePackageJsonImports",
    "resolveJsonModule",
    "allowImportingTsExtensions",
    "allowArbitraryExtensions",
    "esModuleInterop",
    "allowSyntheticDefaultImports",
    "jsx",
    "jsxFactory",
    "jsxFragmentFactory",
    "jsxImportSource",
    "experimentalDecorators",
    "emitDecoratorMetadata",
    "useDefineForClassFields",
];

const FALLBACK_OPTIONS = {
    allowJs: true,
    checkJs: false,
    noEmit: true,
    target: ts.ScriptTarget.Latest,
    module: ts.ModuleKind.ESNext,
    moduleResolution: ts.ModuleResolutionKind.Bundler,
    jsx: ts.JsxEmit.Preserve,
};

/**
 * The options and root files of a fallback program: the files no config's
 * program emitted, under the resolution options of the config beside them.
 */
function fallbackConfig(root, remaining, config, directory = root) {
    const configOptions = config?.options;
    const inherited = {};
    for (const option of RESOLUTION_OPTIONS)
        if (configOptions?.[option] !== undefined)
            inherited[option] = configOptions[option];
    return {
        options: {
            ...FALLBACK_OPTIONS,
            ...inherited,
            // Where installed types are looked up from: the group's own
            // package, not the directory the worker runs in.
            configFilePath: path.join(directory, "tsconfig.json"),
        },
        // An extensionless script, or one whose extension is not in lower
        // case, only ever reaches the fallback program: no tsconfig `include`
        // matches either name.
        fileNames: remaining.map((relative) =>
            offeredPath(path.join(root, relative)),
        ),
        // A referenced project's outputs stand for its sources here as in
        // the config's own program (`#shared/*` naming its `dist`).
        projectReferences: config?.projectReferences,
    };
}

function configFilesForScan(root, requested) {
    if (requested !== undefined) {
        if (
            !Array.isArray(requested) ||
            requested.some((item) => typeof item !== "string")
        ) {
            throw new Error(
                "TypeScript config_files must be a list of project-relative paths.",
            );
        }
        return requested.map((item) =>
            normalize(path.relative(root, validatedInside(root, item))),
        );
    }
    return discoverConfigFiles(root);
}

/**
 * Return sorted project-relative tsconfig paths below a validated root.
 *
 * Walking is the fallback, not the norm: the core names `config_files` on every
 * scan it plans, so this runs only for a request that supplied none.
 *
 * @param {string} root Absolute, already-validated project root.
 * @returns {string[]} Project-relative tsconfig paths, sorted.
 */
export function discoverConfigFiles(root) {
    const configs = [];
    walk(root, root, (absolute, relative) => {
        const basename = path.basename(relative).toLowerCase();
        if (
            basename === "tsconfig.json" ||
            (basename.startsWith("tsconfig.") && basename.endsWith(".json"))
        ) {
            configs.push(relative);
        }
    });

    return configs.sort();
}
