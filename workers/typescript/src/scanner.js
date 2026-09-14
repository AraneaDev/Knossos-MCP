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
    "build",
    "dist",
]);

// Directory-name prefixes, for the namespace this tool owns. ".knossos" alone
// is in the set above; a CI job parks a checkout of the analyzer or its snapshot
// database beside the project under the same convention, and those must not be
// discovered as the project's own source.
const EXCLUDED_DIRECTORY_PREFIXES = [".knossos-"];

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
    }

    /**
     * Stream deterministic owned contributions for the requested source files.
     *
     * @param {{root: unknown, files: unknown, config_files?: unknown, limits?: unknown}} params
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

        for (const configPath of configPaths) {
            const parsed = parseConfig(root, configPath);
            const key = `${root}\0${configPath}`;
            this.#reserveProgramSlot(key);
            const oldProgram = this.programCache.get(key);
            const program = createRestrictedProgram(
                root,
                parsed,
                oldProgram,
                maxFileBytes,
                reads,
            );
            this.#cacheProgram(key, program);
            if (oldProgram) ++programsReused;
            recordUnreadSourceFiles(root, program, reads);
            this.#emitProgram(root, program, requestedSet, emitted, emit);
            ++programs;
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
            const key = `${root}\0<fallback>`;
            this.#reserveProgramSlot(key);
            const oldProgram = this.programCache.get(key);
            const program = createRestrictedProgram(
                root,
                parsed,
                oldProgram,
                maxFileBytes,
                reads,
            );
            this.#cacheProgram(key, program);
            if (oldProgram) ++programsReused;
            recordUnreadSourceFiles(root, program, reads);
            this.#emitProgram(root, program, requestedSet, emitted, emit);
            ++programs;
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

    // Insert a program as most-recently-used and evict the least-recently-used
    // entries beyond the cap so peak resident program memory stays bounded.
    #cacheProgram(key, program) {
        this.programCache.delete(key);
        this.programCache.set(key, program);
        while (this.programCache.size > MAX_CACHED_PROGRAMS) {
            const oldest = this.programCache.keys().next().value;
            this.programCache.delete(oldest);
        }
    }

    #emitProgram(root, program, requestedSet, emitted, emit) {
        const checker = program.getTypeChecker();
        const diagnosticsByFile = diagnosticsForProgram(program, root);

        for (const sourceFile of program.getSourceFiles()) {
            const relative = relativeInside(root, sourceFile.fileName);
            if (
                relative === null ||
                relative.includes("/node_modules/") ||
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
                executable: startsWithShebang(this.sourceFile.text),
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
        if (ts.isVariableDeclaration(node)) this.application.variable(node);
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

        if (
            (ts.isFunctionDeclaration(node) ||
                ts.isMethodDeclaration(node) ||
                ts.isMethodSignature(node)) &&
            node.type
        ) {
            const target = this.typeNodeReference(node.type);
            if (target !== null) this.addEdge("returns", id, target, node.type);
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

        const symbol = unalias(
            this.checker,
            this.checker.getSymbolAtLocation(node),
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
        if (relative === null || relative.includes("/node_modules/"))
            return null;
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
        if (relative === null || relative.includes("/node_modules/")) {
            const name = symbol?.getName();
            return name && !name.startsWith("__")
                ? reference(`external_${hint}`, name)
                : null;
        }
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

function parseConfig(root, configPath) {
    const absolute = validatedInside(root, configPath);
    const host = {
        ...ts.sys,
        readFile: (file) =>
            allowedCompilerPath(root, file) ? ts.sys.readFile(file) : undefined,
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
        recordWalked(reads, root, walked, null);
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
    } catch {
        buffer = undefined;
    }
    if (buffer === undefined) {
        recordWalked(reads, root, walked, null);
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
    recordWalked(reads, root, walked, contentHash);
    return sourceFile;
}

/**
 * Read a file's bytes, or undefined when it holds more than `maxBytes`: at most
 * one byte past the cap is ever read.
 */
function readBounded(file, maxBytes) {
    const handle = fs.openSync(file, "r");
    try {
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
 * the core cannot track: outside the root (the default library) or below a
 * node_modules directory, which discovery never reports.
 */
function inputHashKey(root, absolute) {
    const relative = relativeInside(root, absolute);
    if (
        relative === null ||
        relative === "" ||
        relative === "node_modules" ||
        relative.startsWith("node_modules/") ||
        relative.includes("/node_modules/")
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
 * is anything outside the root or below node_modules.
 *
 * Discovery never reports a link and never descends into a linked directory,
 * so on a stable tree every linked key names a path the core ignores. Mid-scan,
 * a discovered file swapped for a link (to another file, to a directory, or on
 * a package's resolved path) is keyed where discovery saw it, so the read or
 * probe that went through it is checked against discovery's hash.
 *
 * @returns {{final: string|null, linked: string[]}}
 */
function walkKeys(root, walked) {
    const location = inputKeyLocation(root, walked);
    const final = location === null ? null : inputHashKey(root, location);
    const linked = new Set();
    const add = (candidate) => {
        const key = inputHashKey(root, candidate);
        if (key !== null && key !== final) linked.add(key);
    };
    for (const link of walked.links) {
        if (!contains(root, link.location)) continue;
        add(link.location);
        const rest = link.rest.filter((name) => name !== "" && name !== ".");
        if (rest.length > 0 && !rest.includes(".."))
            add(`${link.location}/${rest.join("/")}`);
    }
    return { final, linked: [...linked] };
}

/** Record a read, hashed or failed, under every key its walk gives. */
function recordWalked(reads, root, walked, contentHash) {
    const { final, linked } = walkKeys(root, walked);
    if (final !== null) reads.record(final, contentHash);
    for (const key of linked) reads.record(key, contentHash);
}

/** Record a path the host would not or could not read as a failed read. */
function recordRefused(reads, root, absolute) {
    recordWalked(reads, root, walkPath(absolute), null);
}

/**
 * Record an existence probe whose answer feeds facts, such as a module
 * resolution candidate or a realpath.
 *
 * A probe answering absent, or not a file, records null under every key its
 * walk gives: the compiler goes on as if the file were not there, so a
 * discovered file missing for that moment must fail verification. A probe
 * answering present read no bytes, so it vouches for nothing at the location it
 * reached, which a read will record; it records null only under the linked
 * keys, so a discovered path that had become a link is still caught. Discovery
 * never reports an absent path or a link, so a stable tree is unaffected.
 */
function recordProbe(reads, root, walked, present) {
    const { final, linked } = walkKeys(root, walked);
    if (!present && final !== null) reads.record(final, null);
    for (const key of linked) reads.record(key, null);
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
            } catch {
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
 *   directory read fails (EISDIR); discovery never reports a directory, so the
 *   key is ignored on a stable tree, while a discovered file replaced by one
 *   mid-scan fails verification.
 * - Otherwise the last link followed inside the root. A link followed as a
 *   directory component keys the path below it as it was about to be walked
 *   (a discovered `src/sub/c.ts` whose `src/sub` became a link out of the root
 *   keys `src/sub/c.ts`), unless that path holds a `..`, which only the walk
 *   could have applied; then the link itself.
 *
 * Every key a stable layout produces this way names either the file the
 * kernel reaches, or a link or a path through one, which discovery never
 * reports and the core ignores. A discovered file changed into one of those
 * mid-scan is keyed where discovery saw it, so it fails verification.
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
function recordUnreadSourceFiles(root, program, reads) {
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
        );
    }
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
        // gone under (walkPath, inputKeyLocation): a stable layout never trips over
        // that, since discovery reports neither a symlink nor an over-cap file
        // and the core ignores a path it did not discover, while a discovered
        // file that became one mid-scan fails verification.
        const absolute = normalize(path.resolve(realSourcePath(fileName)));
        reads.observe("load", absolute);
        const refused = () => {
            recordRefused(reads, root, absolute);
            return undefined;
        };
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
        recordProbe(reads, root, walked, present);
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
        } catch {
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
            recordProbe(reads, root, walked, present);
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
    // host.fileExists, which recorded its walk. Discovery tracks package.json
    // as a unit rather than a file, so today the core ignores these keys,
    // which also means they cost a stable tree nothing.
    host.readFile = (file) => {
        if (!allowedCompilerPath(root, file)) return undefined;
        const normalized = realSourcePath(normalize(path.resolve(file)));
        const library = contains(defaultLibDirectory(), normalized);
        let buffer;
        try {
            buffer = readBounded(
                normalized,
                library ? Number.MAX_SAFE_INTEGER : maxFileBytes,
            );
        } catch {
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
            );
        return buffer === undefined ? undefined : decodeLikeTypeScript(buffer);
    };
    return ts.createProgram({
        rootNames: parsed.fileNames,
        options,
        projectReferences: parsed.projectReferences,
        host,
        oldProgram,
    });
}

function diagnosticsForProgram(program, root) {
    const result = new Map();
    for (const diagnostic of ts.getPreEmitDiagnostics(program)) {
        if (!diagnostic.file) continue;
        if (diagnostic.code === 6059) continue; // Analysis-only project-reference source merging triggers this.
        const relative = relativeInside(root, diagnostic.file.fileName);
        if (relative === null || relative.includes("/node_modules/")) continue;
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

function isDeclaration(node) {
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
        ts.isPropertySignature(node)
    );
}

function containerDeclaration(node) {
    return (
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
 * telling us anything about reachability.
 */
function referenceableDeclaration(node) {
    return (
        ts.isFunctionDeclaration(node) ||
        ts.isMethodDeclaration(node) ||
        ts.isClassDeclaration(node) ||
        ts.isInterfaceDeclaration(node) ||
        ts.isEnumDeclaration(node) ||
        ts.isTypeAliasDeclaration(node)
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
    // `const run = handler;` / `private fn = handler;`
    if (
        (ts.isVariableDeclaration(parent) ||
            ts.isPropertyDeclaration(parent)) &&
        parent.initializer === node
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
    } catch {
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
        const handle = fs.openSync(absolute, "r");
        try {
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
    const walked = walkPath(normalized);
    return walked.location === undefined || contains(root, walked.location);
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
