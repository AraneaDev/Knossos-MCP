import { createHash } from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import ts from "typescript";
import { FactAccumulator } from "./fact-accumulator.js";
import { NestJsFactEnricher } from "./nestjs-fact-enricher.js";
import { TypeScriptApplicationEnricher } from "./typescript-application-enricher.js";
import {
    bindingKeyword,
    callName,
    declarationModifiers,
    functionBindingOf,
    isFunctionBinding,
    reference,
} from "./typescript-fact-utils.js";
import {
    blankSource,
    componentAliasSuffix,
    componentDialect,
    componentDiagnosticKept,
    toVirtualSource,
} from "./component-source.js";

const SOURCE_EXTENSIONS = new Set([
    ".ts",
    ".tsx",
    ".mts",
    ".cts",
    ".js",
    ".jsx",
    ".mjs",
    ".cjs",
]);
// Bytes read when probing an extensionless file's shebang; one short line is enough.
const SHEBANG_PROBE_BYTES = 256;
// TypeScript silently drops a root file whose name carries no recognised
// extension, so an extensionless shebang script is offered to the program under
// a synthetic name ending in `.js` and mapped back to its real path on the way
// out. The suffix is deliberately unusual: a real file that collided with it
// would be reported under the wrong path.
const SHEBANG_ALIAS_SUFFIX = ".knossos-shebang.js";
// TypeScript recognises a source extension only in lower case, so a file such as
// `FOO.TS`, which discovery classifies as TypeScript, would be in no program and
// lose its facts on every scan. It is offered under its name plus this mark and
// the lower-cased extension, and mapped back the same way.
const CASE_ALIAS_MARK = ".knossos-alias";
// A component (`.vue`, `.svelte`, `.astro`) is offered to the compiler as
// `X.vue.ts` (`X.astro.tsx`), so module resolution finds it through relative
// paths, `paths` and `baseUrl` without a resolver of its own. When a real
// `X.vue.ts` exists it wins, and the component is offered under this mark.
const COMPONENT_ALIAS_MARK = ".knossos-component";
const COMPONENT_ALIAS = /\.(vue|svelte|astro)(\.knossos-component)?(\.tsx?)$/i;
// Lets a tsconfig `include` match components, so they are checked under the
// options of the project that holds them.
const COMPONENT_FILE_EXTENSIONS = [".vue", ".svelte", ".astro"].map(
    (extension) => ({
        extension,
        isMixedContent: false,
        scriptKind: ts.ScriptKind.Deferred,
    }),
);
const EXCLUDED_DIRECTORIES = new Set([
    ".git",
    ".knossos",
    "node_modules",
    "vendor",
    "coverage",
    ".next",
    ".nuxt",
    // Kept in sync with the authoritative PHP IgnoreMatcher. Generated build
    // output and mutation-testing sandboxes (.stryker-tmp holds a full project
    // copy per sandbox) are not source and would multiply program discovery.
    ".stryker-tmp",
    ".pnpm-store",
    ".yarn",
    ".worktrees",
    "build",
    "dist",
    "site",
]);

// Directory-name prefixes, for the namespace this tool owns. ".knossos" alone
// is in the set above; a CI job parks a checkout of the analyzer or its snapshot
// database beside the project under the same convention, and those must not be
// discovered as the project's own source.
const EXCLUDED_DIRECTORY_PREFIXES = [".knossos-"];
// Consecutive segments excluded wherever they appear, as the PHP IgnoreMatcher
// excludes them: VitePress's dependency cache and build below the site.
const EXCLUDED_SEGMENT_SEQUENCES = [
    [".vitepress", "cache"],
    [".vitepress", "dist"],
];
// Dependency trees may be read for module resolution even though discovery
// does not scan them as project-owned source. Generated and tool-owned trees
// remain blocked at this boundary.
const RESOLUTION_ALLOWED_EXCLUDED = new Set(["node_modules", "vendor"]);

/** Whether a directory entry is excluded from discovery by name alone. */
function isExcludedDirectoryName(name) {
    return (
        EXCLUDED_DIRECTORIES.has(name) ||
        EXCLUDED_DIRECTORY_PREFIXES.some((prefix) => name.startsWith(prefix))
    );
}

// Each retained ts.Program holds its own parsed default library and type
// checker (~100-120MB). A repo with many tsconfigs builds one program per
// config within a single scan; retaining them all at once exhausts the
// worker's --max-old-space-size cap. Bound the cache so peak live programs
// stays small while still allowing incremental reuse across scans in watch
// mode. A program is only needed until its files are emitted, so evicting the
// least-recently-used program never affects correctness — an evicted config is
// simply rebuilt from scratch on its next scan.
const MAX_CACHED_PROGRAMS = 2;

// The hash of the raw bytes each SourceFile was created from, keyed by the
// SourceFile object itself. Keyed by object rather than by path because
// programs are cached across requests and several programs can read the same
// path: a path-keyed map could pair one read's facts with another read's hash,
// which is precisely the false match the hash exists to prevent.
const parsedContentHashes = new WeakMap();
/**
 * A component's virtual source, by the source file made from it: its dialect,
 * the script and template ranges, whether its script is TypeScript, and, for
 * one that could not be read, why.
 */
const componentSources = new WeakMap();

/**
 * Every project file one scan request read to derive facts, for the result's
 * `input_hashes`: project-relative path to the SHA-256 of the bytes read, or
 * null when a read was attempted and failed.
 *
 * The checker resolves a requested file against every source file in its
 * program, so the map covers each file each program read, not only the requested
 * ones. One request can build several programs (one per tsconfig, plus the
 * fallback) and each reads its files afresh, so a path can be read more than
 * once. When those reads disagree, whether two different hashes or a hash and a
 * failure in either order, no single hash describes what the request's facts
 * came from, and the entry becomes null for good.
 *
 * One recorder per request, handed to each program's host: nothing about a read
 * outlives the request that made it.
 */
class InputReadRecorder {
    /**
     * @param {(stage: "load"|"read", absolute: string) => void} observePath
     *   Told each path the host is about to examine: "load" before a requested
     *   SourceFile's admission checks, "read" before the read resolves it. A
     *   test seam, so a test can change the tree at exactly that point.
     */
    constructor(observePath = () => {}) {
        this.hashes = new Map();
        this.sourceFiles = new WeakSet();
        this.observe = observePath;
    }

    /** Record one read; a read disagreeing with an earlier one records null. */
    record(relative, contentHash) {
        if (
            this.hashes.has(relative) &&
            this.hashes.get(relative) !== contentHash
        ) {
            contentHash = null;
        }
        this.hashes.set(relative, contentHash);
    }

    /** Remember a SourceFile this request created from its own read. */
    created(sourceFile) {
        this.sourceFiles.add(sourceFile);
    }

    /**
     * Whether this request created the SourceFile a program holds. A redirect
     * SourceFile (a duplicate package resolved to an already loaded copy) is a
     * view of its target, so its target is what must have been read.
     */
    createdThisRequest(sourceFile) {
        return this.sourceFiles.has(
            sourceFile.redirectInfo?.redirectTarget ?? sourceFile,
        );
    }

    /** The map as the result field, keys sorted for deterministic output. */
    toResult() {
        return Object.fromEntries(
            [...this.hashes].sort(([left], [right]) =>
                left < right ? -1 : left > right ? 1 : 0,
            ),
        );
    }
}

/**
 * Performs bounded compiler-backed scanning without executing target modules.
 * Instances retain TypeScript programs for incremental reuse.
 */
export class TypeScriptScanner {
    /**
     * @param {{observeHostPath?: (stage: "load"|"read", absolute: string) => void}} [options]
     *   `observeHostPath` is a test seam, handed to each request's recorder.
     */
    constructor({ observeHostPath } = {}) {
        this.programCache = new Map();
        this.observeHostPath = observeHostPath;
        // Reset per request; see #cacheProgram.
        this.programsBuiltThisRequest = 0;
    }

    /**
     * Stream deterministic owned contributions for the requested source files.
     *
     * @param {{root: unknown, files: unknown, config_files?: unknown, limits?: unknown, typescript_versions?: unknown}} params
     * @param {(contribution: object) => void} emit
     * @returns {{files_scanned: number, programs: number, programs_reused: number, input_hashes: Record<string, string|null>}}
     */
    scan(params, emit) {
        const root = validateRoot(params.root);
        const { accepted: requested, rejected } = validateRequestedFiles(
            root,
            params.files,
            params.limits,
        );
        const reads = new InputReadRecorder(this.observeHostPath);
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
        const maxFileBytes = maxFileBytesFrom(params.limits);
        const emitted = new Set();
        let programs = 0;
        let programsReused = 0;
        this.programsBuiltThisRequest = 0;

        const request = {
            root,
            maxFileBytes,
            reads,
            requestedSet,
            emitted,
            emit,
            versions: params.typescript_versions,
            vueProjects: Array.isArray(params.vue_projects)
                ? params.vue_projects
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

        for (const [configPath, parsed] of parsedConfigs) {
            request.owner = configPath;
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
            if (emitted.size === requestedSet.size) break;
        }

        const remaining = requested.filter(
            (relative) => !emitted.has(normalize(relative)),
        );
        if (remaining.length > 0) {
            // Whatever no config's program emitted, owned or not.
            request.owner = undefined;
            request.owners = new Map();
            const parsed = fallbackConfig(root, remaining);
            tally(
                this.#scanProgram(
                    `${root}\0<fallback>`,
                    programConfig(request, root, parsed),
                    request,
                ),
            );
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
            files_scanned: emitted.size + rejected.length,
            programs,
            programs_reused: programsReused,
            input_hashes: reads.toResult(),
        };
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
     * recorded.
     *
     * @returns {{reused: boolean}|undefined} undefined when the stack overflowed
     */
    #scanProgram(
        key,
        parsed,
        {
            root,
            maxFileBytes,
            reads,
            requestedSet,
            emitted,
            emit,
            owner,
            owners,
        },
    ) {
        this.#reserveProgramSlot(key);
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
            this.#emitProgram(
                root,
                program,
                requestedSet,
                emitted,
                emit,
                maxFileBytes,
                owner,
                owners ?? new Map(),
            );
        } catch (error) {
            if (!isStackOverflow(error)) throw error;
            const covered = [
                ...parsed.fileNames,
                ...(program?.getSourceFiles() ?? []).map(
                    (file) => file.fileName,
                ),
            ];
            for (const fileName of covered) {
                const relative = relativeInside(root, fileName);
                if (
                    relative === null ||
                    belowNodeModules(relative) ||
                    !requestedSet.has(relative) ||
                    emitted.has(relative)
                ) {
                    continue;
                }
                emit(
                    factFreeContribution(
                        relative,
                        "TS_PROGRAM_TOO_DEEP",
                        "The TypeScript compiler exceeded its stack building the program for this file's configuration (for example, an import chain too deep to follow), so its facts are omitted.",
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
        }
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
            return;
        }
        this.programCache.delete(key);
        this.programCache.set(key, program);
        while (this.programCache.size > MAX_CACHED_PROGRAMS) {
            const oldest = this.programCache.keys().next().value;
            this.programCache.delete(oldest);
        }
    }

    #emitProgram(
        root,
        program,
        requestedSet,
        emitted,
        emit,
        maxFileBytes,
        owner,
        owners,
    ) {
        const checker = program.getTypeChecker();
        const diagnosticsByFile = diagnosticsForProgram(
            program,
            root,
            maxFileBytes,
        );

        for (const sourceFile of program.getSourceFiles()) {
            const relative = relativeInside(root, sourceFile.fileName);
            if (
                relative === null ||
                belowNodeModules(relative) ||
                !requestedSet.has(relative) ||
                emitted.has(relative) ||
                // Another config includes this file itself; its program
                // describes it under the options the project really uses.
                (owners.has(relative) && owners.get(relative) !== owner)
            ) {
                continue;
            }

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

            // Isolate per-file collection: a single adversarial/minified file can
            // overflow the visitor recursion (RangeError). One bad file must
            // degrade to a diagnostic, not discard facts for every other file in
            // the request.
            let contribution;
            try {
                const collector = new FactCollector(root, sourceFile, checker, {
                    options: program.getCompilerOptions(),
                });
                collector.collect();
                contribution = {
                    owner_key: `knossos.typescript:file:${relative}`,
                    nodes: collector.nodes,
                    edges: collector.edges,
                    diagnostics: diagnosticsByFile.get(relative) ?? [],
                };
            } catch (error) {
                contribution = {
                    owner_key: `knossos.typescript:file:${relative}`,
                    nodes: [],
                    edges: [],
                    diagnostics: [
                        {
                            severity: "error",
                            code: "TS_INTERNAL_ERROR",
                            message:
                                error instanceof Error
                                    ? error.message
                                    : String(error),
                            evidence: {
                                path: relative,
                                start_line: 1,
                                end_line: 1,
                            },
                        },
                    ],
                };
            }
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
            emit(contribution);
            emitted.add(relative);
        }
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
        this.accumulator = new FactAccumulator(
            sourceFile,
            this.relative,
            evidence,
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
                    hasMainGuard(this.sourceFile),
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
        if (
            (ts.isFunctionDeclaration(node) ||
                ts.isMethodDeclaration(node) ||
                ts.isMethodSignature(node)) &&
            node.type
        ) {
            // `Clipboard | null` has no symbol of its own: each named member
            // of a union is a type the function may return.
            const returned = ts.isUnionTypeNode(node.type)
                ? node.type.types.filter((member) =>
                      ts.isTypeReferenceNode(member),
                  )
                : [node.type];
            for (const typeNode of returned) {
                const target = this.typeNodeReference(typeNode);
                if (target !== null)
                    this.addEdge("returns", id, target, typeNode);
            }
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
        this.addNode(
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
        const nest = this.nest.declaration(node, id, canonical);
        const applicationRoles = this.application.declaration(
            node,
            id,
            canonical,
            descriptor.name,
        );
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
        const target = this.symbolReference(
            this.checker.getSymbolAtLocation(node.expression),
            "class",
        );
        if (source !== null && target !== null)
            this.addEdge("constructs", source, target, node);
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
        const signature = this.checker.getResolvedSignature(node);
        if (
            signature?.declaration === undefined &&
            ts.isPropertyAccessExpression(node.expression)
        )
            this.untypedCalls.add(node.expression.name.text);
        const target = this.symbolReference(
            signature?.declaration?.symbol,
            callableKind(signature?.declaration),
        );
        const source = this.currentSource();
        if (source !== null && target !== null)
            this.addEdge("calls", source, target, node);

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
            ? this.symbolReference(exported, "function")
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
                ? this.symbolReference(exported, "function")
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
        if (!valueReferencePosition(node)) return;

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

        const target = this.symbolReference(symbol, callableKind(declaration));
        const source = this.currentSource();
        if (source !== null && target !== null && source !== target)
            this.addEdge("references", source, target, node);
    }

    typeNodeReference(node) {
        const type = this.checker.getTypeFromTypeNode(node);
        return this.symbolReference(type.aliasSymbol ?? type.symbol, "class");
    }

    moduleTarget(specifier, location) {
        const internal = this.internalModuleTarget(location);
        if (internal !== null) return internal;

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
        const declaration = symbol?.declarations?.find((item) =>
            ts.isSourceFile(item),
        );
        if (!declaration) return null;
        const relative = relativeInside(this.root, declaration.fileName);
        if (relative === null || belowNodeModules(relative)) return null;
        return reference("module", relative);
    }

    symbolReference(input, hint = "class") {
        const symbol = unalias(this.checker, input);
        const declaration = symbol?.declarations?.find(
            (item) =>
                relativeInside(this.root, item.getSourceFile().fileName) !==
                null,
        );
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

    addNode(
        id,
        kind,
        canonicalName,
        displayName,
        node,
        attributes = {},
        origin = "ast",
    ) {
        this.accumulator.addNode(
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
        fileExists: (file) =>
            allowedCompilerPath(root, file) && ts.sys.fileExists(file),
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
        pending.push(...(referenced.projectReferences ?? []));
    }
    parsed.fileNames = [...fileNames].map(offeredComponentPath);
    parsed.ownFileNames = ownFileNames.map(offeredComponentPath);
    // References are kept, and the host resolves a reference's build output
    // back to its source (see createRestrictedProgram), so an import of
    // `../lib/dist/index.js` or a package.json `imports` alias onto it lands
    // on lib/src without the project ever having been built.
    return parsed;
}

/**
 * Read a file for the compiler within the byte cap, decoded as TypeScript
 * decodes it, recording the read under its walk's keys: the hash of the raw
 * bytes read, or null when the read failed or went over the cap. A default
 * library file is exempt from the cap and never recorded, since discovery
 * never reports one.
 */
function readRecorded(root, file, reads, maxFileBytes) {
    const normalized = realSourcePath(normalize(path.resolve(file)));
    const library = contains(defaultLibDirectory(), normalized);
    let buffer;
    try {
        buffer = readBounded(
            normalized,
            library ? Number.MAX_SAFE_INTEGER : maxFileBytes,
        );
    } catch (error) {
        rethrowStackOverflow(error);
        buffer = undefined;
    }
    if (!library)
        recordWalked(
            reads,
            root,
            walkPath(normalized),
            buffer === undefined
                ? null
                : createHash("sha256").update(buffer).digest("hex"),
            maxFileBytes,
        );
    return buffer === undefined ? undefined : decodeLikeTypeScript(buffer);
}

/**
 * Decode a file's bytes into the string ts.sys.readFile would return, so
 * reading the buffer ourselves (to hash it) changes nothing the compiler sees.
 * Mirrors TypeScript 6.0's node `readFile`: UTF-16 BE and LE byte-order marks
 * decode as UTF-16, a UTF-8 BOM is dropped, anything else is UTF-8.
 */
function decodeLikeTypeScript(buffer) {
    if (buffer.length >= 2 && buffer[0] === 0xfe && buffer[1] === 0xff) {
        // Copied before swapping: the caller's buffer is what was hashed.
        const swapped = Buffer.from(buffer.subarray(0, buffer.length & ~1));
        swapped.swap16();
        return swapped.toString("utf16le", 2);
    }
    if (buffer.length >= 2 && buffer[0] === 0xff && buffer[1] === 0xfe) {
        return buffer.toString("utf16le", 2);
    }
    if (
        buffer.length >= 3 &&
        buffer[0] === 0xef &&
        buffer[1] === 0xbb &&
        buffer[2] === 0xbf
    ) {
        return buffer.toString("utf8", 3);
    }
    return buffer.toString("utf8");
}

/**
 * Read, hash and parse one file in a single read, so the hash is over exactly
 * the bytes the SourceFile was built from, and record the read for the request.
 * Returns undefined when the file cannot be read, the same "skip this input"
 * signal ts.sys.readFile gives.
 *
 * The path is resolved once, by walkPath, and that one walk decides everything:
 * whether the file may be read (it must end at a file inside the root, or be a
 * default-library file), the path the bytes are read from (the file the walk
 * ended at), and the key the read goes under (inputKeyLocation). Discovery
 * never follows a symlink, so a linked name is not a path the core tracks,
 * while the file its bytes came from is. A tsconfig `include` walks through
 * links (and `preserveSymlinks` keeps linked import paths), so both names do
 * reach this host. A path that is refused here is not read at all; the compiler
 * goes on as if the file did not exist, which changes the facts of every file
 * importing it, so it is recorded as a failed read under the same key.
 *
 * The tree can still change between the walk and the read, and the read is
 * still verified. A read that fails is recorded as null. A read that opens a
 * file other than the one the walk reached, because a component became a link
 * in between, hashes the bytes it actually got and records that hash under the
 * walk's keys, where it disagrees with what discovery hashed for the keyed path
 * unless the bytes are the same, in which case so are the facts. The read is
 * bounded to one byte past the cap for the same reason: whatever it opens, it
 * never reads more than a file the host would accept.
 */
function readHashedSourceFile(
    root,
    readPath,
    fileName,
    languageVersion,
    scriptKind,
    reads,
    maxFileBytes,
) {
    const absolute = normalize(path.resolve(readPath));
    reads.observe("read", absolute);
    const walked = walkPath(absolute);
    const library = contains(defaultLibDirectory(), absolute);
    if (
        walked.kind !== "file" ||
        !(contains(root, walked.location) || library)
    ) {
        recordWalked(reads, root, walked, null, maxFileBytes);
        return undefined;
    }
    let buffer;
    try {
        // Default-library declaration files are exempt from the cap, as in
        // exceedsByteCap.
        buffer = readBounded(
            walked.location,
            library ? Number.MAX_SAFE_INTEGER : maxFileBytes,
        );
    } catch (error) {
        rethrowStackOverflow(error);
        buffer = undefined;
    }
    if (buffer === undefined) {
        recordWalked(reads, root, walked, null, maxFileBytes);
        return undefined;
    }
    const contentHash = createHash("sha256").update(buffer).digest("hex");
    const decoded = decodeLikeTypeScript(buffer);
    const component = componentSource(readPath, decoded);
    const sourceFile = ts.createSourceFile(
        fileName,
        component?.text ?? decoded,
        component === undefined
            ? languageVersion
            : asComponentModule(languageVersion),
        true,
        scriptKind,
    );
    if (component !== undefined) componentSources.set(sourceFile, component);
    parsedContentHashes.set(sourceFile, contentHash);
    reads.created(sourceFile);
    recordWalked(reads, root, walked, contentHash, maxFileBytes);
    return sourceFile;
}

/**
 * The virtual source a component is parsed from, or undefined for any other
 * file. One that cannot be delimited is parsed as blank text, so it keeps its
 * module node, and says why.
 */
function componentSource(readPath, decoded) {
    const dialect = componentDialect(readPath);
    if (dialect === null) return undefined;
    try {
        return { dialect, ...toVirtualSource(decoded, dialect) };
    } catch (error) {
        rethrowStackOverflow(error);
        return {
            dialect,
            text: blankSource(decoded),
            scriptRanges: [],
            templateRanges: [],
            typed: false,
            unparsed: errorMessage(error),
        };
    }
}

/**
 * Parse options that make a component a module whatever its script says.
 *
 * A `<script setup>` that neither imports nor exports reads to the compiler as
 * a global script, and an import of the component then resolves to nothing.
 * Every component is a module to its bundler, with its compiled component as
 * the default export.
 */
function asComponentModule(languageVersion) {
    const options =
        typeof languageVersion === "object"
            ? languageVersion
            : { languageVersion };
    return {
        ...options,
        setExternalModuleIndicator: (file) => {
            file.externalModuleIndicator = true;
        },
    };
}

/**
 * Read a file's bytes, or undefined when it holds more than `maxBytes`: at most
 * one byte past the cap is ever read.
 *
 * Throws for anything but a regular file. Opening a FIFO for reading blocks
 * until a writer appears, and a walk reports any non-directory as a file, so
 * the path is opened without blocking (which changes nothing for a regular
 * file) and the handle, not the path, is checked, so a path swapped for a FIFO
 * after a check of it cannot slip through.
 */
function readBounded(file, requestedMaxBytes) {
    const maxBytes = byteCapFor(file, requestedMaxBytes);
    const handle = fs.openSync(
        file,
        fs.constants.O_RDONLY | fs.constants.O_NONBLOCK,
    );
    try {
        if (!fs.fstatSync(handle).isFile())
            throw new Error(`Not a regular file: ${file}`);
        const chunks = [];
        let total = 0;
        const limit = maxBytes + 1;
        while (total < limit) {
            const chunk = Buffer.alloc(Math.min(65_536, limit - total));
            const read = fs.readSync(handle, chunk, 0, chunk.length, null);
            if (read === 0) break;
            chunks.push(
                read === chunk.length ? chunk : chunk.subarray(0, read),
            );
            total += read;
        }
        return total > maxBytes ? undefined : Buffer.concat(chunks, total);
    } finally {
        fs.closeSync(handle);
    }
}

/**
 * The key a read of `absolute` goes under in `input_hashes`, or null for a read
 * the core cannot track: the root itself, anything outside it, or the default
 * library, which is the worker's own and never the project's, even under a
 * root that happens to contain the worker.
 *
 * A path below node_modules is keyed like any other. Discovery never hashes
 * one, so the core re-reads it when the scan commits: declarations resolved
 * through a dependency decide the facts of every file importing it.
 */
function inputHashKey(root, absolute) {
    const relative = relativeInside(root, absolute);
    if (
        relative === null ||
        relative === "" ||
        contains(defaultLibDirectory(), absolute)
    ) {
        return null;
    }
    return relative;
}

/**
 * The keys a walk goes under in `input_hashes`.
 *
 * `final` is the key of the location the walk reached (inputKeyLocation).
 * `linked` holds every other in-root key the walk passed through a link: each
 * link followed, and each link with the components that were still to walk
 * below it. The first of those is the path as written, which the host always
 * receives normalised, without a `..`. A `..` among the components below a
 * later link could only be applied by the walk, so such a path is left out, as
 * is anything inputHashKey refuses: the root itself, anything outside it, and
 * the default library.
 *
 * Discovery never reports a link and never descends into a linked directory,
 * so on a stable tree every linked key names a path discovery did not hash.
 * Mid-scan, a discovered file swapped for a link (to another file, to a
 * directory, or on a package's resolved path) is keyed where discovery saw it,
 * so the read or probe that went through it is checked against discovery's
 * hash.
 *
 * The core re-reads every key discovery did not hash when the scan commits,
 * and fails the scan when two requests, or two programs, report one key with
 * two values. So a key's value must be what that path itself names on a stable
 * tree, whichever read or probe reported it. Linked keys come in two kinds:
 *
 * - `through`: the path as written, and a link with the components below it.
 *   Each resolves exactly as the walk does, so it takes the walk's value.
 * - `directories`: a link the walk followed with components still to apply
 *   below it. It never names the file the walk reached: a hash of that file
 *   would differ from one read below it to the next. Its value is the link's
 *   own (see recordLinked): null for a directory or nothing, and the hash of
 *   the file it leads to when the walk failed below it because it is a file.
 *
 * A link followed as the last component is a `through` key even when the walk
 * ends at a directory or at nothing; a read of either records null anyway.
 *
 * @returns {{final: string|null, through: string[], directories: string[]}}
 */
function walkKeys(root, walked) {
    const location = inputKeyLocation(root, walked);
    const final = location === null ? null : inputHashKey(root, location);
    const through = new Set();
    const directories = new Set();
    const add = (set, candidate) => {
        const key = inputHashKey(root, candidate);
        if (key !== null && key !== final) set.add(key);
    };
    for (const link of walked.links) {
        if (!contains(root, link.location)) continue;
        const rest = link.rest.filter((name) => name !== "" && name !== ".");
        if (rest.length === 0) {
            add(through, link.location);
            continue;
        }
        add(directories, link.location);
        if (!rest.includes(".."))
            add(through, `${link.location}/${rest.join("/")}`);
    }
    return { final, through: [...through], directories: [...directories] };
}

/** Record a read, hashed or failed, under every key its walk gives. */
function recordWalked(reads, root, walked, contentHash, maxFileBytes) {
    const keys = walkKeys(root, walked);
    if (keys.final !== null) reads.record(keys.final, contentHash);
    recordLinked(reads, root, walked, keys, contentHash, maxFileBytes);
}

/**
 * Record a walk's linked keys: its `through` keys with the value given, and its
 * `directories` keys with the value the link itself names, whatever was read
 * below it (see walkKeys).
 *
 * A walk that reached a file or a directory went through every such link as a
 * directory, so each is null. A walk that failed may have failed at the link's
 * own target, a file with more still to walk below it (ENOTDIR), and a
 * `/// <reference path="./lnk.ts/x.d.ts" />` does exactly that to a link a
 * read in another request opens as a file. So the link is walked again as
 * itself and keyed as a read of it would be: the hash of the in-root file it
 * leads to within the byte cap, or null.
 */
function recordLinked(
    reads,
    root,
    walked,
    { through, directories },
    throughValue,
    maxFileBytes,
) {
    for (const key of through) reads.record(key, throughValue);
    const traversed = walked.kind === "file" || walked.kind === "directory";
    for (const key of directories) {
        reads.record(
            key,
            traversed ? null : linkedFileHash(root, key, maxFileBytes),
        );
    }
}

/** The hash of the in-root file a project-relative path leads to, or null. */
function linkedFileHash(root, relative, maxFileBytes) {
    const walked = walkPath(`${root}/${relative}`);
    return walked.kind === "file" && contains(root, walked.location)
        ? boundedHash(walked.location, maxFileBytes)
        : null;
}

/** Record a path the host would not or could not read as a failed read. */
function recordRefused(reads, root, absolute, maxFileBytes) {
    recordWalked(reads, root, walkPath(absolute), null, maxFileBytes);
}

/**
 * Record an existence probe whose answer feeds facts, such as a module
 * resolution candidate or a realpath.
 *
 * A probe answering absent, or not a file, records null under every key its
 * walk gives: the compiler goes on as if the file were not there, so a
 * discovered file missing for that moment must fail verification.
 *
 * A probe answering present vouches for nothing at the location it reached,
 * which a read will record. Its linked keys still need a value, so that a
 * discovered path that had become a link is caught. That value must be the one
 * a read through the same path records (see walkKeys): a module resolution
 * probes a package's files through its link in one request, while a
 * `/// <reference path>` reads them through it in another, and the core fails
 * a scan whose requests disagree on a key. So when the walk ended at a file and
 * has `through` keys, the file is read within the byte cap and those keys get
 * its hash, or null when that read fails or goes over the cap, as a read of it
 * would. Anything else a present probe reached, a directory, gives them null.
 */
function recordProbe(reads, root, walked, present, maxFileBytes) {
    const keys = walkKeys(root, walked);
    if (!present && keys.final !== null) reads.record(keys.final, null);
    recordLinked(
        reads,
        root,
        walked,
        keys,
        present && walked.kind === "file" && keys.through.length > 0
            ? boundedHash(walked.location, maxFileBytes)
            : null,
        maxFileBytes,
    );
}

/** The hash of a file read within the byte cap, or null when that read fails. */
function boundedHash(file, maxFileBytes) {
    let buffer;
    try {
        buffer = readBounded(file, maxFileBytes);
    } catch (error) {
        rethrowStackOverflow(error);
        return null;
    }
    return buffer === undefined
        ? null
        : createHash("sha256").update(buffer).digest("hex");
}

// Linux's MAXSYMLINKS: one lookup follows at most this many links, and the
// next one fails it with ELOOP.
const MAX_SYMLINK_HOPS = 40;

/**
 * Resolve an absolute path the way the kernel's path lookup does, one
 * component at a time, so the result names the file a read of it opens.
 *
 * `current` is always a real directory. A `..` steps to its parent, which is
 * where the kernel's `..` goes once the links before it have been followed. A
 * symlink's target is put in front of the components still to walk, raw, so
 * its own `..` is applied the same way; nothing is ever collapsed as text.
 *
 * - "file": the walk reached a non-directory as its last component.
 * - "directory": the walk ended at a directory.
 * - "missing": a component does not exist, or is a non-directory with more to
 *   walk (ENOENT, ENOTDIR), and `location` is the path the read was to open
 *   (see absentBelow).
 * - "unresolvable": no such path can be named: a `..` left to apply below a
 *   missing component, another lookup error, or more than MAX_SYMLINK_HOPS
 *   links (ELOOP).
 *
 * `links` lists every symlink followed, in order, with the components that
 * were still to walk after it at that moment.
 *
 * @param {string} absolute
 * @returns {{kind: "file"|"missing"|"directory"|"unresolvable", location?: string, links: {location: string, rest: string[]}[]}}
 */
function walkPath(absolute) {
    const top = normalize(path.parse(absolute).root);
    const remaining = normalize(absolute).slice(top.length).split("/");
    const links = [];
    let current = top;
    let hops = 0;
    while (remaining.length > 0) {
        const name = remaining.shift();
        if (name === "" || name === ".") continue;
        if (name === "..") {
            current = parentDirectory(current, top);
            continue;
        }
        const candidate = current.endsWith("/")
            ? `${current}${name}`
            : `${current}/${name}`;
        let stat;
        try {
            stat = fs.lstatSync(candidate);
        } catch (error) {
            rethrowStackOverflow(error);
            return error?.code === "ENOENT"
                ? absentBelow(candidate, remaining, links)
                : { kind: "unresolvable", links };
        }
        if (stat.isSymbolicLink()) {
            if (++hops > MAX_SYMLINK_HOPS)
                return { kind: "unresolvable", links };
            links.push({ location: candidate, rest: [...remaining] });
            let target;
            try {
                target = normalize(fs.readlinkSync(candidate));
            } catch (error) {
                rethrowStackOverflow(error);
                return { kind: "unresolvable", links };
            }
            if (path.isAbsolute(target)) {
                current = normalize(path.parse(target).root);
                target = target.slice(current.length);
            }
            remaining.unshift(...target.split("/"));
        } else if (stat.isDirectory()) {
            current = candidate;
        } else if (remaining.length > 0) {
            return absentBelow(candidate, remaining, links);
        } else {
            return { kind: "file", location: candidate, links };
        }
    }
    return { kind: "directory", location: current, links };
}

/**
 * The walk's result when the lookup fails at `candidate` because it does not
 * exist, or is not a directory while more remains to walk. Nothing exists below
 * it, so nothing below it can be a link, and without a `..` still to apply the
 * remaining components name exactly the file the read was to open: a "missing"
 * location. A `..` still to apply could only be resolved against a directory
 * that is not there, so the path is unresolvable.
 */
function absentBelow(candidate, remaining, links) {
    const rest = remaining.filter((name) => name !== "" && name !== ".");
    if (rest.includes("..")) return { kind: "unresolvable", links };
    return { kind: "missing", location: [candidate, ...rest].join("/"), links };
}

/**
 * The kernel's resolution of an existing path, for the root and requested files,
 * which must exist. Node's JavaScript fs.realpathSync collapses `..` in a link
 * target as text first; the native one does not.
 */
function realpathNative(candidate) {
    return normalize(fs.realpathSync.native(candidate));
}

/** The parent of a real directory; a filesystem root is its own parent. */
function parentDirectory(directory, top) {
    if (directory === top) return top;
    const parent = directory.slice(0, directory.lastIndexOf("/"));
    return parent.length < top.length ? top : parent;
}

/**
 * The absolute location a walked read goes under in `input_hashes`, or null
 * when no location the walk passed lies inside the root.
 *
 * - A walk that ended at a file, a missing location or a directory inside the
 *   root: that location, the path the read opened or would have opened. A
 *   directory read fails (EISDIR) and is recorded as null, which the core
 *   accepts at commit for a path that is not a regular file, while a
 *   discovered file replaced by one mid-scan fails verification.
 * - Otherwise the last link followed inside the root. A link followed as a
 *   directory component keys the path below it as it was about to be walked
 *   (a discovered `src/sub/c.ts` whose `src/sub` became a link out of the root
 *   keys `src/sub/c.ts`), unless that path holds a `..`, which only the walk
 *   could have applied; then the link itself.
 *
 * Every key a stable layout produces this way names either the file the
 * kernel reaches, or a link or a path through one, which discovery never
 * reports. The core verifies such an undiscovered key when the scan commits: a
 * hash must still match the in-root regular file within the cap that the path
 * names, and a null is valid only while the path is absent, not a regular
 * file, a link, or over the cap. A discovered file changed into one of those
 * mid-scan is keyed where discovery saw it, so it fails verification against
 * discovery's hash.
 */
function inputKeyLocation(root, walked) {
    if (walked.kind !== "unresolvable" && contains(root, walked.location)) {
        return walked.location;
    }
    const link = walked.links.findLast((entry) =>
        contains(root, entry.location),
    );
    if (link === undefined) return null;
    const rest = link.rest.filter((name) => name !== "" && name !== ".");
    if (rest.length === 0 || rest.includes("..")) return link.location;
    return `${link.location}/${rest.join("/")}`;
}

/**
 * Record null for every project file a program holds that this request did not
 * create from its own read.
 *
 * TypeScript 6.0 asks the host for every file again when it reuses an old
 * program's structure, and this host always returns a fresh SourceFile, so a
 * reused program holds only this request's reads and this finds nothing. It is
 * here so that a compiler that kept an earlier request's SourceFile would make
 * that file's facts unverifiable rather than vouched for by the earlier read.
 */
function recordUnreadSourceFiles(root, program, reads, maxFileBytes) {
    for (const sourceFile of program.getSourceFiles()) {
        if (reads.createdThisRequest(sourceFile)) continue;
        const absolute = normalize(
            path.resolve(realSourcePath(sourceFile.fileName)),
        );
        // The hash its facts were parsed from, bound to the object, when this
        // worker created it at all; null only when nothing describes it.
        recordWalked(
            reads,
            root,
            walkPath(absolute),
            parsedContentHashes.get(sourceFile) ?? null,
            maxFileBytes,
        );
    }
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
        if (!allowedCompilerPath(root, config) || !isRegularFile(config))
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

/** The initializer of `key` in an object literal, or undefined. */
function objectField(node, key) {
    if (!ts.isObjectLiteralExpression(node)) return undefined;
    return node.properties.find(
        (property) =>
            ts.isPropertyAssignment(property) &&
            staticPropertyName(property.name) === key,
    )?.initializer;
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

/** `require.context('<literal>', …)`, webpack's directory import. */
function isRequireContext(node) {
    const callee = node.expression;
    return (
        ts.isPropertyAccessExpression(callee) &&
        ts.isIdentifier(callee.expression) &&
        callee.expression.text === "require" &&
        callee.name.text === "context" &&
        node.arguments.length >= 1 &&
        ts.isStringLiteralLike(node.arguments[0])
    );
}

/**
 * The source and flags of a regular expression literal, or null. The
 * stateful `g` and `y` flags are dropped: webpack tests each key on its own.
 */
function regularExpressionOf(node) {
    if (!ts.isRegularExpressionLiteral(node)) return null;
    const match = /^\/(.*)\/([a-z]*)$/s.exec(node.text);
    if (match === null) return null;
    const flags = match[2].replace(/[gy]/g, "");
    try {
        new RegExp(match[1], flags);
    } catch {
        return null;
    }
    return { source: match[1], flags };
}

/**
 * Options Vue, vue-router, vue-meta and Nuxt call on a component themselves.
 */
const VUE_HOOKS = new Set([
    "data",
    "setup",
    "render",
    "beforeCreate",
    "created",
    "beforeMount",
    "mounted",
    "beforeUpdate",
    "updated",
    "beforeDestroy",
    "destroyed",
    "beforeUnmount",
    "unmounted",
    "activated",
    "deactivated",
    "errorCaptured",
    "renderTracked",
    "renderTriggered",
    "serverPrefetch",
    "beforeRouteEnter",
    "beforeRouteUpdate",
    "beforeRouteLeave",
    "metaInfo",
    "head",
    "asyncData",
    "fetch",
]);

/**
 * The options object a Vue component exports: `export default { … }`, or the
 * object passed to `defineComponent(…)` / `Vue.extend(…)` there.
 */
function vueOptionsObject(sourceFile) {
    const exported = sourceFile.statements.find(
        (statement) =>
            ts.isExportAssignment(statement) && !statement.isExportEquals,
    )?.expression;
    if (exported === undefined) return undefined;
    if (ts.isObjectLiteralExpression(exported)) return exported;
    const argument = ts.isCallExpression(exported)
        ? exported.arguments[0]
        : undefined;
    return argument !== undefined && ts.isObjectLiteralExpression(argument)
        ? argument
        : undefined;
}

/** An object literal member's static name, or null. */
function memberName(member) {
    return member.name === undefined ? null : staticPropertyName(member.name);
}

/** A property name written as an identifier or a string, or null. */
function staticPropertyName(name) {
    return ts.isIdentifier(name) || ts.isStringLiteral(name) ? name.text : null;
}

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

function defaultLibDirectory() {
    return normalize(path.dirname(ts.getDefaultLibFilePath({})));
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
    };
    const host = ts.createCompilerHost(options, true);
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
    host.fileExists = (file) => {
        const absolute = normalize(path.resolve(realSourcePath(file)));
        if (contains(defaultLibDirectory(), absolute))
            return ts.sys.fileExists(absolute);
        if (!contains(root, absolute)) return false;
        const walked = walkPath(absolute);
        const present =
            walked.kind === "file" && contains(root, walked.location);
        recordProbe(reads, root, walked, present, maxFileBytes);
        return present;
    };
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
    );
    return ts.createProgram({
        rootNames: parsed.fileNames,
        options,
        projectReferences: parsed.projectReferences,
        host,
        oldProgram,
    });
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
function componentResolver(host, cache, vueProject) {
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
            const resolved = componentTarget(
                literal.text,
                resolve(literal.text),
            );
            if (
                !vueProject ||
                resolved.resolvedModule !== undefined ||
                !literal.text.includes("/") ||
                path.posix.extname(literal.text) !== ""
            )
                return resolved;
            const component = resolve(`${literal.text}.vue`);
            return component.resolvedModule !== undefined
                ? component
                : resolved;
        });
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

function diagnosticsForProgram(program, root, maxFileBytes) {
    const result = new Map();
    for (const diagnostic of ts.getPreEmitDiagnostics(program)) {
        if (!diagnostic.file) continue;
        const component = componentSources.get(diagnostic.file);
        if (
            component !== undefined &&
            !componentDiagnosticKept(component, diagnostic)
        )
            continue;
        if (diagnostic.code === 6059) continue; // Analysis-only project-reference source merging triggers this.
        if (namesComponentDefaultExport(diagnostic)) continue;
        const relative = relativeInside(root, diagnostic.file.fileName);
        if (relative === null || belowNodeModules(relative)) continue;
        const overCap = declarationOverCap(
            program,
            diagnostic,
            root,
            maxFileBytes,
        );
        if (overCap !== null) {
            const start = diagnostic.file.getLineAndCharacterOfPosition(
                diagnostic.start ?? 0,
            );
            const list = result.get(relative) ?? [];
            list.push({
                severity: "warning",
                code: "TS_DECLARATION_OVER_CAP",
                message: overCap,
                evidence: {
                    path: relative,
                    start_line: start.line + 1,
                    end_line: start.line + 1,
                },
            });
            result.set(relative, list);
            continue;
        }
        const start = diagnostic.start ?? 0;
        const startPosition =
            diagnostic.file.getLineAndCharacterOfPosition(start);
        const endPosition = diagnostic.file.getLineAndCharacterOfPosition(
            start + (diagnostic.length ?? 0),
        );
        const item = {
            severity:
                diagnostic.category === ts.DiagnosticCategory.Error
                    ? "error"
                    : "warning",
            code: `TS${diagnostic.code}`,
            message: ts.flattenDiagnosticMessageText(
                diagnostic.messageText,
                "\n",
            ),
            evidence: {
                path: relative,
                start_line: startPosition.line + 1,
                end_line: Math.max(
                    startPosition.line + 1,
                    endPosition.line + 1,
                ),
            },
        };
        const list = result.get(relative) ?? [];
        list.push(item);
        result.set(relative, list);
    }
    componentParseDiagnostics(program, root, result);
    return result;
}

/**
 * `Module "X.vue" has no default export`: a component's default export is
 * the component its bundler compiles, which its virtual source never spells.
 */
function namesComponentDefaultExport(diagnostic) {
    if (diagnostic.code !== 1192) return false;
    const text = ts.flattenDiagnosticMessageText(diagnostic.messageText, "\n");
    const module = /Module '"([^"]+)"'/.exec(text)?.[1];
    return module !== undefined && componentDialect(module) !== null;
}

/** A `COMPONENT_UNPARSED` warning for each component that could not be read. */
function componentParseDiagnostics(program, root, result) {
    for (const sourceFile of program.getSourceFiles()) {
        const component = componentSources.get(sourceFile);
        const relative = relativeInside(root, sourceFile.fileName);
        if (component?.unparsed === undefined || relative === null) continue;
        const list = result.get(relative) ?? [];
        list.push({
            severity: "warning",
            code: "COMPONENT_UNPARSED",
            message: `${relative} was not read as a ${component.dialect} component: ${component.unparsed}`,
            evidence: { path: relative, start_line: 1, end_line: 1 },
        });
        result.set(relative, list);
    }
}

/**
 * Why a `Cannot find module` is not what it says, or null when it is.
 *
 * A file over the byte cap is refused to bound memory, and a package whose
 * declaration file is that large resolves perfectly well: the compiler just
 * never receives it, and reports the import as missing. Read as a missing
 * dependency, that sends someone to install a package they already have.
 */
function declarationOverCap(program, diagnostic, root, maxFileBytes) {
    if (diagnostic.code !== 2307) return null;
    const text = ts.flattenDiagnosticMessageText(diagnostic.messageText, "\n");
    const name = /Cannot find module '([^']+)'/.exec(text)?.[1];
    if (name === undefined) return null;
    let resolved;
    program.forEachResolvedModule((resolution, moduleName) => {
        if (moduleName === name)
            resolved ??= resolution.resolvedModule?.resolvedFileName;
    }, diagnostic.file);
    if (resolved === undefined || !exceedsByteCap(resolved, maxFileBytes))
        return null;
    const shown = relativeInside(root, resolved) ?? resolved;
    return `Module '${name}' resolves to ${shown}, which is over the ${maxFileBytes}-byte per-file cap, so the scan did not read it and names imported from it are unresolved.`;
}

function declarationDescriptor(node, sourceFile) {
    const name = declarationName(node, sourceFile);
    if (name === null) return null;
    const kind = declarationKind(node, "class");
    const modifiers = declarationModifiers(node);
    return {
        kind,
        name,
        attributes: {
            exported: modifiers.some(
                (modifier) => modifier.kind === ts.SyntaxKind.ExportKeyword,
            ),
            default: modifiers.some(
                (modifier) => modifier.kind === ts.SyntaxKind.DefaultKeyword,
            ),
            abstract: modifiers.some(
                (modifier) => modifier.kind === ts.SyntaxKind.AbstractKeyword,
            ),
            static: modifiers.some(
                (modifier) => modifier.kind === ts.SyntaxKind.StaticKeyword,
            ),
            ...(isFunctionBinding(node)
                ? { binding: bindingKeyword(node) }
                : {}),
        },
    };
}

function declarationKind(node, fallback) {
    if (ts.isClassDeclaration(node) || ts.isClassExpression(node))
        return "class";
    if (ts.isInterfaceDeclaration(node)) return "interface";
    if (ts.isEnumDeclaration(node)) return "enum";
    if (ts.isTypeAliasDeclaration(node)) return "type_alias";
    if (ts.isModuleDeclaration(node)) return "namespace";
    if (ts.isFunctionDeclaration(node) || ts.isFunctionExpression(node))
        return "function";
    if (
        ts.isMethodDeclaration(node) ||
        ts.isMethodSignature(node) ||
        ts.isConstructorDeclaration(node)
    )
        return "method";
    if (ts.isPropertyDeclaration(node) || ts.isPropertySignature(node))
        return "property";
    if (isFunctionBinding(node)) return "function";
    if (isObjectLiteralBinding(node)) return "variable";
    if (isContextualObjectLiteral(node)) return "object";
    return fallback;
}

function declarationName(node, sourceFile) {
    if (ts.isConstructorDeclaration(node)) return "constructor";
    if (
        node.name &&
        (ts.isIdentifier(node.name) ||
            ts.isStringLiteral(node.name) ||
            ts.isNumericLiteral(node.name))
    ) {
        return node.name.text;
    }
    if (
        (ts.isClassDeclaration(node) ||
            ts.isClassExpression(node) ||
            ts.isFunctionDeclaration(node) ||
            isContextualObjectLiteral(node)) &&
        !node.name
    ) {
        // Include the column so minified single-line bundles don't collapse
        // every anonymous entity onto the same `@line` key.
        const position = sourceFile.getLineAndCharacterOfPosition(
            node.getStart(sourceFile),
        );
        const label = ts.isObjectLiteralExpression(node)
            ? "{object}"
            : "{anonymous}";
        return `${label}@${position.line + 1}:${position.character + 1}`;
    }
    return null;
}

// Inside `declare global { ... }` or `declare module 'x' { ... }`: a
// description of something the runtime or another package defines, which is
// no more code than a `.d.ts` is. The block itself counts as inside.
function insideAmbientDeclaration(node) {
    for (let current = node; current; current = current.parent) {
        if (
            ts.isModuleDeclaration(current) &&
            ((current.flags & ts.NodeFlags.GlobalAugmentation) !== 0 ||
                ts.isStringLiteral(current.name))
        )
            return true;
    }
    return false;
}

function isDeclaration(node) {
    // A member of an inline `{ ... }` type describes a shape, not code, and
    // has no name of its own to be declared under.
    if (
        (ts.isMethodSignature(node) || ts.isPropertySignature(node)) &&
        ts.isTypeLiteralNode(node.parent)
    )
        return false;
    return (
        ts.isClassDeclaration(node) ||
        ts.isClassExpression(node) ||
        ts.isInterfaceDeclaration(node) ||
        ts.isEnumDeclaration(node) ||
        ts.isTypeAliasDeclaration(node) ||
        ts.isModuleDeclaration(node) ||
        ts.isFunctionDeclaration(node) ||
        ts.isMethodDeclaration(node) ||
        ts.isMethodSignature(node) ||
        ts.isConstructorDeclaration(node) ||
        ts.isPropertyDeclaration(node) ||
        ts.isPropertySignature(node) ||
        isFunctionBinding(node) ||
        isObjectLiteralBinding(node) ||
        isContextualObjectLiteral(node)
    );
}

/**
 * `const charon: View = { query() {} }`: a binding whose object literal
 * declares methods, which is how TypeScript writes an implementation without a
 * class. The binding is the container its methods belong to, so they are named
 * after it rather than after the module, and two literals in one file cannot
 * share a member name; and its declared or `satisfies` type is the contract it
 * implements, which is what reaches those methods from a caller typed as it.
 */
function isObjectLiteralBinding(node) {
    if (!ts.isVariableDeclaration(node) || !ts.isIdentifier(node.name))
        return false;
    const literal = objectLiteralOf(node.initializer);
    return (
        literal !== null &&
        literal.properties.some((member) => ts.isMethodDeclaration(member))
    );
}

/**
 * An object literal with methods that no binding names: passed as an argument
 * (`register({ handle() {} })`, `new Proxy(t, { get() {} })`), returned, or
 * nested in another literal. It implements the type of the place it is passed
 * to, so it is a container of its own, named by position, with that type as
 * its contract. A literal that initialises a binding is the binding's.
 */
function isContextualObjectLiteral(node) {
    if (
        !ts.isObjectLiteralExpression(node) ||
        !node.properties.some((member) => ts.isMethodDeclaration(member))
    )
        return false;
    let current = node.parent;
    while (
        current !== undefined &&
        (ts.isParenthesizedExpression(current) ||
            ts.isAsExpression(current) ||
            ts.isSatisfiesExpression(current))
    )
        current = current.parent;
    return !(
        current !== undefined &&
        ts.isVariableDeclaration(current) &&
        isObjectLiteralBinding(current)
    );
}

// The object literal an initializer evaluates to, through parentheses, `as`
// and `satisfies`, or null.
function objectLiteralOf(expression) {
    let current = expression;
    while (
        current !== undefined &&
        (ts.isParenthesizedExpression(current) ||
            ts.isAsExpression(current) ||
            ts.isSatisfiesExpression(current))
    )
        current = current.expression;
    return current !== undefined && ts.isObjectLiteralExpression(current)
        ? current
        : null;
}

// The type an object-literal binding declares it implements: its annotation,
// or the nearest `satisfies`.
function objectLiteralContract(node) {
    if (node.type !== undefined) return node.type;
    let current = node.initializer;
    while (
        current !== undefined &&
        (ts.isParenthesizedExpression(current) ||
            ts.isAsExpression(current) ||
            ts.isSatisfiesExpression(current))
    ) {
        if (ts.isSatisfiesExpression(current)) return current.type;
        current = current.expression;
    }
    return undefined;
}

function containerDeclaration(node) {
    return (
        isFunctionBinding(node) ||
        isObjectLiteralBinding(node) ||
        isContextualObjectLiteral(node) ||
        ts.isClassDeclaration(node) ||
        ts.isClassExpression(node) ||
        ts.isInterfaceDeclaration(node) ||
        ts.isModuleDeclaration(node) ||
        ts.isFunctionDeclaration(node) ||
        ts.isMethodDeclaration(node) ||
        ts.isConstructorDeclaration(node)
    );
}

function memberDeclaration(node) {
    return (
        ts.isMethodDeclaration(node) ||
        ts.isMethodSignature(node) ||
        ts.isConstructorDeclaration(node) ||
        ts.isPropertyDeclaration(node) ||
        ts.isPropertySignature(node)
    );
}

function canonicalForDeclaration(declaration, relative) {
    // Mirror the container stack used when the node is DECLARED (see
    // `declaration()`): only class/interface/module/function/method ancestors
    // contribute to the canonical path. Climbing every named ancestor (variable
    // declarations, property assignments, class expressions bound to a const)
    // built reference targets that no declared node ever emitted, so calls /
    // constructs / extends edges to members reached through named variables or
    // object literals dangled. Restricting to containers makes the reference
    // canonical identical to the declaration canonical.
    const sourceFile = declaration.getSourceFile();
    const ownName = declarationName(declaration, sourceFile);
    const containers = [];
    let current = declaration.parent;
    while (current && !ts.isSourceFile(current)) {
        if (containerDeclaration(current)) {
            const name = declarationName(current, sourceFile);
            if (name !== null) containers.unshift(name);
        }
        current = current.parent;
    }
    const containerPath =
        containers.length > 0
            ? `${relative}#${containers.join(".")}`
            : relative;
    if (memberDeclaration(declaration)) {
        return `${containerPath}::${ownName ?? "{anonymous}"}`;
    }
    const prefix = containers.length > 0 ? `${containers.join(".")}.` : "";
    return `${relative}#${prefix}${ownName ?? ""}`;
}

/**
 * Declarations a value reference is allowed to point at: the callables and
 * types dead-code analysis reasons about. Variables, parameters, properties and
 * imports are excluded — an edge per local read would dominate the graph without
 * telling us anything about reachability. The variables admitted are an
 * object-literal binding with methods, which the graph holds as a component
 * (see {@link isObjectLiteralBinding}) and which is used by being handed around,
 * and a module-level function binding (see `isFunctionBinding`).
 */
function referenceableDeclaration(node) {
    return (
        ts.isFunctionDeclaration(node) ||
        ts.isMethodDeclaration(node) ||
        ts.isClassDeclaration(node) ||
        ts.isInterfaceDeclaration(node) ||
        ts.isEnumDeclaration(node) ||
        ts.isTypeAliasDeclaration(node) ||
        isFunctionBinding(node) ||
        isObjectLiteralBinding(node)
    );
}

/** A declaration's attributes, marked `ambient` inside `declare global` / `declare module`. */
function ambientAttributes(node, attributes) {
    return insideAmbientDeclaration(node)
        ? { ...attributes, ambient: true }
        : attributes;
}

/** An identifier that is the whole of a statement or of a parenthesised expression. */
function isBareValue(parent, node) {
    return (
        (ts.isExpressionStatement(parent) ||
            ts.isParenthesizedExpression(parent)) &&
        parent.expression === node
    );
}

/** A member, property or attribute name, or an intrinsic JSX tag: never a binding. */
function isNamePosition(node) {
    const parent = node.parent;
    return (
        (ts.isPropertyAccessExpression(parent) && parent.name === node) ||
        (ts.isPropertyAssignment(parent) && parent.name === node) ||
        ts.isJsxAttribute(parent) ||
        ((ts.isJsxOpeningElement(parent) ||
            ts.isJsxSelfClosingElement(parent) ||
            ts.isJsxClosingElement(parent)) &&
            /^[a-z]/.test(node.text))
    );
}

/**
 * `input.run ?? defaultRun`, `a || b` and `flag ? a : b`: a fallback or a
 * choice between functions, either of which may be the one that runs.
 */
function isChoiceOperand(parent, node) {
    if (ts.isConditionalExpression(parent))
        return parent.whenTrue === node || parent.whenFalse === node;
    return (
        ts.isBinaryExpression(parent) &&
        [
            ts.SyntaxKind.QuestionQuestionToken,
            ts.SyntaxKind.BarBarToken,
            ts.SyntaxKind.AmpersandAmpersandToken,
        ].includes(parent.operatorToken.kind)
    );
}

/**
 * True when an identifier stands in one of the value positions where a callable
 * or type is handed around rather than invoked.
 *
 * Deliberately an ALLOW-list keyed on the immediate parent, not a deny-list.
 * Two reasons. Correctness: every listed position is a value position by
 * construction, so type nodes, declaration names, member names, import/export
 * specifiers and binding patterns can never reach the checker. Cost: this
 * predicate runs for every identifier in every file, and the checker call it
 * guards is the expensive part — a deny-list left the common cases (local reads,
 * property names) falling through to `getSymbolAtLocation`, which made a full
 * scan of a mid-sized project exceed the worker timeout.
 *
 * A JSX tag name is on the list because it is the ONLY way a React component is
 * ever used. Without it a component was reached by nothing the graph could see:
 * a scan of a 588-file React project reported fourteen live components as
 * unreferenced, every one of them used solely as `<Component />`. An intrinsic
 * tag like `<div>` resolves to a property signature in the DOM library, which
 * {@link referenceableDeclaration} rejects, so the common case costs one
 * checker call and emits nothing.
 */
function valueReferencePosition(node) {
    const parent = node.parent;
    if (!parent) return false;

    // `[a, b]` — registry / dispatch-table arrays.
    if (ts.isArrayLiteralExpression(parent)) return true;
    // `{ key: handler }` and the `{ handler }` shorthand.
    if (ts.isPropertyAssignment(parent) && parent.initializer === node)
        return true;
    if (ts.isShorthandPropertyAssignment(parent)) return true;
    // `register(handler)` — a callback argument, never the callee (which is
    // `parent.expression` and already yields a `calls` / `constructs` edge).
    if (
        (ts.isCallExpression(parent) || ts.isNewExpression(parent)) &&
        parent.arguments?.includes(node)
    )
        return true;
    // `const run = handler;` / `private fn = handler;`, and a default:
    // `(row = DefaultRow) => ...` or `{ render = DefaultRow }`.
    if (
        (ts.isVariableDeclaration(parent) ||
            ts.isPropertyDeclaration(parent) ||
            ts.isParameter(parent) ||
            ts.isBindingElement(parent)) &&
        parent.initializer === node
    )
        return true;
    if (isChoiceOperand(parent, node)) return true;
    // `handler;` and `(handler)`: how a component's tags and event handlers
    // reach the checker (see component-source.js), and a value use anywhere.
    if (isBareValue(parent, node)) return true;
    // `<Button onClick={addItem}>` and `{renderRow}`: a function handed to
    // React inside JSX, as a prop or a child.
    if (ts.isJsxExpression(parent) && parent.expression === node) return true;
    // `return handler;` and `() => handler`
    if (ts.isReturnStatement(parent) && parent.expression === node) return true;
    if (ts.isArrowFunction(parent) && parent.body === node) return true;
    // `<Panel />` and `<Panel>…</Panel>` — a component rendered as a JSX
    // element. Only the tag name, and only on the opening form: the closing tag
    // names the same declaration and would resolve a second symbol for an edge
    // the accumulator immediately de-duplicates.
    if (
        (ts.isJsxSelfClosingElement(parent) ||
            ts.isJsxOpeningElement(parent)) &&
        parent.tagName === node
    )
        return true;
    // `mod.run` standing in any of the positions above: a facade republishing
    // an imported function as its own field, `const run = mod.run`, or an
    // object literal built the same way. Asked of the NAME half only, so the
    // object being read cannot answer for the member.
    //
    // Without this a facade hid everything it republished. A client class
    // assigning `getCustomers = customer.getCustomers` and imported by a dozen
    // screens left every one of those functions reachable from nothing but its
    // own test, which is the direction of wrongness that costs most: it invites
    // deleting code the application runs.
    //
    // The recursion walks a chain like `a.b.c` up to whatever encloses it and
    // ends there, because each step moves to the parent.
    if (ts.isPropertyAccessExpression(parent) && parent.name === node)
        return valueReferencePosition(parent);

    return false;
}

/**
 * Whether an import clause brings in nothing but types, so the statement is
 * erased before anything runs.
 *
 * Written out rather than inlined because the three ways to write one are easy
 * to get wrong, and one of them was: the previous expression read
 * `clause?.namedBindings && …`, which yields `undefined` — not `false` — for
 * `import Panel from './Panel'`, and an undefined attribute is dropped on the
 * way into the graph. Every default-only import therefore carried no
 * `type_only` at all, so a consumer could not tell a value import from one that
 * had never been marked.
 *
 * A default binding beside named type specifiers (`import D, { type T } from`)
 * is a value import: `D` survives compilation. An empty named list is not
 * type-only either, because `every()` on no elements is vacuously true and
 * `import D, {} from` would otherwise be erased on paper while `D` still runs.
 */
function importIsTypeOnly(clause) {
    if (clause === undefined) return false;
    if (clause.isTypeOnly === true) return true;
    const named = clause.namedBindings;
    return (
        clause.name === undefined &&
        named !== undefined &&
        ts.isNamedImports(named) &&
        named.elements.length > 0 &&
        named.elements.every((element) => element.isTypeOnly)
    );
}

function callableKind(declaration) {
    return declaration &&
        (ts.isMethodDeclaration(declaration) ||
            ts.isMethodSignature(declaration))
        ? "method"
        : "function";
}

function unalias(checker, symbol) {
    if (!symbol) return undefined;
    return (symbol.flags & ts.SymbolFlags.Alias) !== 0
        ? checker.getAliasedSymbol(symbol)
        : symbol;
}

function externalPackageName(specifier) {
    if (
        specifier.startsWith(".") ||
        specifier.startsWith("/") ||
        specifier.startsWith("#")
    )
        return null;
    const parts = specifier.split("/");
    return specifier.startsWith("@") ? parts.slice(0, 2).join("/") : parts[0];
}

function evidence(sourceFile, relative, node) {
    const start =
        sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile))
            .line + 1;
    const end =
        sourceFile.getLineAndCharacterOfPosition(node.getEnd()).line + 1;
    return {
        path: relative,
        start_line: start,
        end_line: Math.max(start, end),
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

function maxFileBytesFrom(limits) {
    return Number.isInteger(limits?.max_file_bytes)
        ? limits.max_file_bytes
        : 2_000_000;
}

// A dependency's declaration file may hold a whole framework's type surface
// (Phaser ships 6 MB in one file), and refusing it cost every class extending
// one of its types all of its inherited members. Declarations below
// node_modules get this multiple of the cap a project's own sources get. The
// core's commit-time check applies the same rule (UndiscoveredInputVerifier).
const DEPENDENCY_DECLARATION_CAP_FACTOR = 16;

function byteCapFor(file, maxBytes) {
    return /(?:^|\/)node_modules\/.+\.d\.[cm]?ts$/.test(normalize(file))
        ? maxBytes * DEPENDENCY_DECLARATION_CAP_FACTOR
        : maxBytes;
}

// Default-library declaration files are exempt: skipping one would break type
// resolution for every file. Only project sources under the root are capped.
function exceedsByteCap(fileName, maxFileBytes) {
    const normalized = realSourcePath(normalize(path.resolve(fileName)));
    if (contains(defaultLibDirectory(), normalized)) return false;
    try {
        return (
            fs.statSync(normalized).size > byteCapFor(normalized, maxFileBytes)
        );
    } catch (error) {
        rethrowStackOverflow(error);
        return false;
    }
}

/**
 * Whether a requested path is still an in-root regular file within the byte
 * cap, reached without following a link.
 */
function readableAsItself(root, relative, maxFileBytes) {
    const absolute = `${root}/${relative}`;
    const walked = walkPath(absolute);
    if (walked.kind !== "file" || walked.location !== absolute) return false;
    try {
        return fs.statSync(absolute).size <= maxFileBytes;
    } catch {
        return false;
    }
}

/**
 * A requested file the filesystem would not let the worker read as that file.
 * See validateRequestedFiles.
 */
class UnreadableInput extends Error {}

// A requested file refused because of what was read in it: an extensionless
// script whose shebang does not name JavaScript. Discovery routed it here
// because its reading of those bytes did, so a script swapped for another one
// and restored around the probe would otherwise lose its facts from a graph
// reported fresh. The refusal carries evidence for `input_hashes`: the hash of
// the whole file from one bounded read that reached the same verdict, which a
// stable tree matches, or null when that read failed, was over the cap, or
// found JavaScript after all.
class RefusedAfterRead extends Error {
    constructor(message, contentHash) {
        super(message);
        this.contentHash = contentHash;
    }
}

function errorMessage(error) {
    return error instanceof Error ? error.message : String(error);
}

function validateRequestedFiles(root, files, limits = {}) {
    if (
        !Array.isArray(files) ||
        files.some((file) => typeof file !== "string")
    ) {
        throw new Error(
            "TypeScript scan files must be a list of project-relative paths.",
        );
    }
    const maxFiles = Number.isInteger(limits?.max_files)
        ? limits.max_files
        : 100_000;
    const maxFileBytes = maxFileBytesFrom(limits);
    if (maxFiles < 1 || maxFileBytes < 1 || files.length > maxFiles)
        throw new Error("TypeScript scan limits are invalid or exceeded.");

    // A path this worker refuses, or a file that vanished between discovery and
    // scan, is reported per file rather than raised: only a request that cannot
    // be interpreted at all (checked above) is fatal.
    //
    // A rejection is either a refusal by policy (an extension or shebang this
    // worker does not scan), which says nothing about the tree, or a read the
    // filesystem refused: the path is gone, is not a regular file, is over the
    // byte cap, or resolves somewhere other than where it was requested. The
    // second kind is marked `failedRead`, because the facts-free contribution
    // standing in for the file must not pass verification as if it had been
    // read.
    const accepted = [];
    const rejected = [];
    for (const relative of files) {
        // A malformed path stays fatal: it names no file, so there is nothing to
        // attribute a diagnostic to, and echoing it into a contribution would
        // emit an owner key the graph rejects anyway.
        assertScannablePath(relative);
        const requested = normalize(relative);
        try {
            let absolute;
            try {
                absolute = validatedInside(root, relative);
            } catch (error) {
                throw new UnreadableInput(errorMessage(error));
            }
            // Discovery never follows a link, so a requested path always names
            // its own file. One that now resolves elsewhere is read as another
            // file, whose facts must not stand in for this one's, nor be
            // emitted under the other file's key.
            if (normalize(path.relative(root, absolute)) !== requested)
                throw new UnreadableInput(
                    `TypeScript input no longer resolves to itself: ${relative}`,
                );
            if (
                !SOURCE_EXTENSIONS.has(path.extname(absolute).toLowerCase()) &&
                componentDialect(absolute) === null
            ) {
                if (path.extname(absolute) !== "")
                    throw new Error(
                        `Unsupported TypeScript input: ${relative}`,
                    );
                if (!namesJavaScriptInShebang(absolute))
                    throw new RefusedAfterRead(
                        `Unsupported TypeScript input: ${relative}`,
                        shebangRefusalEvidence(absolute, maxFileBytes),
                    );
            }
            let stat;
            try {
                stat = fs.statSync(absolute);
            } catch (error) {
                throw new UnreadableInput(errorMessage(error));
            }
            if (!stat.isFile() || stat.size > maxFileBytes)
                throw new UnreadableInput(
                    `TypeScript input exceeds limits: ${relative}`,
                );
            accepted.push(requested);
        } catch (error) {
            rejected.push({
                relative: requested,
                message: errorMessage(error),
                failedRead: error instanceof UnreadableInput,
                ...(error instanceof RefusedAfterRead
                    ? { refusalHash: error.contentHash }
                    : {}),
            });
        }
    }

    return { accepted, rejected };
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

// V8's own message for a JavaScript stack overflow. Any other RangeError is a
// real fault and keeps propagating.
function isStackOverflow(error) {
    return (
        error instanceof RangeError &&
        error.message.includes("Maximum call stack size exceeded")
    );
}

// For a catch that reads any failure as the filesystem's answer. A stack
// overflow is not one: a host callback is a leaf frame of a deep program build,
// so swallowing the overflow there would drop one read and let the build carry
// on, truncating the program instead of reaching the TS_PROGRAM_TOO_DEEP
// backstop in #scanProgram.
function rethrowStackOverflow(error) {
    if (isStackOverflow(error)) throw error;
}

// A contribution that carries nothing but the reason one file was skipped.
function unscannableContribution(relative, message) {
    return factFreeContribution(relative, "TS_UNSCANNABLE_FILE", message);
}

// A contribution with no facts, only a diagnostic saying why.
function factFreeContribution(relative, code, message) {
    return {
        owner_key: `knossos.typescript:file:${relative}`,
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

// Discovery classifies an extensionless script by its shebang and routes it
// here, so gating on the extension alone rejected exactly the files discovery
// had just resolved. Mirrors the discoverer's rule: match `#!/usr/bin/node` and
// `#!/usr/bin/env node`, tolerate a version suffix, and anchor to a word
// boundary so a path merely containing an interpreter name is not matched. Only
// the first line is read, and only for a file that has no known extension.
function namesJavaScriptInShebang(absolute) {
    if (path.extname(absolute) !== "") return false;
    let buffer;
    let read;
    try {
        // Non-blocking, and checked on the handle, as readBounded does: the
        // path may have been swapped for a FIFO, whose open would otherwise
        // block until a writer appears.
        const handle = fs.openSync(
            absolute,
            fs.constants.O_RDONLY | fs.constants.O_NONBLOCK,
        );
        try {
            if (!fs.fstatSync(handle).isFile())
                throw new Error(`Not a regular file: ${absolute}`);
            buffer = Buffer.alloc(SHEBANG_PROBE_BYTES);
            read = fs.readSync(handle, buffer, 0, SHEBANG_PROBE_BYTES, 0);
        } finally {
            fs.closeSync(handle);
        }
    } catch (error) {
        // Only ever asked of a path that just resolved, so a failed read is the
        // filesystem's answer, not this script's interpreter.
        throw new UnreadableInput(errorMessage(error));
    }

    return probeNamesJavaScript(buffer.subarray(0, read));
}

// Whether the probe's bytes, the file's first SHEBANG_PROBE_BYTES at most, name
// JavaScript on their first line.
function probeNamesJavaScript(bytes) {
    const first = bytes
        .toString("utf8", 0, Math.min(bytes.length, SHEBANG_PROBE_BYTES))
        .split("\n", 1)[0];
    return (
        first.startsWith("#!") &&
        /\b(node|nodejs|bun|deno)[0-9.]*\b/i.test(first)
    );
}

// What a shebang refusal reports in `input_hashes` for the refused file. The
// probe read only a line, which no discovery hash can be compared with, so the
// whole file is read once more, bounded, and judged again on those same bytes.
// A file that still does not name JavaScript is reported by that read's hash:
// a tree that is not changing matches what discovery hashed, so a script this
// rule and discovery's happen to judge apart costs only its diagnostic, while a
// script swapped for another one does not match. A read that fails, is over the
// cap, or now names JavaScript saw a file that changed between the two reads,
// and is reported as null.
function shebangRefusalEvidence(absolute, maxFileBytes) {
    let buffer;
    try {
        buffer = readBounded(absolute, maxFileBytes);
    } catch {
        return null;
    }
    if (buffer === undefined || probeNamesJavaScript(buffer)) return null;
    return createHash("sha256").update(buffer).digest("hex");
}

// Whether a file opens with a shebang, whatever interpreter it names.
//
// A shebang means the file is executed rather than imported, which is what
// dead-code analysis needs to know: nothing in the codebase references a
// script, so its module having no inbound edge says nothing about whether it is
// wanted. Unlike the probe above, which decides whether an extensionless file
// is JavaScript at all, this asks only how the file is entered, so the
// interpreter is irrelevant. A byte-order mark may precede it.
function startsWithShebang(text) {
    return text.replace(/^\uFEFF/, "").startsWith("#!");
}

// Source extensions a module URL can name; an asset URL names none of them.
const MODULE_URL_EXTENSION = /\.(?:[cm]?[jt]sx?)$/;

// The project module `new URL('./gen.worker.ts', import.meta.url)` names: how
// Vite, webpack and the browser load a module worker, which no import names.
// Decided from the literal alone, so the answer cannot depend on which files
// share a request; a path that leaves the project, lands below node_modules or
// in build output, or names an asset, is no module of this project.
function importMetaUrlModule(node, sourceFile, root) {
    const args = node.arguments ?? [];
    if (
        !ts.isIdentifier(node.expression) ||
        node.expression.text !== "URL" ||
        args.length < 2 ||
        !ts.isStringLiteralLike(args[0]) ||
        !isImportMetaUrl(args[1])
    )
        return null;
    const specifier = args[0].text;
    if (
        !(specifier.startsWith("./") || specifier.startsWith("../")) ||
        !MODULE_URL_EXTENSION.test(specifier)
    )
        return null;
    const absolute = normalize(
        path.resolve(path.dirname(sourceFile.fileName), specifier),
    );
    const relative = relativeInside(root, absolute);
    if (
        relative === null ||
        belowNodeModules(relative) ||
        excludedByProjectLayout(relative)
    )
        return null;
    return relative;
}

// `__dirname`, or `import.meta.dirname`.
function isDirnameExpression(expression) {
    if (ts.isIdentifier(expression)) return expression.text === "__dirname";
    return (
        ts.isPropertyAccessExpression(expression) &&
        expression.name.text === "dirname" &&
        ts.isMetaProperty(expression.expression)
    );
}

// The project-relative source module a path names, resolved against
// `directory`, or null for one outside the project, below node_modules, in
// excluded build output, or not a source file at all.
function sourcePathTarget(root, directory, specifier) {
    if (!MODULE_URL_EXTENSION.test(specifier)) return null;
    const relative = relativeInside(
        root,
        normalize(path.resolve(directory, specifier)),
    );
    if (
        relative === null ||
        relative === "" ||
        belowNodeModules(relative) ||
        excludedByProjectLayout(relative)
    )
        return null;
    return relative;
}

function isImportMetaUrl(expression) {
    return (
        ts.isPropertyAccessExpression(expression) &&
        expression.name.text === "url" &&
        ts.isMetaProperty(expression.expression) &&
        expression.expression.keywordToken === ts.SyntaxKind.ImportKeyword
    );
}

// Whether a file-scope `if` runs its body only when the file is the program
// entered: `import.meta.main` (Bun, Deno) or CommonJS `require.main === module`.
// This is JavaScript's `__main__` guard, and it says the same thing a shebang
// does: something outside the graph runs the file, so no inbound edge is owed.
// A guard nested in a function, or a negated one, says nothing about that.
function hasMainGuard(sourceFile) {
    return sourceFile.statements.some(
        (statement) =>
            ts.isIfStatement(statement) &&
            isMainGuardCondition(unwrapParentheses(statement.expression)),
    );
}

function isMainGuardCondition(expression) {
    if (isImportMetaMain(expression)) return true;
    if (!ts.isBinaryExpression(expression)) return false;
    const operator = expression.operatorToken.kind;
    if (
        operator !== ts.SyntaxKind.EqualsEqualsEqualsToken &&
        operator !== ts.SyntaxKind.EqualsEqualsToken
    )
        return false;
    const left = unwrapParentheses(expression.left);
    const right = unwrapParentheses(expression.right);
    return (
        (isRequireMain(left) && isIdentifierNamed(right, "module")) ||
        (isIdentifierNamed(left, "module") && isRequireMain(right))
    );
}

function isImportMetaMain(expression) {
    return (
        ts.isPropertyAccessExpression(expression) &&
        expression.name.text === "main" &&
        ts.isMetaProperty(expression.expression) &&
        expression.expression.keywordToken === ts.SyntaxKind.ImportKeyword
    );
}

function isRequireMain(expression) {
    return (
        ts.isPropertyAccessExpression(expression) &&
        expression.name.text === "main" &&
        isIdentifierNamed(expression.expression, "require")
    );
}

function isIdentifierNamed(expression, name) {
    return ts.isIdentifier(expression) && expression.text === name;
}

function unwrapParentheses(expression) {
    let current = expression;
    while (ts.isParenthesizedExpression(current)) current = current.expression;
    return current;
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

/** The program for requested files no config's program emitted. */
function fallbackConfig(root, remaining) {
    return {
        options: {
            allowJs: true,
            checkJs: false,
            noEmit: true,
            target: ts.ScriptTarget.Latest,
            module: ts.ModuleKind.ESNext,
            moduleResolution: ts.ModuleResolutionKind.Bundler,
            jsx: ts.JsxEmit.Preserve,
        },
        // An extensionless script, or one whose extension is not in lower
        // case, only ever reaches the fallback program: no tsconfig `include`
        // matches either name.
        fileNames: remaining.map((relative) =>
            offeredPath(path.join(root, relative)),
        ),
        projectReferences: undefined,
    };
}

function validateRoot(input) {
    if (typeof input !== "string" || input.length === 0)
        throw new Error("A project root is required.");
    const root = realpathNative(input);
    if (!fs.statSync(root).isDirectory())
        throw new Error("Project root is not a directory.");
    return root;
}

// Kept separate from reading the file: a path of the wrong shape cannot be
// attributed to any file, so it fails the request, while a well-formed path that
// simply cannot be scanned costs only that file.
function assertScannablePath(relative) {
    if (
        typeof relative !== "string" ||
        relative.length === 0 ||
        path.isAbsolute(relative) ||
        relative.includes("\0")
    ) {
        throw new Error("Project-relative path is invalid.");
    }
    const segments = normalize(relative).split("/");
    if (
        segments.some(
            (segment) => segment === "" || segment === "." || segment === "..",
        )
    )
        throw new Error("Project-relative path is invalid.");
}

function validatedInside(root, relative) {
    assertScannablePath(relative);
    const real = realpathNative(path.join(root, relative));
    if (!contains(root, real))
        throw new Error("Project-relative path escapes the root.");
    return real;
}

// Whether the compiler may touch a path: the default library, or a path inside
// the root that the kernel's lookup (walkPath) keeps inside it. A path that does
// not resolve at all is let through, so its read fails and is recorded by
// readHashedSourceFile under the same walk's key.
function allowedCompilerPath(root, candidate) {
    const normalized = realSourcePath(normalize(path.resolve(candidate)));
    if (contains(defaultLibDirectory(), normalized)) return true;
    if (!contains(root, normalized)) return false;
    const relative = normalize(path.relative(root, normalized));
    if (relative !== "" && excludedByProjectLayout(relative)) return false;
    const walked = walkPath(normalized);
    return walked.location === undefined || contains(root, walked.location);
}

/** Whether an in-root absolute path lies where the project's exclusions refuse it. */
function excludedByProjectLayoutPath(root, absolute) {
    if (!contains(root, absolute)) return false;
    const relative = normalize(path.relative(root, absolute));
    return relative !== "" && excludedByProjectLayout(relative);
}

/**
 * Whether the project's own directory exclusions refuse a project-relative path.
 *
 * The exclusions name directories a project builds into or vendors under, so
 * they describe the project's layout. Inside a dependency tree they describe
 * nothing: a package ships its declarations wherever its own package.json
 * points, and `dist` is the most common answer of all. Applying the project's
 * rules there refused `node_modules/@eslint/core/dist/cjs/types.d.cts`, the one
 * file that package's types live in, which cost twice over: the refusal is
 * recorded as a failed read, and a failed read of a file that reads perfectly
 * well is what UndiscoveredInputVerifier fails the whole scan on at commit;
 * and until it does, a symbol the refused declaration names resolves to
 * `unknown`, so `class A extends Dep` is recorded as extending nothing anyone
 * can name.
 *
 * So the check stops at the first resolution-allowed segment: above it the
 * project's layout governs, below it the dependency's does.
 */
function excludedByProjectLayout(relative) {
    const segments = relative.split("/");
    const dependencyRoot = segments.findIndex((segment) =>
        RESOLUTION_ALLOWED_EXCLUDED.has(segment),
    );
    const governed =
        dependencyRoot === -1 ? segments : segments.slice(0, dependencyRoot);
    return (
        governed.some(
            (segment) =>
                isExcludedDirectoryName(segment) &&
                !RESOLUTION_ALLOWED_EXCLUDED.has(segment),
        ) ||
        governed.some(
            (segment, index) =>
                index + 1 < governed.length &&
                EXCLUDED_SEGMENT_SEQUENCES.some(
                    ([first, second]) =>
                        segment === first && governed[index + 1] === second,
                ),
        )
    );
}

/**
 * Whether a project-relative path lies below a node_modules directory, at the
 * top level of the project or nested. A relative path has no leading slash, so
 * "/node_modules/" alone would miss the project's own node_modules.
 */
function belowNodeModules(relative) {
    return (
        relative === "node_modules" ||
        relative.startsWith("node_modules/") ||
        relative.includes("/node_modules/")
    );
}

function relativeInside(root, candidate) {
    const normalized = realSourcePath(normalize(path.resolve(candidate)));
    if (!contains(root, normalized)) return null;
    return normalize(path.relative(root, normalized));
}

// The single chokepoint every relative path passes through, so un-aliasing here
// keeps canonical names, evidence paths, and the emitted owner keys pointed at
// the file that actually exists on disk.
function realSourcePath(candidate) {
    if (candidate.endsWith(SHEBANG_ALIAS_SUFFIX))
        return candidate.slice(0, -SHEBANG_ALIAS_SUFFIX.length);
    const component = COMPONENT_ALIAS.exec(candidate);
    if (component !== null) {
        const original = candidate.slice(
            0,
            component.index + 1 + component[1].length,
        );
        if (
            component[3].toLowerCase() ===
                componentAliasSuffix(componentDialect(original)) &&
            (component[2] !== undefined || !isRegularFile(candidate)) &&
            isRegularFile(original)
        )
            return original;
    }
    const caseAlias = /\.knossos-alias(\.[a-z]+)$/.exec(candidate);
    if (caseAlias !== null && SOURCE_EXTENSIONS.has(caseAlias[1])) {
        const original = candidate.slice(0, caseAlias.index);
        const originalExtension = path.extname(original);
        // offeredPath only ever appends this mark to a name whose own
        // extension is a supported one spelled with some upper case, the
        // exact case it lower-cases below the mark. A real file that happens
        // to be named e.g. `x.knossos-alias.ts` already has a lower-case
        // extension, so stripping the mark here would rename it to `x` and
        // lose its facts under the wrong key; only strip when undoing that
        // exact case fold would restore it.
        if (
            originalExtension !== "" &&
            originalExtension !== originalExtension.toLowerCase() &&
            originalExtension.toLowerCase() === caseAlias[1]
        )
            return original;
    }
    return candidate;
}

// The name a requested file is offered to the program under: an extensionless
// script as a `.js` alias, a file whose extension is not in lower case under its
// lower-cased extension, and anything else as itself.
function offeredPath(absolute) {
    const dialect = componentDialect(absolute);
    if (dialect !== null) {
        const suffix = componentAliasSuffix(dialect);
        return isRegularFile(`${absolute}${suffix}`)
            ? `${absolute}${COMPONENT_ALIAS_MARK}${suffix}`
            : `${absolute}${suffix}`;
    }
    const extension = path.extname(absolute);
    if (extension === "") return `${absolute}${SHEBANG_ALIAS_SUFFIX}`;
    if (extension !== extension.toLowerCase())
        return `${absolute}${CASE_ALIAS_MARK}${extension.toLowerCase()}`;
    return absolute;
}

/** A component's alias (see offeredPath); any other file as itself. */
function offeredComponentPath(file) {
    return componentDialect(file) === null ? file : offeredPath(file);
}

/** Whether a path is a regular file, following links as the compiler does. */
function isRegularFile(candidate) {
    return fs.statSync(candidate, { throwIfNoEntry: false })?.isFile() ?? false;
}

function contains(root, candidate) {
    const base = normalize(root).replace(/\/$/, "");
    const value = normalize(candidate);
    return value === base || value.startsWith(`${base}/`);
}

function normalize(value) {
    return value.replaceAll("\\", "/");
}

function walk(root, directory, onFile) {
    let entries;
    try {
        entries = fs.readdirSync(directory, { withFileTypes: true });
    } catch {
        // An unreadable directory (EACCES/EPERM) must not fail the whole
        // tsconfig walk; skip it and continue, matching Python's os.walk.
        return;
    }
    for (const entry of entries) {
        if (isExcludedDirectoryName(entry.name)) continue;
        const absolute = path.join(directory, entry.name);
        const relative = normalize(path.relative(root, absolute));
        if (entry.isSymbolicLink()) continue;
        if (entry.isDirectory()) walk(root, absolute, onFile);
        else if (entry.isFile()) onFile(absolute, relative);
    }
}
