import { createHash } from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import ts from "typescript";
import { FactAccumulator } from "./fact-accumulator.js";
import { NestJsFactEnricher } from "./nestjs-fact-enricher.js";
import { TypeScriptApplicationEnricher } from "./typescript-application-enricher.js";
import { callName, reference } from "./typescript-fact-utils.js";

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
        };
        const tally = (outcome) => {
            if (outcome === undefined) return;
            ++programs;
            if (outcome.reused) ++programsReused;
        };

        for (const configPath of configPaths) {
            const parsed = parseConfig(root, configPath, reads);
            tally(
                this.#scanProgram(
                    `${root}\0${configPath}`,
                    withProjectCompilerDefaults(
                        root,
                        path.dirname(path.join(root, configPath)),
                        parsed,
                        params.typescript_versions,
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
            const options = {
                allowJs: true,
                checkJs: false,
                noEmit: true,
                target: ts.ScriptTarget.Latest,
                module: ts.ModuleKind.ESNext,
                moduleResolution: ts.ModuleResolutionKind.Bundler,
                jsx: ts.JsxEmit.Preserve,
            };
            const parsed = {
                options,
                // An extensionless script, or one whose extension is not in
                // lower case, only ever reaches the fallback program: no
                // tsconfig `include` matches either name.
                fileNames: remaining.map((relative) =>
                    offeredPath(path.join(root, relative)),
                ),
                projectReferences: undefined,
            };
            tally(
                this.#scanProgram(
                    `${root}\0<fallback>`,
                    withProjectCompilerDefaults(
                        root,
                        root,
                        parsed,
                        params.typescript_versions,
                    ),
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
        { root, maxFileBytes, reads, requestedSet, emitted, emit },
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

    #emitProgram(root, program, requestedSet, emitted, emit, maxFileBytes) {
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
                emitted.has(relative)
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
                const collector = new FactCollector(root, sourceFile, checker);
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
    constructor(root, sourceFile, checker) {
        this.language = new TypeScriptLanguageFactCollector(
            root,
            sourceFile,
            checker,
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
    }

    visit(node) {
        const pushed = this.language.enter(node);
        this.language.handle(node);
        ts.forEachChild(node, (child) => this.visit(child));
        this.language.leave(pushed);
    }
}

class TypeScriptLanguageFactCollector {
    constructor(root, sourceFile, checker) {
        this.root = root;
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

    enter(node) {
        return isDeclaration(node) ? this.declaration(node) : false;
    }

    handle(node) {
        if (ts.isImportDeclaration(node)) this.importDeclaration(node);
        if (ts.isExportDeclaration(node)) this.exportDeclaration(node);
        if (ts.isImportEqualsDeclaration(node)) this.importEquals(node);
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
                : insideAmbientDeclaration(node)
                  ? { ...descriptor.attributes, ambient: true }
                  : descriptor.attributes,
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

        if (
            ts.isClassDeclaration(node) ||
            ts.isClassExpression(node) ||
            ts.isInterfaceDeclaration(node)
        ) {
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

        if (isObjectLiteralBinding(node)) {
            const contract = objectLiteralContract(node);
            const target =
                contract !== undefined && ts.isTypeReferenceNode(contract)
                    ? this.typeNodeReference(contract)
                    : null;
            if (target !== null && target !== id)
                this.addEdge("implements", id, target, contract);
        }

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
                { dynamic: true, url: true, type_only: false },
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

        const signature = this.checker.getResolvedSignature(node);
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
        if (ts.isAwaitExpression(call)) call = unwrapParentheses(call.expression);
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
        // Declared in this project, but not as anything `declaration()` emits:
        // a type parameter, an inline `{ ... }` type, a `const f = () => ...`
        // arrow. The name built for it would match no node, and the core turns
        // a dangling edge into an external component that is neither external
        // nor a component.
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
    );
    if (!parsed)
        throw new Error(`Unable to parse TypeScript config: ${configPath}`);
    const fileNames = new Set(
        parsed.fileNames.filter((file) => allowedCompilerPath(root, file)),
    );
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
        );
        if (!referenced) continue;
        for (const file of referenced.fileNames) {
            if (allowedCompilerPath(root, file)) fileNames.add(file);
        }
        pending.push(...(referenced.projectReferences ?? []));
    }
    parsed.fileNames = [...fileNames];
    // Analysis consumes referenced sources directly; build-mode output redirection
    // would otherwise require users to compile projects before scanning.
    parsed.projectReferences = undefined;
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
    const sourceFile = ts.createSourceFile(
        fileName,
        decodeLikeTypeScript(buffer),
        languageVersion,
        true,
        scriptKind,
    );
    parsedContentHashes.set(sourceFile, contentHash);
    reads.created(sourceFile);
    recordWalked(reads, root, walked, contentHash, maxFileBytes);
    return sourceFile;
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
function readBounded(file, maxBytes) {
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
    return ts.createProgram({
        rootNames: parsed.fileNames,
        options,
        projectReferences: parsed.projectReferences,
        host,
        oldProgram,
    });
}

function diagnosticsForProgram(program, root, maxFileBytes) {
    const result = new Map();
    for (const diagnostic of ts.getPreEmitDiagnostics(program)) {
        if (!diagnostic.file) continue;
        if (diagnostic.code === 6059) continue; // Analysis-only project-reference source merging triggers this.
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
    return result;
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
    const modifiers = ts.canHaveModifiers(node)
        ? (ts.getModifiers(node) ?? [])
        : [];
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
    if (isObjectLiteralBinding(node)) return "variable";
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
            ts.isFunctionDeclaration(node)) &&
        !node.name
    ) {
        // Include the column so minified single-line bundles don't collapse
        // every anonymous entity onto the same `@line` key.
        const position = sourceFile.getLineAndCharacterOfPosition(
            node.getStart(sourceFile),
        );
        return `{anonymous}@${position.line + 1}:${position.character + 1}`;
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
        isObjectLiteralBinding(node)
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
        isObjectLiteralBinding(node) ||
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
 * telling us anything about reachability. The one variable admitted is an
 * object-literal binding with methods, which the graph holds as a component
 * (see {@link isObjectLiteralBinding}) and which is used by being handed around.
 */
function referenceableDeclaration(node) {
    return (
        ts.isFunctionDeclaration(node) ||
        ts.isMethodDeclaration(node) ||
        ts.isClassDeclaration(node) ||
        ts.isInterfaceDeclaration(node) ||
        ts.isEnumDeclaration(node) ||
        ts.isTypeAliasDeclaration(node) ||
        isObjectLiteralBinding(node)
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
    // `input.run ?? defaultRun`, `a || b` and `flag ? a : b`: a fallback or
    // a choice between functions, either of which may be the one that runs.
    if (
        ts.isBinaryExpression(parent) &&
        [
            ts.SyntaxKind.QuestionQuestionToken,
            ts.SyntaxKind.BarBarToken,
            ts.SyntaxKind.AmpersandAmpersandToken,
        ].includes(parent.operatorToken.kind)
    )
        return true;
    if (
        ts.isConditionalExpression(parent) &&
        (parent.whenTrue === node || parent.whenFalse === node)
    )
        return true;
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

// Default-library declaration files are exempt: skipping one would break type
// resolution for every file. Only project sources under the root are capped.
function exceedsByteCap(fileName, maxFileBytes) {
    const normalized = realSourcePath(normalize(path.resolve(fileName)));
    if (contains(defaultLibDirectory(), normalized)) return false;
    try {
        return fs.statSync(normalized).size > maxFileBytes;
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
            if (!SOURCE_EXTENSIONS.has(path.extname(absolute).toLowerCase())) {
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
    const extension = path.extname(absolute);
    if (extension === "") return `${absolute}${SHEBANG_ALIAS_SUFFIX}`;
    if (extension !== extension.toLowerCase())
        return `${absolute}${CASE_ALIAS_MARK}${extension.toLowerCase()}`;
    return absolute;
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
