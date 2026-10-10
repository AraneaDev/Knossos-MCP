import path from "node:path";
import ts from "typescript";
import { exceedsByteCap } from "./byte-caps.js";
import { componentAliasSuffix, componentDialect } from "./component-source.js";
import {
    ambientAttributes,
    boundFunction,
    callableKind,
    canonicalForDeclaration,
    containerDeclaration,
    declarationDescriptor,
    declarationKind,
    evidence,
    externalPackageName,
    importIsTypeOnly,
    isContextualObjectLiteral,
    isDeclaration,
    isNamePosition,
    isObjectLiteralBinding,
    isTypeOnlyDeclaration,
    memberDeclaration,
    objectLiteralContract,
    referenceableDeclaration,
    unalias,
    valueReferencePosition,
} from "./declaration-kinds.js";
import { isStackOverflow, rethrowStackOverflow } from "./errors.js";
import {
    BUILT_IN_EXCLUSIONS,
    exclusionRules,
    setActiveExclusions,
} from "./exclusions.js";
import { FactAccumulator } from "./fact-accumulator.js";
import {
    InputReadRecorder,
    inRootFile,
    probeRecorded,
    ReadAttribution,
    recordProbe,
    recordRefused,
    recordUnreadSourceFiles,
} from "./input-reads.js";
import {
    hasMainGuard,
    importMetaUrlModule,
    isDirnameExpression,
    sourcePathTarget,
    unwrapParentheses,
} from "./module-entry.js";
import { NestJsFactEnricher } from "./nestjs-fact-enricher.js";
import {
    anchoredAt,
    programDiagnostics,
    programWideCarrier,
} from "./program-diagnostics.js";
import {
    allowedCompilerPath,
    belowNodeModules,
    COMPONENT_ALIAS,
    COMPONENT_ALIAS_MARK,
    contains,
    defaultLibDirectory,
    excludedByProjectLayoutPath,
    isRegularFile,
    normalize,
    offeredComponentPath,
    offeredPath,
    realpathNative,
    realSourcePath,
    relativeInside,
    SHEBANG_ALIAS_SUFFIX,
    sourceFilesFrom,
    validatedInside,
    validateRoot,
    walkPath,
} from "./project-paths.js";
import {
    configFilesForScan,
    maxFileBytesFrom,
    readableAsItself,
    startsWithShebang,
    validateRequestedFiles,
} from "./request-validation.js";
import { componentSources, parsedContentHashes } from "./source-caches.js";
import { readHashedSourceFile, readRecorded } from "./source-reading.js";
import {
    globBase,
    globContext,
    isClassicScript,
    isImportMetaGlob,
    isK6Script,
    isRequireContext,
    memberName,
    objectField,
    regularExpressionOf,
    relativeGlob,
    REQUIRE_SUFFIXES,
    runsOnLoad,
    staticPropertyName,
    storeMemberNames,
    VUE_HOOKS,
    vueOptionsObject,
} from "./syntax-predicates.js";
import { TypeScriptApplicationEnricher } from "./typescript-application-enricher.js";
import {
    callName,
    declarationModifiers,
    functionBindingOf,
    isFunctionBinding,
    reference,
    unwrapExpression,
} from "./typescript-fact-utils.js";
export { excludedBy } from "./exclusions.js";
export { discoverConfigFiles } from "./request-validation.js";

// Every contribution's owner key is this prefix and the file's project path.
const OWNER_KEY_PREFIX = "knossos.typescript:file:";
// Lets a tsconfig `include` match components, so they are checked under the
// options of the project that holds them.
const COMPONENT_FILE_EXTENSIONS = [".vue", ".svelte", ".astro"].map(
    (extension) => ({
        extension,
        isMixedContent: false,
        scriptKind: ts.ScriptKind.Deferred,
    }),
);

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

class TypeScriptLanguageFactCollector {
    constructor(root, sourceFile, checker, project = {}) {
        this.root = root;
        // The program's compiler options.
        this.project = project;
        this.sourceFile = sourceFile;
        this.checker = checker;
        this.relative = relativeInside(root, sourceFile.fileName);
        this.container = [];
        this.moduleId = reference("module", this.relative);
        const ownEnd = componentSources.get(sourceFile)?.sourceLength;
        this.accumulator = new FactAccumulator(
            sourceFile,
            this.relative,
            evidence,
            ownEnd === undefined
                ? {}
                : { end: ownEnd, moduleId: this.moduleId },
        );
        this.application = new TypeScriptApplicationEnricher(this);
        this.nest = new NestJsFactEnricher(this);
        this.untypedCalls = new Set();
        // Each declaration's node id, by the syntax node it was read from.
        this.declaredIds = new Map();
    }

    get nodes() {
        return this.accumulator.nodes;
    }
    get edges() {
        return this.accumulator.edges;
    }

    initialize() {
        this.addNode(
            this.moduleId,
            "module",
            this.relative,
            path.basename(this.relative),
            this.sourceFile,
            {
                declaration_file: this.sourceFile.isDeclarationFile,
                executable:
                    startsWithShebang(this.sourceFile.text) ||
                    hasMainGuard(this.sourceFile, this.checker) ||
                    isClassicScript(this.sourceFile) ||
                    runsOnLoad(this.sourceFile),
            },
        );
    }

    /**
     * Records on the module node the member names called on a receiver the
     * checker could not type (`any`, an untyped parameter in JavaScript).
     * Such a call has no edge, and a method by one of these names may be what
     * it reaches, so the core reports that method as only possibly dead.
     */
    finish() {
        const component = componentSources.get(this.sourceFile);
        if (component !== undefined)
            this.unresolvedTemplateNames(component.templateRanges);
        if (component?.dialect === "astro") this.astroProps();
        if (component?.dialect === "vue")
            this.vueOptions(component.templateRanges);
        if (isK6Script(this.sourceFile)) this.k6Script();
        if (this.untypedCalls.size === 0) return;
        this.accumulator.nodesById.get(
            this.moduleId,
        ).attributes.unresolved_member_calls = [...this.untypedCalls].sort();
    }

    /**
     * Names a component's template uses that resolve to nothing, such as an
     * Options API method reached through the component instance. They join
     * the member names called on untyped receivers, so a method by one of
     * them is reported as only possibly dead.
     */
    unresolvedTemplateNames(ranges) {
        const inTemplate = (node) => {
            const start = node.getStart(this.sourceFile);
            return ranges.some(([from, to]) => start >= from && node.end <= to);
        };
        const visit = (node) => {
            if (
                ts.isIdentifier(node) &&
                inTemplate(node) &&
                !isNamePosition(node) &&
                this.checker.getSymbolAtLocation(node) === undefined
            )
                this.untypedCalls.add(node.text);
            ts.forEachChild(node, visit);
        };
        visit(this.sourceFile);
    }

    /**
     * Astro types a component's `Astro.props` from the `Props` its frontmatter
     * declares, by that name, so the component references it even when no
     * line of its source does.
     */
    astroProps() {
        const props = this.sourceFile.statements.find(
            (statement) =>
                (ts.isInterfaceDeclaration(statement) ||
                    ts.isTypeAliasDeclaration(statement)) &&
                statement.name.text === "Props",
        );
        if (props === undefined) return;
        const kind = ts.isInterfaceDeclaration(props)
            ? "interface"
            : "type_alias";
        this.addEdge(
            "references",
            this.moduleId,
            reference(kind, `${this.relative}#Props`),
            props,
        );
    }

    /**
     * A Vue Options API component: Vue calls its lifecycle hooks and watchers
     * itself, and its methods and computed properties are reached by name,
     * through `this` or from the template, which no static edge records. A
     * method or computed property this component names gets a reference from
     * its module; one it never names stays reportable.
     */
    vueOptions(templateRanges) {
        const options = vueOptionsObject(this.sourceFile);
        if (options === undefined) return;
        const used = this.vueUsedNames(templateRanges);
        const groups = new Map();
        for (const property of options.properties) {
            const name = memberName(property);
            if (VUE_HOOKS.has(name)) this.markRuntimeInvoked(property);
            if (
                ts.isPropertyAssignment(property) &&
                ts.isObjectLiteralExpression(property.initializer)
            )
                groups.set(name, property.initializer.properties);
        }
        // `props: { items: { default() {}, validator(v) {} } }`.
        for (const prop of groups.get("props") ?? []) {
            if (
                !ts.isPropertyAssignment(prop) ||
                !ts.isObjectLiteralExpression(prop.initializer)
            )
                continue;
            for (const factory of prop.initializer.properties) {
                if (["default", "validator"].includes(memberName(factory)))
                    this.markRuntimeInvoked(factory);
            }
        }
        for (const watcher of groups.get("watch") ?? [])
            this.vueWatcher(watcher, used);
        for (const member of [
            ...(groups.get("methods") ?? []),
            ...(groups.get("computed") ?? []),
        ]) {
            const id = this.declaredIds.get(member);
            if (id !== undefined && used.has(memberName(member)))
                this.addEdge("references", this.moduleId, id, member);
        }
    }

    /**
     * A Vue watcher, which Vue calls: `n(value) {}`, `total: 'recount'` (a
     * method named by string), or `deep: { handler() {} }` /
     * `deep: { handler: 'recount' }`.
     */
    vueWatcher(watcher, used) {
        this.markRuntimeInvoked(watcher);
        if (!ts.isPropertyAssignment(watcher)) return;
        const value = watcher.initializer;
        if (ts.isStringLiteralLike(value)) used.add(value.text);
        if (!ts.isObjectLiteralExpression(value)) return;
        for (const option of value.properties) {
            if (memberName(option) !== "handler") continue;
            this.markRuntimeInvoked(option);
            if (
                ts.isPropertyAssignment(option) &&
                ts.isStringLiteralLike(option.initializer)
            )
                used.add(option.initializer.text);
        }
    }

    /** Names a Vue component uses: `this.name` in its script, any name in its template. */
    vueUsedNames(templateRanges) {
        const used = new Set();
        const visit = (node) => {
            if (
                ts.isPropertyAccessExpression(node) &&
                node.expression.kind === ts.SyntaxKind.ThisKeyword
            )
                used.add(node.name.text);
            else if (
                ts.isIdentifier(node) &&
                templateRanges.some(
                    ([from, to]) =>
                        node.getStart(this.sourceFile) >= from &&
                        node.end <= to,
                )
            )
                used.add(node.text);
            ts.forEachChild(node, visit);
        };
        visit(this.sourceFile);
        return used;
    }

    /** Mark a declared member as called by its framework rather than by code. */
    /**
     * A k6 load-test script: `k6 run script.js` runs the module, and k6 calls
     * its default export, `setup`, `teardown` and `handleSummary`, and every
     * function a scenario names as its `exec`. Nothing imports any of them.
     */
    k6Script() {
        this.accumulator.nodesById.get(this.moduleId).attributes.executable =
            true;
        const invoked = new Set(["setup", "teardown", "handleSummary"]);
        for (const statement of this.sourceFile.statements) {
            if (!ts.isVariableStatement(statement)) continue;
            for (const declaration of statement.declarationList.declarations) {
                if (
                    !ts.isIdentifier(declaration.name) ||
                    declaration.name.text !== "options"
                )
                    continue;
                const scenarios = objectField(
                    unwrapExpression(declaration.initializer),
                    "scenarios",
                );
                for (const scenario of scenarios?.properties ?? []) {
                    const exec = ts.isPropertyAssignment(scenario)
                        ? objectField(scenario.initializer, "exec")
                        : undefined;
                    if (exec !== undefined && ts.isStringLiteral(exec))
                        invoked.add(exec.text);
                }
            }
        }
        for (const statement of this.sourceFile.statements) {
            const modifiers = declarationModifiers(statement);
            const isDefault = modifiers.some(
                (modifier) => modifier.kind === ts.SyntaxKind.DefaultKeyword,
            );
            if (ts.isFunctionDeclaration(statement)) {
                if (
                    isDefault ||
                    (statement.name !== undefined &&
                        invoked.has(statement.name.text))
                )
                    this.markRuntimeInvoked(statement);
            } else if (ts.isVariableStatement(statement)) {
                for (const declaration of statement.declarationList
                    .declarations)
                    if (
                        isFunctionBinding(declaration) &&
                        invoked.has(declaration.name.text)
                    )
                        this.markRuntimeInvoked(declaration);
            } else if (
                ts.isExportAssignment(statement) &&
                ts.isIdentifier(statement.expression)
            ) {
                // `export default run`: the declaration it names.
                const symbol = this.checker.getSymbolAtLocation(
                    statement.expression,
                );
                for (const declaration of symbol?.declarations ?? [])
                    this.markRuntimeInvoked(declaration);
            }
        }
    }

    /**
     * Whether a method fulfils a member of a type its class extends or
     * implements, or of the type its object literal is handed to: the source
     * says so with `override`, or the checker finds a member of that name on
     * a heritage or contextual type. The type may be a dependency's
     * or a built-in one (`Iterator`), whose members are not in the graph, so
     * the dispatch through it leaves no edge to this method.
     */
    overridesSupertypeMember(node) {
        if (!ts.isMethodDeclaration(node)) return false;
        // An object literal's method fulfils the member of the type the
        // literal is handed to (`registerHooks({ resolve() {} })`), which
        // calls it unseen.
        if (ts.isObjectLiteralExpression(node.parent)) {
            const name = this.checker.getSymbolAtLocation(
                node.name,
            )?.escapedName;
            const contract = this.checker.getContextualType(node.parent);
            const member =
                name === undefined
                    ? undefined
                    : contract?.getProperty(
                          ts.unescapeLeadingUnderscores(name),
                      );
            // A contextual type inferred from the literal itself
            // (`define<T>(options: T)`) holds this very method: that names
            // no contract, so only a member declared elsewhere counts.
            return (member?.declarations ?? []).some(
                (declaration) => declaration !== node,
            );
        }
        if (
            !ts.isClassDeclaration(node.parent) &&
            !ts.isClassExpression(node.parent)
        )
            return false;
        const flags = ts.getCombinedModifierFlags(node);
        if (flags & ts.ModifierFlags.Override) return true;
        // A heritage type describes instances; a static method sharing an
        // instance member's name fulfils nothing of it.
        if (flags & ts.ModifierFlags.Static) return false;
        const name = this.checker.getSymbolAtLocation(node.name)?.escapedName;
        if (name === undefined) return false;
        return (node.parent.heritageClauses ?? []).some((clause) =>
            clause.types.some(
                (type) =>
                    this.checker
                        .getTypeAtLocation(type)
                        .getProperty(ts.unescapeLeadingUnderscores(name)) !==
                    undefined,
            ),
        );
    }

    markRuntimeInvoked(member) {
        const id = this.declaredIds.get(member);
        const fact =
            id === undefined ? undefined : this.accumulator.nodesById.get(id);
        if (fact !== undefined)
            fact.attributes = { ...fact.attributes, runtime_invoked: true };
    }

    enter(node) {
        return isDeclaration(node) ? this.declaration(node) : false;
    }

    handle(node) {
        if (ts.isImportDeclaration(node)) this.importDeclaration(node);
        if (ts.isExportDeclaration(node)) this.exportDeclaration(node);
        if (ts.isImportEqualsDeclaration(node)) this.importEquals(node);
        if (ts.isPropertyAssignment(node)) this.entryPointsProperty(node);
        if (ts.isVariableDeclaration(node)) {
            this.application.variable(node);
            this.dynamicImportBindings(node);
        }
        if (ts.isNewExpression(node)) this.newExpression(node);
        if (ts.isCallExpression(node)) this.callExpression(node);
        if (ts.isTypeReferenceNode(node)) this.typeReference(node);
        if (ts.isIdentifier(node)) this.valueReference(node);
        if (ts.isBindingElement(node) && ts.isObjectBindingPattern(node.parent))
            this.destructuredMember(node);
    }

    leave(pushed) {
        if (pushed) this.container.pop();
    }

    /** `extends` / `implements` edges and constructor `injects` edges of a class or interface. */
    heritageEdges(node, id) {
        if (
            !ts.isClassDeclaration(node) &&
            !ts.isClassExpression(node) &&
            !ts.isInterfaceDeclaration(node)
        )
            return;
        for (const clause of node.heritageClauses ?? []) {
            for (const type of clause.types) {
                const target = this.symbolReference(
                    this.checker.getSymbolAtLocation(type.expression),
                    "class",
                );
                if (target !== null) {
                    this.addEdge(
                        clause.token === ts.SyntaxKind.ImplementsKeyword
                            ? "implements"
                            : "extends",
                        id,
                        target,
                        type,
                    );
                }
            }
        }
        const constructor = node.members?.find((member) =>
            ts.isConstructorDeclaration(member),
        );
        for (const parameter of constructor?.parameters ?? []) {
            if (parameter.type) {
                const target = this.typeNodeReference(parameter.type);
                if (target !== null)
                    this.addEdge("injects", id, target, parameter);
            }
        }
    }

    /** The contract an object literal implements, from its context or its binding's annotation. */
    objectLiteralContracts(node, id) {
        if (isContextualObjectLiteral(node)) {
            const contextual = this.checker.getContextualType(node);
            const parts = contextual?.isUnion()
                ? contextual.types
                : contextual
                  ? [contextual]
                  : [];
            for (const part of parts) {
                const symbol = part.aliasSymbol ?? part.symbol;
                if (!symbol || symbol.getName().startsWith("__")) continue;
                const target = this.symbolReference(symbol, "class");
                if (target !== null && target !== id)
                    this.addEdge("implements", id, target, node);
            }
        }

        if (isObjectLiteralBinding(node)) {
            const contract = objectLiteralContract(node);
            const target =
                contract !== undefined && ts.isTypeReferenceNode(contract)
                    ? this.typeNodeReference(contract)
                    : null;
            if (target !== null && target !== id)
                this.addEdge("implements", id, target, contract);
        }
    }

    /** A `returns` edge per named type a function or method declares it returns. */
    returnEdges(node, id) {
        // A function binding declares its return type on the arrow or
        // function expression; a type on the binding itself is a function
        // type, not a return type.
        const returnType = isFunctionBinding(node)
            ? unwrapExpression(node.initializer).type
            : ts.isFunctionDeclaration(node) ||
                ts.isMethodDeclaration(node) ||
                ts.isMethodSignature(node)
              ? node.type
              : undefined;
        if (returnType === undefined) return;
        // `Clipboard | null` has no symbol of its own: each named member
        // of a union is a type the function may return.
        const returned = ts.isUnionTypeNode(returnType)
            ? returnType.types.filter((member) =>
                  ts.isTypeReferenceNode(member),
              )
            : [returnType];
        for (const typeNode of returned) {
            const target = this.typeNodeReference(typeNode);
            if (target !== null) this.addEdge("returns", id, target, typeNode);
        }
    }

    declaration(node) {
        const descriptor = declarationDescriptor(node, this.sourceFile);
        if (descriptor === null) return false;
        const parent = this.container.at(-1) ?? {
            id: this.moduleId,
            canonical: this.relative,
        };
        const canonical = memberDeclaration(node)
            ? `${parent.canonical}::${descriptor.name}`
            : `${this.relative}#${this.container.length > 0 ? `${this.container.map((item) => item.name).join(".")}.` : ""}${descriptor.name}`;
        const id = reference(descriptor.kind, canonical);
        this.declaredIds.set(node, id);
        // False for a declaration a component's framework implies, which is
        // no node, so it takes no roles or attributes either.
        const kept = this.addNode(
            id,
            descriptor.kind,
            canonical,
            descriptor.name,
            node,
            // Carried down from the module node onto every declaration it
            // holds. A `.d.ts` describes code rather than being it, so its
            // symbols are not their own reachability question: the graph should
            // ask whether the `.mjs` behind `color-debt.d.mts` is used, not
            // whether anyone imports the declaration of it.
            this.sourceFile.isDeclarationFile
                ? { ...descriptor.attributes, declaration_file: true }
                : ambientAttributes(node, descriptor.attributes),
        );
        this.addEdge("contains", parent.id, id, node);
        if (kept && this.overridesSupertypeMember(node)) {
            const fact = this.accumulator.nodesById.get(id);
            fact.attributes = { ...fact.attributes, overrides: true };
        }
        const nest = kept
            ? this.nest.declaration(node, id, canonical)
            : { roles: [], controllerPrefix: null };
        const applicationRoles = kept
            ? this.application.declaration(node, id, canonical, descriptor.name)
            : [];
        if (applicationRoles.length > 0) {
            const fact = this.accumulator.nodesById.get(id);
            fact.attributes = {
                ...fact.attributes,
                typescript_framework_roles: applicationRoles,
            };
        }
        if (nest.roles.length > 0) {
            const fact = this.accumulator.nodesById.get(id);
            fact.attributes = { ...fact.attributes, nestjs_roles: nest.roles };
        }

        this.heritageEdges(node, id);
        this.objectLiteralContracts(node, id);
        this.returnEdges(node, id);

        if (containerDeclaration(node)) {
            this.container.push({
                id,
                canonical,
                name: descriptor.name,
                nestControllerPrefix: nest.controllerPrefix,
            });
            return true;
        }
        return false;
    }

    importDeclaration(node) {
        if (!ts.isStringLiteral(node.moduleSpecifier)) return;
        this.nest.importDeclaration(node);
        const target = this.moduleTarget(
            node.moduleSpecifier.text,
            node.moduleSpecifier,
        );
        if (target === null) return;
        this.addEdge("imports", this.moduleId, target, node, {
            type_only: importIsTypeOnly(node.importClause),
        });
    }

    exportDeclaration(node) {
        if (!node.moduleSpecifier || !ts.isStringLiteral(node.moduleSpecifier))
            return;
        const target = this.moduleTarget(
            node.moduleSpecifier.text,
            node.moduleSpecifier,
        );
        if (target !== null)
            this.addEdge("re_exports", this.moduleId, target, node, {
                type_only: node.isTypeOnly,
                // What the re-export passes on under the source's own names;
                // absent for `export *`, which passes on everything.
                ...(node.exportClause && ts.isNamedExports(node.exportClause)
                    ? {
                          names: node.exportClause.elements.map(
                              (element) =>
                                  (element.propertyName ?? element.name).text,
                          ),
                      }
                    : {}),
            });
    }

    importEquals(node) {
        if (
            ts.isExternalModuleReference(node.moduleReference) &&
            ts.isStringLiteral(node.moduleReference.expression)
        ) {
            const target = this.moduleTarget(
                node.moduleReference.expression.text,
                node.moduleReference.expression,
            );
            if (target !== null)
                this.addEdge("imports", this.moduleId, target, node, {
                    type_only: node.isTypeOnly,
                });
        }
    }

    newExpression(node) {
        const url = importMetaUrlModule(node, this.sourceFile, this.root);
        if (url !== null)
            this.addEdge(
                "imports",
                this.currentSource() ?? this.moduleId,
                reference("module", url),
                node,
                {
                    dynamic: true,
                    url: true,
                    type_only: false,
                    speculative: true,
                },
            );
        const source = this.currentSource();
        const target =
            this.symbolReference(
                this.checker.getSymbolAtLocation(node.expression),
                "class",
                true,
            ) ?? this.constructedClass(node.expression);
        if (source !== null && target !== null)
            this.addEdge("constructs", source, target, node);
    }

    /**
     * The class a binding holds when `new` names the binding rather than the
     * class. A binding assigned a class (`const B: typeof A = Actual`, or a
     * class expression) holds that class, whatever its annotation says. A
     * binding with no initializer to read (`const { Backend } =
     * require('./local') as typeof import('./local')`) holds what its type
     * says: the constructor type's symbol is the class. An annotated binding
     * assigned anything else proves nothing.
     */
    constructedClass(expression) {
        const binding = unalias(
            this.checker,
            this.checker.getSymbolAtLocation(expression),
        )?.valueDeclaration;
        if (
            binding !== undefined &&
            ts.isVariableDeclaration(binding) &&
            binding.initializer !== undefined
        ) {
            const value = unwrapExpression(binding.initializer);
            const assigned =
                value !== undefined &&
                (ts.isClassExpression(value) || ts.isIdentifier(value))
                    ? this.classSymbolReference(
                          ts.isClassExpression(value)
                              ? this.checker
                                    .getTypeAtLocation(value)
                                    .getSymbol()
                              : unalias(
                                    this.checker,
                                    this.checker.getSymbolAtLocation(value),
                                ),
                      )
                    : null;
            if (assigned !== null || binding.type !== undefined)
                return assigned;
        }
        return this.classSymbolReference(
            this.checker.getTypeAtLocation(expression).getSymbol(),
        );
    }

    /** The node a class symbol names, declared or a class expression, or null. */
    classSymbolReference(symbol) {
        return symbol?.declarations?.some(
            (item) => ts.isClassDeclaration(item) || ts.isClassExpression(item),
        )
            ? this.symbolReference(symbol, "class", true)
            : null;
    }

    callExpression(node) {
        if (
            node.expression.kind === ts.SyntaxKind.ImportKeyword &&
            node.arguments.length === 1 &&
            ts.isStringLiteral(node.arguments[0])
        ) {
            const target = this.moduleTarget(
                node.arguments[0].text,
                node.arguments[0],
            );
            if (target !== null)
                this.addEdge(
                    "imports",
                    this.currentSource() ?? this.moduleId,
                    target,
                    node,
                    { dynamic: true, type_only: false },
                );
            // Only when the import resolved to a module INSIDE the project.
            // `target` above is non-null for an external package too —
            // moduleTarget() falls back to minting a `package` node for
            // anything that isn't internal — so gating on it here would still
            // let dynamicDefaultImport resolve a real default export an npm
            // package happens to have, minting an external_function node and
            // a references edge for what is, from this project, just an
            // ordinary dependency.
            if (this.internalModuleTarget(node.arguments[0]) !== null)
                this.dynamicDefaultImport(node.arguments[0]);
            return;
        }
        if (
            ts.isIdentifier(node.expression) &&
            node.expression.text === "require" &&
            node.arguments.length === 1 &&
            ts.isStringLiteral(node.arguments[0])
        ) {
            const target = this.moduleTarget(
                node.arguments[0].text,
                node.arguments[0],
            );
            if (target !== null)
                this.addEdge(
                    "imports",
                    this.currentSource() ?? this.moduleId,
                    target,
                    node,
                    { commonjs: true, type_only: false },
                );
            return;
        }

        this.pathLiteralImports(node);
        for (const name of storeMemberNames(node)) this.untypedCalls.add(name);
        const signature = this.checker.getResolvedSignature(node);
        if (
            signature?.declaration === undefined &&
            ts.isPropertyAccessExpression(node.expression)
        ) {
            // `x.m.bind(...)` on an untyped `x` may reach any `m`; the name
            // `bind` says nothing about which.
            const bound = boundFunction(node.expression);
            this.untypedCalls.add(
                bound !== undefined && ts.isPropertyAccessExpression(bound)
                    ? bound.name.text
                    : node.expression.name.text,
            );
        }
        const source = this.currentSource();
        const target = this.callEdge(node, signature, source);

        const calledName = callName(node.expression);
        if (source !== null && calledName?.startsWith("use") && target !== null)
            this.addEdge(
                "uses_hook",
                source,
                target,
                node,
                { framework: "react" },
                "framework_convention",
            );
        this.application.call(node, source, calledName);
    }

    /**
     * The function binding a call names, or null. A binding typed by an
     * annotation or a cast is called through that type's signature, whose
     * declaration is the type rather than the arrow, so the callee's own
     * symbol is what names the node.
     */
    bindingCallee(node) {
        const callee = ts.isPropertyAccessExpression(node.expression)
            ? node.expression.name
            : node.expression;
        const symbol = unalias(
            this.checker,
            this.checker.getSymbolAtLocation(callee),
        );
        return symbol?.declarations?.some((declaration) =>
            isFunctionBinding(declaration),
        )
            ? this.symbolReference(symbol, "function", true)
            : null;
    }

    /**
     * The default export of a module loaded with `import('./x')`, which no
     * identifier in this file ever names.
     *
     * A static default import is reached at its USE site — `unalias()` resolves
     * the local binding back to the exported declaration when the name is read.
     * A dynamic import has no such site: `lazy(() => import('./pages/Admin'))`
     * hands the module object straight to React, and the component is rendered
     * from a variable holding the lazy wrapper. The module gets its `imports`
     * edge and the component inside it gets nothing, so every route in a
     * code-split application looked unreferenced — twenty of them on the project
     * this comes from, each one a page the router serves.
     *
     * Only `default` is resolved. It is what a dynamic import is overwhelmingly
     * used for, and a named export a caller destructures off the module object
     * is a narrower question this deliberately leaves alone: missing an edge
     * there costs a false candidate, while guessing at every export of every
     * dynamically imported module would quietly mark real dead code as live.
     */
    dynamicDefaultImport(specifier) {
        const moduleSymbol = this.checker.getSymbolAtLocation(specifier);
        if (!moduleSymbol) return;
        const exported = this.checker.tryGetMemberInModuleExports(
            "default",
            moduleSymbol,
        );
        const target = exported
            ? this.symbolReference(exported, "function", true)
            : null;
        const source = this.currentSource();
        if (source !== null && target !== null && source !== target)
            this.addEdge("references", source, target, specifier);
    }

    /**
     * The exports `const { App, Panel: P } = await import('./tui')` takes.
     *
     * Destructuring names each export exactly, so, unlike a module object
     * handed around whole, it resolves without guessing. Without this, a
     * module loaded lazily this way had its `imports` edge while every export
     * it takes looked unreferenced. A rest element names no export.
     */
    dynamicImportBindings(node) {
        if (!ts.isObjectBindingPattern(node.name) || !node.initializer) return;
        let call = unwrapParentheses(node.initializer);
        if (ts.isAwaitExpression(call))
            call = unwrapParentheses(call.expression);
        if (
            !ts.isCallExpression(call) ||
            call.expression.kind !== ts.SyntaxKind.ImportKeyword ||
            call.arguments.length !== 1 ||
            !ts.isStringLiteral(call.arguments[0])
        )
            return;
        const specifier = call.arguments[0];
        if (this.internalModuleTarget(specifier) === null) return;
        const moduleSymbol = this.checker.getSymbolAtLocation(specifier);
        if (!moduleSymbol) return;
        const source = this.currentSource();
        for (const element of node.name.elements) {
            if (element.dotDotDotToken) continue;
            const name = element.propertyName ?? element.name;
            if (!ts.isIdentifier(name) && !ts.isStringLiteral(name)) continue;
            const exported = this.checker.tryGetMemberInModuleExports(
                name.text,
                moduleSymbol,
            );
            const target = exported
                ? this.symbolReference(exported, "function", true)
                : null;
            if (source !== null && target !== null && source !== target)
                this.addEdge("references", source, target, element);
        }
    }

    /**
     * A speculative import of a project module a source path names as a
     * literal: the only evidence, so the core keeps the edge only when the
     * graph holds that module.
     */
    speculativeImport(relative, node) {
        this.addEdge(
            "imports",
            this.currentSource() ?? this.moduleId,
            reference("module", relative),
            node,
            { dynamic: true, type_only: false, speculative: true },
        );
    }

    /**
     * `require.context('./modules', false, /\.js$/)`: webpack bundles every
     * file of the directory the pattern matches. Which files those are is the
     * whole graph's business, not this request's (a request holds only the
     * files that changed), so one unexpanded edge names the directory, the
     * recursion and the pattern, and the reconciler expands it against every
     * module the graph holds.
     */
    requireContextImports(node) {
        const [directoryArg, recursiveArg, patternArg] = node.arguments;
        const absolute = this.contextDirectory(directoryArg.text);
        const directory =
            absolute === null ? null : relativeInside(this.root, absolute);
        const pattern =
            patternArg === undefined
                ? { source: "^\\.\\/.*$", flags: "" }
                : regularExpressionOf(patternArg);
        if (directory === null || pattern === null) return;
        this.addEdge(
            "imports",
            this.currentSource() ?? this.moduleId,
            reference(
                "module_context",
                JSON.stringify({
                    directory: directory === "." ? "" : directory,
                    recursive:
                        recursiveArg === undefined ||
                        recursiveArg.kind === ts.SyntaxKind.TrueKeyword,
                    pattern: pattern.source,
                    flags: pattern.flags,
                }),
            ),
            node,
            { dynamic: true, type_only: false, context: true },
        );
    }

    /**
     * The program's file a relative `require('./local')` names.
     *
     * The compiler binds a `require` argument to its module only in a
     * JavaScript file; in TypeScript it is a string. Each candidate is tried
     * in the order Node loads it, so `require('./x')` beside both `x.js` and
     * `x.ts` means the `.js`.
     */
    requiredSourceFile(location) {
        const sourceFileAt = this.project.sourceFileAt;
        if (
            sourceFileAt === undefined ||
            !ts.isStringLiteralLike(location) ||
            !/^\.\.?(\/|$)/.test(location.text)
        )
            return undefined;
        const base = path.resolve(
            path.dirname(this.sourceFile.fileName),
            location.text,
        );
        for (const suffix of REQUIRE_SUFFIXES) {
            const fileName = normalize(base + suffix);
            const file = sourceFileAt(fileName);
            if (file !== undefined) return file;
            // A file the program leaves out (a tsconfig `exclude`, or `.js`
            // without `allowJs`) is still the module the call loads. Only its
            // existence is asked, as the compiler host asks it, so nothing is
            // read; the answer is attributed to this file like any probe.
            if (
                allowedCompilerPath(this.root, fileName) &&
                this.project.fileExists(fileName)
            )
                return { fileName };
        }
        return undefined;
    }

    /**
     * `import.meta.glob('./Pages/**\/*.vue')`: Vite bundles every file the
     * pattern matches. Each positive pattern becomes the same unexpanded edge
     * `require.context` makes, the directory before the first wildcard and
     * the rest as a pattern over the paths below it. A negated pattern only
     * narrows what the others match, so leaving it out keeps a match live
     * rather than inventing one.
     */
    globImports(node) {
        const first = node.arguments[0];
        const patterns = ts.isArrayLiteralExpression(first)
            ? first.elements
            : [first];
        const base = globBase(node.arguments[1]);
        for (const pattern of patterns) {
            if (!ts.isStringLiteralLike(pattern)) continue;
            // A relative pattern resolves from `base` when the call sets one.
            const text =
                base !== null && /^\.\.?\//.test(pattern.text)
                    ? relativeGlob(base, pattern.text)
                    : pattern.text;
            const glob = globContext(text);
            if (glob === null) continue;
            const absolute = glob.directory.startsWith("/")
                ? normalize(path.resolve(this.root, "." + glob.directory))
                : this.contextDirectory(glob.directory);
            const directory =
                absolute === null ? null : relativeInside(this.root, absolute);
            if (directory === null) continue;
            this.addEdge(
                "imports",
                this.currentSource() ?? this.moduleId,
                reference(
                    "module_context",
                    JSON.stringify({
                        directory: directory === "." ? "" : directory,
                        recursive: glob.recursive,
                        pattern: glob.pattern,
                        flags: "",
                    }),
                ),
                pattern,
                { dynamic: true, type_only: false, context: true },
            );
        }
    }

    /**
     * The directory a `require.context` names: relative to this file, or
     * through the program's `paths` (which carry a bundler's aliases).
     */
    contextDirectory(specifier) {
        const here = path.dirname(
            realSourcePath(normalize(this.sourceFile.fileName)),
        );
        if (specifier.startsWith("."))
            return normalize(path.resolve(here, specifier));
        const options = this.project.options ?? {};
        const base = options.pathsBasePath ?? options.baseUrl ?? this.root;
        // An exact key wins; otherwise the longest matching prefix, as the
        // compiler picks among `paths` patterns.
        let best = null;
        for (const [key, targets] of Object.entries(options.paths ?? {})) {
            const target = targets[0];
            if (target === undefined) continue;
            if (key === specifier) return normalize(path.resolve(base, target));
            const prefix = key.endsWith("*") ? key.slice(0, -1) : null;
            if (
                prefix !== null &&
                specifier.startsWith(prefix) &&
                (best === null || prefix.length > best.prefix.length)
            )
                best = { prefix, target };
        }
        return best === null
            ? null
            : normalize(
                  path.resolve(
                      base,
                      best.target.replace(
                          "*",
                          specifier.slice(best.prefix.length),
                      ),
                  ),
              );
    }

    /**
     * Source paths a call names by literal: `resolve(__dirname, 'x/y.tsx')`
     * (or `join`, or `import.meta.dirname`), relative to this file, or
     * `join(root, 'scripts', 'x.ts')` from a base it cannot know; and
     * `navigator.serviceWorker.register('/sw.js')`, a URL under the web root.
     */
    pathLiteralImports(node) {
        if (isRequireContext(node)) {
            this.requireContextImports(node);
            return;
        }
        if (isImportMetaGlob(node)) {
            this.globImports(node);
            return;
        }
        const callee = node.expression;
        const name = ts.isIdentifier(callee)
            ? callee.text
            : ts.isPropertyAccessExpression(callee)
              ? callee.name.text
              : null;
        const args = node.arguments;
        if (
            (name === "resolve" || name === "join") &&
            args.length >= 2 &&
            args.slice(1).every((arg) => ts.isStringLiteralLike(arg))
        ) {
            // A literal first segment is part of the path (`join('src',
            // 'util.ts')`); any other first argument is the unknown base.
            const joined = args
                .slice(ts.isStringLiteralLike(args[0]) ? 0 : 1)
                .map((arg) => arg.text)
                .join("/");
            // `__dirname` names this file's directory. Any other base
            // (`root`, `ROOT`, `process.cwd()`) is unknown here, so both usual
            // answers are offered: this file's directory and the project
            // root. Speculative, so a guess the graph does not hold is dropped.
            const bases = isDirnameExpression(args[0])
                ? [path.dirname(this.sourceFile.fileName)]
                : [path.dirname(this.sourceFile.fileName), this.root];
            for (const base of bases) {
                const relative = sourcePathTarget(this.root, base, joined);
                if (relative !== null) this.speculativeImport(relative, node);
            }
            return;
        }
        if (
            name === "register" &&
            ts.isPropertyAccessExpression(callee) &&
            ts.isPropertyAccessExpression(callee.expression) &&
            callee.expression.name.text === "serviceWorker" &&
            args.length >= 1 &&
            ts.isStringLiteralLike(args[0]) &&
            args[0].text.startsWith("/")
        ) {
            // Served from the web root, which is `public/` or `static/` in
            // most toolchains, or the project root itself.
            const url = args[0].text.slice(1).split(/[?#]/)[0];
            for (const base of ["public", "static", ""]) {
                const relative = sourcePathTarget(
                    this.root,
                    this.root,
                    base === "" ? url : `${base}/${url}`,
                );
                if (relative !== null) this.speculativeImport(relative, node);
            }
        }
    }

    /**
     * `build({ entryPoints: ['src/boot.ts'] })`: a bundler's entries, named
     * by path relative to where the build runs, which is this file's
     * directory for a build script beside its package.
     */
    entryPointsProperty(node) {
        const name =
            ts.isIdentifier(node.name) || ts.isStringLiteral(node.name)
                ? node.name.text
                : null;
        if (!["entryPoints", "entrypoints", "entry", "input"].includes(name))
            return;
        const values = ts.isArrayLiteralExpression(node.initializer)
            ? node.initializer.elements
            : ts.isObjectLiteralExpression(node.initializer)
              ? node.initializer.properties
                    .filter((member) => ts.isPropertyAssignment(member))
                    .map((member) => member.initializer)
              : [node.initializer];
        for (const value of values) {
            if (!ts.isStringLiteralLike(value)) continue;
            const relative = sourcePathTarget(
                this.root,
                path.dirname(this.sourceFile.fileName),
                value.text,
            );
            if (relative !== null) this.speculativeImport(relative, value);
        }
    }

    typeReference(node) {
        const source = this.currentSource();
        const target = this.typeNodeReference(node);
        if (source !== null && target !== null && source !== target)
            this.addEdge("references", source, target, node);
    }

    /**
     * A callable or type used as a VALUE rather than invoked — pushed into an
     * array or object literal, passed as a callback, assigned to a const,
     * exported through a registry.
     *
     * Without this, the only edges into a function were `calls` / `constructs`
     * (from call and new expressions) and `references` (from type positions), so
     * every dispatch-table entry looked unreferenced and dead-code analysis
     * reported it as a "probable" dead candidate — e.g. the members of a
     * `const VALIDATORS = [validateA, validateB]` array, which are demonstrably
     * live. The edge kind is `references`, matching the type-position case.
     *
     * Scope is deliberately narrow: only identifiers resolving to a callable or
     * type DECLARATION in this repository, and only in positions no other
     * handler already covers. Emitting an edge for every identifier (locals,
     * parameters, property reads) would multiply graph size and swamp the
     * in-degree signal that hub and hotspot ranking depends on.
     */
    valueReference(node) {
        if (this.enumMemberRead(node)) return;
        if (!valueReferencePosition(node)) return;
        const exported =
            ts.isPropertyAccessExpression(node.parent) &&
            node.parent.name === node
                ? this.requiredExport(node.parent)
                : null;
        if (exported !== null) {
            const source = this.currentSource();
            if (source !== null && source !== exported.target)
                this.addEdge(
                    "references",
                    source,
                    exported.target,
                    node,
                    exported.speculative ? { speculative: true } : {},
                );
            return;
        }

        // A shorthand `{ discover }` names the object's property; the value it
        // copies is the function, which only this lookup returns.
        const symbol = unalias(
            this.checker,
            ts.isShorthandPropertyAssignment(node.parent)
                ? this.checker.getShorthandAssignmentValueSymbol(node.parent)
                : this.checker.getSymbolAtLocation(node),
        );
        const declaration = symbol?.declarations?.find((item) =>
            referenceableDeclaration(item),
        );
        if (!declaration) return;

        const target = this.symbolReference(
            symbol,
            callableKind(declaration),
            true,
        );
        const source = this.currentSource();
        if (source !== null && target !== null && source !== target)
            this.addEdge("references", source, target, node);
    }

    /**
     * Emit the `calls` edge a call makes and return its target: the resolved
     * declaration, or a loaded module's export read behind an inline type,
     * which is speculative when the module is outside the program.
     */
    callEdge(node, signature, source) {
        const required =
            ts.isPropertyAccessExpression(node.expression) &&
            signature?.declaration === undefined
                ? this.requiredExport(node.expression)
                : null;
        const target =
            this.callTarget(node, signature) ?? required?.target ?? null;
        if (source !== null && target !== null)
            this.addEdge(
                "calls",
                source,
                target,
                node,
                required?.speculative ? { speculative: true } : {},
            );
        return target;
    }

    /**
     * The declaration a call reaches: a local binding's function, the
     * signature the checker resolved, or a loaded module's export read
     * behind an inline type.
     */
    callTarget(node, signature) {
        return (
            this.bindingCallee(node) ??
            this.symbolReference(
                signature?.declaration?.symbol,
                callableKind(signature?.declaration),
                true,
            )
        );
    }

    /**
     * `const { getSpiderResponse: fn } = await import('./service')`: a name
     * taken out of an object is the declaration the object's property is, so
     * a module's export destructured from a loaded module is referenced.
     */
    destructuredMember(element) {
        const key = element.propertyName ?? element.name;
        const name =
            ts.isIdentifier(key) || ts.isStringLiteral(key) ? key.text : null;
        if (name === null) return;
        const property = this.checker
            .getTypeAtLocation(element.parent)
            .getProperty(name);
        const symbol = unalias(this.checker, property);
        const declaration = symbol?.declarations?.find((item) =>
            referenceableDeclaration(item),
        );
        if (!declaration) return;
        const target = this.symbolReference(
            symbol,
            callableKind(declaration),
            true,
        );
        const source = this.currentSource();
        if (source !== null && target !== null && source !== target)
            this.addEdge("references", source, target, element);
    }

    /**
     * The export a member read names when its object is a module loaded by
     * `require('./x')` behind an inline type (`as { handle: ... }`) that
     * hides the module's own: `service.handle` is the module's `handle`. For
     * a module the program leaves out, the reference is speculative.
     */
    requiredExport(access) {
        if (!ts.isIdentifier(access.expression)) return null;
        const binding = unalias(
            this.checker,
            this.checker.getSymbolAtLocation(access.expression),
        )?.valueDeclaration;
        const call =
            binding !== undefined &&
            ts.isVariableDeclaration(binding) &&
            binding.initializer !== undefined
                ? unwrapExpression(binding.initializer)
                : undefined;
        if (
            call === undefined ||
            !ts.isCallExpression(call) ||
            !ts.isIdentifier(call.expression) ||
            call.expression.text !== "require" ||
            call.arguments.length !== 1 ||
            !ts.isStringLiteralLike(call.arguments[0])
        )
            return null;
        const file =
            unalias(
                this.checker,
                this.checker.getSymbolAtLocation(call.arguments[0]),
            )?.declarations?.find((item) => ts.isSourceFile(item)) ??
            this.requiredSourceFile(call.arguments[0]);
        if (file === undefined) return null;
        if (!ts.isSourceFile(file)) {
            // A file the program leaves out: its exports are unknown, so the
            // function the name would be is named, and kept only if it exists.
            const relative = relativeInside(this.root, file.fileName);
            return relative === null
                ? null
                : {
                      target: reference(
                          "function",
                          `${relative}#${access.name.text}`,
                      ),
                      speculative: true,
                  };
        }
        const symbol = unalias(
            this.checker,
            this.checker
                .getSymbolAtLocation(file)
                ?.exports?.get(access.name.text),
        );
        const declaration = symbol?.declarations?.find((item) =>
            referenceableDeclaration(item),
        );
        const target = declaration
            ? this.symbolReference(symbol, callableKind(declaration), true)
            : null;
        return target === null ? null : { target, speculative: false };
    }

    /**
     * `TokenType.And`: an enum read through its members, which are not nodes
     * of their own, so the read is a reference to the enum.
     */
    enumMemberRead(node) {
        const parent = node.parent;
        if (
            parent === undefined ||
            !ts.isPropertyAccessExpression(parent) ||
            parent.expression !== node
        )
            return false;
        const symbol = unalias(
            this.checker,
            this.checker.getSymbolAtLocation(node),
        );
        if (!symbol?.declarations?.some((item) => ts.isEnumDeclaration(item)))
            return false;
        const source = this.currentSource();
        const target = this.symbolReference(symbol, "enum", true);
        if (source !== null && target !== null && source !== target)
            this.addEdge("references", source, target, node);
        return true;
    }

    typeNodeReference(node) {
        const type = this.checker.getTypeFromTypeNode(node);
        return this.symbolReference(type.aliasSymbol ?? type.symbol, "class");
    }

    moduleTarget(specifier, location) {
        const internal = this.internalModuleTarget(location);
        if (internal !== null) return internal;
        // An import nothing resolved under a tsconfig `paths` key (bundler
        // aliases are folded into them) names the project's own code that is
        // missing, not a dependency: `@app/missing` under `@app/*`. One that
        // resolved into a dependency (`vue` aliased to a file of the `vue`
        // package) is that package.
        if (!this.resolvedImport(location) && this.underAlias(specifier))
            return null;

        const packageName = externalPackageName(specifier);
        if (packageName !== null) {
            const id = reference("package", packageName);
            this.addNode(id, "package", packageName, packageName, location, {
                external: true,
            });
            return id;
        }
        return null;
    }

    /** Whether the compiler resolved an import's specifier to any file. */
    resolvedImport(location) {
        if (this.checker.getSymbolAtLocation(location) !== undefined)
            return true;
        return (
            ts.isStringLiteralLike(location) &&
            this.project.resolved?.(location) === true
        );
    }

    /**
     * Whether a `paths` key covers a specifier, as the compiler matches them.
     *
     * `ts.tryParsePatterns` and `ts.matchPatternOrExact` are the compiler's
     * internal helpers, not its public API, and their shape changed in
     * TypeScript 5.6; the package-name tests import `@app/missing` under
     * `@app/*`, so a TypeScript release that changes them again fails there.
     */
    underAlias(specifier) {
        return this.aliasPatterns().some(
            (patterns) =>
                ts.matchPatternOrExact(patterns, specifier) !== undefined,
        );
    }

    /**
     * The program's `paths` keys as the compiler matches them, without the
     * catch-all `*`, which says nothing about a name. Wrapped in a list, as
     * a program without `paths` has none.
     */
    aliasPatterns() {
        if (this.parsedAliases === undefined) {
            const keys = Object.keys(this.project.options?.paths ?? {}).filter(
                (key) => key !== "*",
            );
            this.parsedAliases =
                keys.length === 0
                    ? []
                    : [
                          ts.tryParsePatterns(
                              Object.fromEntries(keys.map((key) => [key, []])),
                          ),
                      ];
        }
        return this.parsedAliases;
    }

    /**
     * The module id when the specifier resolves to a real file inside this
     * project, or null when it resolves outside it (an npm package's own
     * source, or a node_modules copy) or not at all.
     *
     * Split out of {@link moduleTarget} so a caller — {@link callExpression}'s
     * dynamic-import handling — can tell "resolved to a project module" apart
     * from "resolved to SOMETHING", which `moduleTarget`'s own return does
     * not: it falls back to minting an external `package` node for anything
     * that isn't internal, so a non-null `moduleTarget` result does not mean
     * an internal module.
     */
    internalModuleTarget(location) {
        const symbol = unalias(
            this.checker,
            this.checker.getSymbolAtLocation(location),
        );
        const declaration =
            symbol?.declarations?.find((item) => ts.isSourceFile(item)) ??
            this.requiredSourceFile(location);
        if (!declaration) return null;
        const relative = relativeInside(this.root, declaration.fileName);
        if (relative === null || belowNodeModules(relative)) return null;
        return reference("module", relative);
    }

    symbolReference(input, hint = "class", value = false) {
        const symbol = unalias(this.checker, input);
        const inProject = (item) =>
            relativeInside(this.root, item.getSourceFile().fileName) !== null;
        // A type and a value may share a name and so one symbol. Read as a
        // value (called, constructed, referenced), it is the value's
        // declaration, whichever of the two came first.
        const declaration =
            (value
                ? symbol?.declarations?.find(
                      (item) => inProject(item) && !isTypeOnlyDeclaration(item),
                  )
                : undefined) ?? symbol?.declarations?.find(inProject);
        if (!declaration) {
            const name = symbol?.getName();
            return name && !name.startsWith("__")
                ? reference(`external_${hint}`, name)
                : null;
        }
        const relative = relativeInside(
            this.root,
            declaration.getSourceFile().fileName,
        );
        if (relative === null || belowNodeModules(relative)) {
            const name = symbol?.getName();
            return name && !name.startsWith("__")
                ? reference(`external_${hint}`, name)
                : null;
        }
        // A call resolves to the arrow or function expression itself; the
        // node is the module-level binding it initialises.
        const binding = functionBindingOf(declaration);
        if (binding !== null)
            return reference(
                "function",
                canonicalForDeclaration(binding, relative),
            );
        // Declared in this project, but not as anything `declaration()` emits:
        // a type parameter, an inline `{ ... }` type, an arrow bound inside a
        // function. The name built for it would match no node, and the core
        // turns a dangling edge into an external component that is neither
        // external nor a component.
        if (!isDeclaration(declaration)) return null;
        const kind = declarationKind(declaration, hint);
        const canonical = canonicalForDeclaration(declaration, relative);
        return reference(kind, canonical);
    }

    currentSource() {
        return this.container.at(-1)?.id ?? this.moduleId;
    }

    /** Add a node; false when it is a declaration past the file's own text. */
    addNode(
        id,
        kind,
        canonicalName,
        displayName,
        node,
        attributes = {},
        origin = "ast",
    ) {
        return this.accumulator.addNode(
            id,
            kind,
            canonicalName,
            displayName,
            node,
            attributes,
            origin,
        );
    }

    addEdge(kind, source, target, node, attributes = {}, origin = "ast") {
        this.accumulator.addEdge(
            kind,
            source,
            target,
            node,
            attributes,
            origin,
        );
    }
}

function parseConfig(root, configPath, reads) {
    const absolute = validatedInside(root, configPath);
    const host = {
        ...ts.sys,
        // A config and every config it extends or references decides the
        // program's files and options, so each read is recorded under its
        // walk's keys like any other: the hash of the raw bytes read, or null
        // for a read that failed. Discovery hashes a tsconfig as a project
        // unit, so the core checks these. Configs were never held to the
        // per-file source cap, and still are not: a config discovery skipped
        // as too large is not a unit, so its key is ignored.
        readFile: (file) =>
            allowedCompilerPath(root, file)
                ? readRecorded(root, file, reads, Number.MAX_SAFE_INTEGER)
                : undefined,
        // An `extends` target is probed before it is read, and a probe that
        // finds nothing decides the options as surely as a read.
        fileExists: (file) =>
            allowedCompilerPath(root, file) &&
            probeRecorded(reads, root, file, Number.MAX_SAFE_INTEGER),
        readDirectory: (directory, extensions, excludes, includes, depth) => {
            if (!allowedCompilerPath(root, directory)) return [];
            return ts.sys
                .readDirectory(directory, extensions, excludes, includes, depth)
                .filter((file) => allowedCompilerPath(root, file));
        },
        onUnRecoverableConfigFileDiagnostic: () => {},
    };
    const parsed = ts.getParsedCommandLineOfConfigFile(
        absolute,
        { noEmit: true },
        host,
        undefined,
        undefined,
        COMPONENT_FILE_EXTENSIONS,
    );
    if (!parsed)
        throw new Error(`Unable to parse TypeScript config: ${configPath}`);
    const fileNames = new Set(
        parsed.fileNames.filter((file) => allowedCompilerPath(root, file)),
    );
    // The files the config lists itself, before its references are merged
    // in: what decides which config describes a file (see scan()).
    const ownFileNames = [...fileNames];
    const pending = [...(parsed.projectReferences ?? [])];
    const visited = new Set([absolute]);
    // Where each referenced config emits, which outputSources() maps back.
    const referencedOutputs = [];
    while (pending.length > 0) {
        const reference = pending.pop();
        const referenceConfig = normalize(
            ts.resolveProjectReferencePath(reference),
        );
        if (
            !allowedCompilerPath(root, referenceConfig) ||
            visited.has(referenceConfig)
        )
            continue;
        visited.add(referenceConfig);
        const referenced = ts.getParsedCommandLineOfConfigFile(
            referenceConfig,
            { noEmit: true },
            host,
            undefined,
            undefined,
            COMPONENT_FILE_EXTENSIONS,
        );
        if (!referenced) continue;
        for (const file of referenced.fileNames) {
            if (allowedCompilerPath(root, file)) fileNames.add(file);
        }
        referencedOutputs.push([referenceConfig, referenced.options]);
        pending.push(...(referenced.projectReferences ?? []));
    }
    parsed.fileNames = [...fileNames].map((file) => offeredComponentPath(file));
    parsed.ownFileNames = ownFileNames.map((file) =>
        offeredComponentPath(file),
    );
    parsed.referencedOutputs = referencedOutputs;
    // References are kept, and the host resolves a reference's build output
    // back to its source (see createRestrictedProgram), so an import of
    // `../lib/dist/index.js` or a package.json `imports` alias onto it lands
    // on lib/src without the project ever having been built.
    return parsed;
}

// The defaults TypeScript 5.x applied to options a config leaves unset, where
// 6.0 changed them: every `@types` package rather than none, non-strict, and no
// check on side-effect imports.
const TYPESCRIPT_5_DEFAULTS = Object.freeze({
    types: ["*"],
    strict: false,
    noUncheckedSideEffectImports: false,
});

/**
 * The parsed config with the defaults of the project's own TypeScript under it.
 *
 * The worker bundles 6.x. A project built with 5.x never opted into 6.0's new
 * defaults, and checking it under them reported every `process`, `Buffer` and
 * implicit `any` its own `tsc` accepts. An option the config sets explicitly
 * still wins, and a project that names no TypeScript keeps the bundled defaults.
 *
 * `versions` maps a manifest's directory (`""` for the root) to the major
 * version its `typescript` dependency declares. The core reads those from the
 * manifests discovery already hashed, so deciding this reads nothing here.
 * The nearest manifest at or above the config's directory answers.
 */
function withProjectCompilerDefaults(root, directory, parsed, versions) {
    const major = projectTypeScriptMajor(root, directory, versions);
    if (major === null || major >= 6) return parsed;
    return {
        ...parsed,
        options: { ...TYPESCRIPT_5_DEFAULTS, ...parsed.options },
    };
}

/**
 * The parsed config a program is built from, for the project at `directory`:
 * see the two functions it applies.
 */
function programConfig(request, directory, parsed) {
    const { root, versions, reads, maxFileBytes } = request;
    return {
        ...withBundlerAliases(
            root,
            directory,
            withProjectCompilerDefaults(root, directory, parsed, versions),
            reads,
            maxFileBytes,
        ),
        vueProject: inVueProject(root, directory, request.vueProjects),
        outputSources: request.outputSources ?? [],
    };
}

/**
 * Whether a program's directory lies on the path of a package that depends
 * on Vue: at or below it (the package holds the config), or above it (the
 * config, or the project root for the fallback program, holds the package).
 * Decided from manifests the core read, never from the files in a request.
 */
function inVueProject(root, directory, vueProjects) {
    const relative = relativeInside(root, directory);
    if (relative === null) return false;
    const here = relative === "." ? "" : relative;
    return vueProjects.some(
        (project) =>
            typeof project === "string" &&
            (project === "" ||
                here === "" ||
                here === project ||
                here.startsWith(`${project}/`) ||
                project.startsWith(`${here}/`)),
    );
}

// Bundler and framework configs that declare module aliases, by directory.
const ALIAS_CONFIGS = [
    "svelte.config.js",
    "svelte.config.mjs",
    "svelte.config.ts",
    "vite.config.js",
    "vite.config.mjs",
    "vite.config.ts",
    "vite.config.mts",
    "webpack.config.js",
    "webpack.config.cjs",
    "webpack.config.mjs",
    "webpack.mix.js",
    "vue.config.js",
];

/**
 * The parsed config with the module aliases the project's bundler declares,
 * for any name the config does not map already.
 *
 * An import of `~/components/App` or `$lib/format` means what the bundler's
 * `resolve.alias` (or SvelteKit's `kit.alias`) says, and a project that
 * relies on the bundler has no tsconfig `paths` for it; SvelteKit writes its
 * aliases, `$lib` included, into a generated tsconfig no checkout has. Such
 * imports resolved to nothing, and everything imported that way read as
 * unused. The configs are read, and recorded, only when they exist.
 */
function withBundlerAliases(root, directory, parsed, reads, maxFileBytes) {
    const aliases = {};
    for (const name of ALIAS_CONFIGS) {
        const config = path.join(directory, name);
        if (
            !allowedCompilerPath(root, config) ||
            !probeRecorded(reads, root, config, maxFileBytes)
        )
            continue;
        if (name.startsWith("svelte.")) aliases.$lib ??= "src/lib";
        const text = readRecorded(root, config, reads, maxFileBytes);
        if (text !== undefined)
            Object.assign(
                aliases,
                declaredAliases(text, name.startsWith("vite.")),
            );
    }
    if (Object.keys(aliases).length === 0) return parsed;
    const paths = { ...parsed.options.paths };
    for (const [name, target] of Object.entries(aliases)) {
        const absolute = normalize(path.resolve(directory, target));
        if (name.endsWith("/*")) {
            paths[name] ??= [absolute];
            continue;
        }
        paths[name] ??= [absolute];
        paths[`${name}/*`] ??= [`${absolute}/*`];
    }
    return { ...parsed, options: { ...parsed.options, paths } };
}

/**
 * The entries of every `alias` object in a config whose target can be read
 * without running it: a string, `path.join|resolve(__dirname, …)`, or
 * `fileURLToPath(new URL('./x', import.meta.url))`, as an object or as
 * Vite's `[{ find, replacement }]` array. An exact-match key (`vue$`) names a
 * package, not a directory, and is skipped. With `rootRelative` (Vite), a
 * target starting with `/` is read against the config's directory.
 */
function declaredAliases(text, rootRelative) {
    const file = ts.createSourceFile(
        "config.ts",
        text,
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TS,
    );
    const aliases = {};
    const add = (name, expression) => {
        const target = name === null ? null : aliasTarget(expression);
        if (target === null || name.endsWith("$")) return;
        // Vite reads `/src` against the project root, not the filesystem's.
        aliases[name] =
            rootRelative && target.startsWith("/") ? `.${target}` : target;
    };
    const visit = (node) => {
        const entries =
            ts.isPropertyAssignment(node) &&
            staticPropertyName(node.name) === "alias"
                ? node.initializer
                : undefined;
        if (entries !== undefined && ts.isObjectLiteralExpression(entries)) {
            for (const entry of entries.properties) {
                if (ts.isPropertyAssignment(entry))
                    add(staticPropertyName(entry.name), entry.initializer);
            }
        }
        // Vite's array form: `[{ find: '@', replacement: '/src' }]`, with a
        // string `find`; a regular expression names no fixed prefix.
        if (entries !== undefined && ts.isArrayLiteralExpression(entries)) {
            for (const entry of entries.elements) {
                const find = objectField(entry, "find");
                const replacement = objectField(entry, "replacement");
                if (
                    find !== undefined &&
                    replacement !== undefined &&
                    ts.isStringLiteralLike(find)
                )
                    add(find.text, replacement);
            }
        }
        ts.forEachChild(node, visit);
    };
    visit(file);
    return aliases;
}

/** A directory an alias maps to, relative to its config, or null. */
function aliasTarget(expression) {
    if (ts.isStringLiteralLike(expression)) {
        // A path, not a package (`lodash-es`, `@scope/pkg`).
        const target = expression.text;
        return /^\.{0,2}\//.test(target) ||
            (target.includes("/") && !target.startsWith("@"))
            ? target
            : null;
    }
    if (!ts.isCallExpression(expression)) return null;
    const callee = expression.expression.getText();
    const [first, ...rest] = expression.arguments;
    if (
        /(?:^|\.)(?:join|resolve)$/.test(callee) &&
        first !== undefined &&
        ts.isIdentifier(first) &&
        first.text === "__dirname" &&
        rest.every((part) => ts.isStringLiteralLike(part))
    )
        return path.posix.join(".", ...rest.map((part) => part.text));
    const url = expression.arguments[0];
    if (
        callee === "fileURLToPath" &&
        url !== undefined &&
        ts.isNewExpression(url) &&
        url.arguments?.[0] !== undefined &&
        ts.isStringLiteralLike(url.arguments[0])
    )
        return url.arguments[0].text;
    return null;
}

/** How a fallback program's cache key ends: no tsconfig includes its files. */
const FALLBACK_KEY = "\0<fallback>";

function projectTypeScriptMajor(root, directory, versions) {
    if (versions === null || typeof versions !== "object") return null;
    let relative = relativeInside(root, directory);
    while (relative !== null) {
        const key = relative === "." ? "" : relative;
        const major = versions[key];
        if (Number.isInteger(major)) return major;
        if (key === "") return null;
        const parent = path.posix.dirname(key);
        relative = parent === "." ? "" : parent;
    }
    return null;
}

/**
 * The program's files, with the installed `svelte` package's declarations
 * when any of them is a Svelte component. The runes (`$state`, `$derived`,
 * `$props`) are globals that package declares, and svelte-check gives every
 * component those declarations; without them each rune returned `any`, and
 * every callback on what it returned reported an implicitly `any` parameter.
 * Resolved as the components would import `svelte`, so `paths` and the
 * package's own `types` entry decide the file, and nothing is added when it
 * is not installed.
 */
function withSvelteRunes(fileNames, options, host) {
    // Offered under their `X.svelte.ts` alias (see COMPONENT_ALIAS).
    const component = fileNames.find(
        (name) => COMPONENT_ALIAS.exec(name)?.[1].toLowerCase() === "svelte",
    );
    if (component === undefined) return fileNames;
    const resolved = ts.resolveModuleName(
        "svelte",
        component,
        options,
        host,
    ).resolvedModule;
    return resolved?.extension === ts.Extension.Dts &&
        !fileNames.includes(resolved.resolvedFileName)
        ? [...fileNames, resolved.resolvedFileName]
        : fileNames;
}

function createRestrictedProgram(
    root,
    parsed,
    oldProgram,
    maxFileBytes,
    reads,
) {
    // Architecture scanning only needs diagnostics for the project's own
    // sources, not for the internals of declaration files. Type-checking the
    // full .d.ts closure of heavy dependencies (e.g. vitest, @types/node pulled
    // in by test files) dominates both time and memory and can exhaust the
    // worker heap on real projects. skipLibCheck/skipDefaultLibCheck skip only
    // the .d.ts-internal checks; diagnostics reported on the user's .ts files
    // are unchanged. Measured on a 94-file target: OOM (>512MB) -> ~2.9s/292MB.
    const options = {
        ...parsed.options,
        skipLibCheck: true,
        skipDefaultLibCheck: true,
        traceResolution: false,
    };
    const host = ts.createCompilerHost(options, true);
    // Resolution tracing writes through the system host to stdout, which is
    // the frame channel, so a single trace line makes the reply invalid JSON
    // and the whole language is dropped. A referenced project's own options
    // are not overridden above, so the host's trace is silenced as well.
    host.trace = () => {};
    // What an editor does: a referenced project's outputs stand for its
    // sources, so nothing has to be built before it can be analysed.
    host.useSourceOfProjectReferenceRedirect = () => true;
    host.getSourceFile = (fileName, languageVersion) => {
        // A refused path is left out of the program, so the facts of every file
        // that imports or includes it are computed as if it did not exist. It
        // is recorded as a failed read under the key a read of it would have
        // gone under (walkPath, inputKeyLocation). A stable layout never trips
        // over that null: discovery reports neither a symlink nor an over-cap
        // file, and the core verifies an undiscovered key at commit, where a
        // null is valid only for a path that is absent, not a regular file, a
        // link, or over the cap, which is what a refusal names. A discovered
        // file that became one mid-scan fails verification against discovery.
        const absolute = normalize(path.resolve(realSourcePath(fileName)));
        reads.observe("load", absolute);
        const refused = () => {
            recordRefused(reads, root, absolute, maxFileBytes);
            return undefined;
        };
        // Refused for where it sits, which no state of the file can change,
        // so there is no read to report. Recording it as a failed read named a
        // readable regular file as unreadable, and the core's commit-time
        // re-read aborted every scan whose source reaches into its own build
        // output, such as a `bin/` script importing `../dist/index.js`.
        if (excludedByProjectLayoutPath(root, absolute)) return undefined;
        if (!allowedCompilerPath(root, fileName)) return refused();
        // The per-file byte cap is enforced on requested files, but the program
        // also pulls in import-reachable and included sources. Guard those too so
        // one giant generated file (e.g. a multi-MB bundled `.d.ts`) is never
        // fully parsed, bounding peak memory.
        if (exceedsByteCap(fileName, maxFileBytes)) return refused();
        // Read here rather than through the default host, which reads via
        // ts.sys.readFile and so never exposes the bytes it decoded. A shebang
        // alias is read, and recorded, under the script's real path.
        return readHashedSourceFile(
            root,
            realSourcePath(fileName),
            fileName,
            languageVersion,
            fileName.endsWith(SHEBANG_ALIAS_SUFFIX)
                ? ts.ScriptKind.JS
                : undefined,
            reads,
            maxFileBytes,
        );
    };
    // Module resolution decides an import's target by these answers, so an
    // in-root answer is recorded (recordProbe).
    host.fileExists = (file) =>
        probeRecorded(reads, root, realSourcePath(file), maxFileBytes);
    // Resolution realpaths a package's files before the host is asked for
    // them, so a link on that path is walked here, where its name is still
    // known, rather than lost behind the resolved name getSourceFile sees. The
    // walk follows the resolution: a tree that changed in between gives the
    // walk a location other than the name resolution returned, and that answer
    // is recorded as absent, since no single state of the tree describes it.
    host.realpath = (file) => {
        // An alias exists nowhere on disk: its realpath is the real file's,
        // under the same alias, and the walk is of the real file.
        const original = realSourcePath(normalize(file));
        if (original !== normalize(file))
            return `${host.realpath(original)}${normalize(file).slice(original.length)}`;
        let real;
        try {
            real = realpathNative(file);
        } catch (error) {
            rethrowStackOverflow(error);
            real = normalize(file);
        }
        const absolute = normalize(path.resolve(file));
        if (
            contains(root, absolute) &&
            !contains(defaultLibDirectory(), absolute)
        ) {
            const walked = walkPath(absolute);
            const present =
                (walked.kind === "file" || walked.kind === "directory") &&
                walked.location === real;
            recordProbe(reads, root, walked, present, maxFileBytes);
        }
        return real;
    };
    // A stat-then-read (exceedsByteCap followed by ts.sys.readFile) leaves a
    // window between the two where the file can grow past the cap, making
    // the read that follows unbounded. readBounded closes it: it reads at
    // most one byte past the cap itself, so the size actually read is what
    // is checked, not a size observed earlier.
    //
    // Module resolution reads package.json files through here, and their
    // fields decide an import's target, so every in-root read is recorded
    // under its walk's keys like any other: the hash of the bytes read, or null
    // for a read that failed. A path refused here was first answered by
    // host.fileExists, which recorded its walk. Discovery hashes package.json
    // as a project unit, and the core checks the entry against that hash.
    host.readFile = (file) =>
        allowedCompilerPath(root, file)
            ? readRecorded(root, file, reads, maxFileBytes)
            : undefined;
    const cache = ts.createModuleResolutionCache(
        host.getCurrentDirectory(),
        host.getCanonicalFileName,
        options,
    );
    // Shared with the compiler's own lookups (type references, package.json
    // formats), so a manifest is read once, not once per cache.
    host.getModuleResolutionCache = () => cache;
    host.resolveModuleNameLiterals = componentResolver(
        host,
        cache,
        parsed.vueProject === true,
        parsed.outputSources ?? [],
        (file) => allowedCompilerPath(root, file),
    );
    return ts.createProgram({
        rootNames: withSvelteRunes(parsed.fileNames, options, host),
        options,
        projectReferences: parsed.projectReferences,
        host,
        oldProgram,
    });
}

/**
 * Where each config in the scan, and each config one references, emits
 * (`outDir`) and what it emits from (`rootDir`, else its own directory).
 */
function outputSources(root, parsedConfigs) {
    const configs = parsedConfigs.flatMap(([configPath, parsed]) => [
        [path.join(root, configPath), parsed.options],
        ...(parsed.referencedOutputs ?? []),
    ]);
    return configs
        .flatMap(([configFile, options]) =>
            options.outDir === undefined
                ? []
                : [
                      {
                          outDir: normalize(options.outDir),
                          rootDir: normalize(
                              options.rootDir ?? path.dirname(configFile),
                          ),
                      },
                  ],
        )
        .sort((a, b) => b.outDir.length - a.outDir.length);
}

const BUILD_SOURCE_EXTENSIONS = new Map([
    [".ts", ts.Extension.Ts],
    [".tsx", ts.Extension.Tsx],
    [".mts", ts.Extension.Mts],
    [".cts", ts.Extension.Cts],
]);

/**
 * A module that resolved to, or only failed to find, another config's build
 * output, which is never read (see allowedCompilerPath), as the source that
 * output is built from: `#shared/x` naming `dist/shared/x.js` is that config's `x.ts`, the
 * file its build keeps the output in step with. Undefined when no failed
 * lookup lies in a known `outDir` or the source is not there.
 */
function sourceOfBuildOutput(result, outputs, host) {
    const locations = [
        ...(result.resolvedModule === undefined
            ? []
            : [result.resolvedModule.resolvedFileName]),
        ...(result.failedLookupLocations ?? []),
    ];
    for (const location of locations) {
        // Every output directory holding it, the nearest first: a config
        // emitting to `dist/shared` beside one emitting to `dist`.
        for (const output of outputs) {
            if (!location.startsWith(output.outDir + "/")) continue;
            const stem = location
                .slice(output.outDir.length + 1)
                .replace(/\.(?:d\.)?[cm]?[jt]sx?$/, "");
            for (const [suffix, extension] of BUILD_SOURCE_EXTENSIONS) {
                const candidate = `${output.rootDir}/${stem}${suffix}`;
                if (host.fileExists(candidate))
                    return {
                        resolvedModule: {
                            resolvedFileName: candidate,
                            extension,
                            isExternalLibraryImport: false,
                        },
                        failedLookupLocations: result.failedLookupLocations,
                    };
            }
        }
    }
    return undefined;
}

/**
 * Module resolution as the compiler does it, with two corrections for
 * components.
 *
 * An import that names a component (`./Card.vue`) means the component even
 * when a real `Card.vue.ts` sits beside it, which the compiler would try
 * first. And in a Vue project (`vueProject`, from the manifests), a relative
 * or path-mapped specifier with no extension that resolves to nothing
 * resolves to `.vue`, as webpack and Vue CLI list it in
 * `resolve.extensions`; the retry probes paths that are then recorded, so it
 * stays off elsewhere. The resolution mode follows a project reference's own
 * options, as the compiler's default loader does.
 */
function componentResolver(host, cache, vueProject, outputs, readable) {
    return (
        literals,
        containingFile,
        redirectedReference,
        compilerOptions,
        containingSourceFile,
    ) =>
        literals.map((literal) => {
            const resolve = (name) =>
                ts.resolveModuleName(
                    name,
                    containingFile,
                    compilerOptions,
                    host,
                    cache,
                    redirectedReference,
                    ts.getModeForUsageLocation(
                        containingSourceFile,
                        literal,
                        redirectedReference?.commandLine.options ??
                            compilerOptions,
                    ),
                );
            const direct = resolve(literal.text);
            // Resolved to build output is resolved to nothing: it is never
            // read (see allowedCompilerPath).
            const resolved = componentTarget(
                literal.text,
                direct.resolvedModule === undefined ||
                    !readable(direct.resolvedModule.resolvedFileName)
                    ? (sourceOfBuildOutput(direct, outputs, host) ?? direct)
                    : direct,
            );
            if (
                !vueProject ||
                resolved.resolvedModule !== undefined ||
                !literal.text.includes("/") ||
                path.posix.extname(literal.text) !== ""
            )
                return resolved;
            const component = resolve(`${literal.text}.vue`);
            // Both attempts probed, and a file at any of those paths would
            // change the answer, so each carries the other's misses.
            return component.resolvedModule !== undefined
                ? withFailedLookups(component, resolved)
                : withFailedLookups(resolved, component);
        });
}

/** A resolution that also names another attempt's failed lookups. */
function withFailedLookups(resolution, attempt) {
    return {
        ...resolution,
        failedLookupLocations: [
            ...(resolution.failedLookupLocations ?? []),
            ...(attempt.failedLookupLocations ?? []),
        ],
    };
}

/**
 * A resolution of a component specifier that landed on a real `X.vue.ts`
 * beside `X.vue`, redirected to the component's own alias; anything else as
 * it is.
 */
function componentTarget(specifier, resolved) {
    const dialect = componentDialect(specifier);
    const file = resolved.resolvedModule?.resolvedFileName;
    if (dialect === null || file === undefined) return resolved;
    const suffix = componentAliasSuffix(dialect);
    const component = file.slice(0, -suffix.length);
    if (
        !file.endsWith(suffix) ||
        componentDialect(component) !== dialect ||
        !isRegularFile(file) ||
        !isRegularFile(component)
    )
        return resolved;
    return {
        ...resolved,
        resolvedModule: {
            ...resolved.resolvedModule,
            resolvedFileName: `${component}${COMPONENT_ALIAS_MARK}${suffix}`,
        },
    };
}

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
