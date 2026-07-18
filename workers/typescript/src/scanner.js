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
 * Performs bounded compiler-backed discovery and scanning without executing
 * target modules. Instances retain TypeScript programs for incremental reuse.
 */
export class TypeScriptScanner {
    constructor() {
        this.programCache = new Map();
    }

    /**
     * Return sorted TypeScript configuration and package inputs below a validated root.
     *
     * @param {{root: unknown}} params
     * @returns {{root: string, config_files: string[], package_files: string[]}}
     */
    discover(params) {
        const root = validateRoot(params.root);
        const configs = [];
        const packages = [];
        walk(root, root, (absolute, relative) => {
            const basename = path.basename(relative).toLowerCase();
            if (basename === "package.json") packages.push(relative);
            if (
                basename === "tsconfig.json" ||
                (basename.startsWith("tsconfig.") && basename.endsWith(".json"))
            ) {
                configs.push(relative);
            }
        });

        configs.sort();
        packages.sort();
        return { root, config_files: configs, package_files: packages };
    }

    /**
     * Stream deterministic owned contributions for the requested source files.
     *
     * @param {{root: unknown, files: unknown, config_files?: unknown, limits?: unknown}} params
     * @param {(contribution: object) => void} emit
     * @returns {{files_scanned: number, programs: number, programs_reused: number}}
     */
    scan(params, emit) {
        const root = validateRoot(params.root);
        const requested = validateRequestedFiles(
            root,
            params.files,
            params.limits,
        );
        const requestedSet = new Set(requested.map((file) => normalize(file)));
        const configPaths = configFilesForScan(root, params.config_files);
        const emitted = new Set();
        let programs = 0;
        let programsReused = 0;

        for (const configPath of configPaths) {
            const parsed = parseConfig(root, configPath);
            const key = `${root}\0${configPath}`;
            const oldProgram = this.programCache.get(key);
            const program = createRestrictedProgram(root, parsed, oldProgram);
            this.#cacheProgram(key, program);
            if (oldProgram) ++programsReused;
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
                fileNames: remaining.map((relative) =>
                    path.join(root, relative),
                ),
                projectReferences: undefined,
            };
            const key = `${root}\0<fallback>`;
            const oldProgram = this.programCache.get(key);
            const program = createRestrictedProgram(root, parsed, oldProgram);
            this.#cacheProgram(key, program);
            if (oldProgram) ++programsReused;
            this.#emitProgram(root, program, requestedSet, emitted, emit);
            ++programs;
        }

        return {
            files_scanned: emitted.size,
            programs,
            programs_reused: programsReused,
        };
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

            const collector = new FactCollector(root, sourceFile, checker);
            collector.collect();
            emit({
                owner_key: `knossos.typescript:file:${relative}`,
                nodes: collector.nodes,
                edges: collector.edges,
                diagnostics: diagnosticsByFile.get(relative) ?? [],
            });
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
            descriptor.attributes,
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
        const typeOnly =
            node.importClause?.isTypeOnly === true ||
            (node.importClause?.namedBindings &&
                ts.isNamedImports(node.importClause.namedBindings) &&
                node.importClause.namedBindings.elements.every(
                    (element) => element.isTypeOnly,
                ));
        this.addEdge("imports", this.moduleId, target, node, {
            type_only: typeOnly,
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
                    { dynamic: true },
                );
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
                    { commonjs: true },
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

    typeReference(node) {
        const source = this.currentSource();
        const target = this.typeNodeReference(node);
        if (source !== null && target !== null && source !== target)
            this.addEdge("references", source, target, node);
    }

    typeNodeReference(node) {
        const type = this.checker.getTypeFromTypeNode(node);
        return this.symbolReference(type.aliasSymbol ?? type.symbol, "class");
    }

    moduleTarget(specifier, location) {
        const symbol = unalias(
            this.checker,
            this.checker.getSymbolAtLocation(location),
        );
        const declaration = symbol?.declarations?.find((item) =>
            ts.isSourceFile(item),
        );
        if (declaration) {
            const relative = relativeInside(this.root, declaration.fileName);
            if (relative !== null && !relative.includes("/node_modules/"))
                return reference("module", relative);
        }

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

function createRestrictedProgram(root, parsed, oldProgram = undefined) {
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
    const getSourceFile = host.getSourceFile.bind(host);
    host.getSourceFile = (
        fileName,
        languageVersion,
        onError,
        shouldCreateNewSourceFile,
    ) => {
        if (!allowedCompilerPath(root, fileName)) return undefined;
        return getSourceFile(
            fileName,
            languageVersion,
            onError,
            shouldCreateNewSourceFile,
        );
    };
    host.fileExists = (file) =>
        allowedCompilerPath(root, file) && ts.sys.fileExists(file);
    host.readFile = (file) =>
        allowedCompilerPath(root, file) ? ts.sys.readFile(file) : undefined;
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
        const line =
            sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile))
                .line + 1;
        return `{anonymous}@${line}`;
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
    const names = [];
    let current = declaration;
    while (current && !ts.isSourceFile(current)) {
        const name = declarationName(current, declaration.getSourceFile());
        if (name !== null) names.unshift(name);
        current = current.parent;
    }
    if (memberDeclaration(declaration) && names.length >= 2) {
        const member = names.pop();
        return `${relative}#${names.join(".")}::${member}`;
    }
    return `${relative}#${names.join(".")}`;
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
    return new TypeScriptScanner().discover({ root }).config_files;
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
    const maxFileBytes = Number.isInteger(limits?.max_file_bytes)
        ? limits.max_file_bytes
        : 2_000_000;
    if (maxFiles < 1 || maxFileBytes < 1 || files.length > maxFiles)
        throw new Error("TypeScript scan limits are invalid or exceeded.");

    return files.map((relative) => {
        const absolute = validatedInside(root, relative);
        if (!SOURCE_EXTENSIONS.has(path.extname(absolute).toLowerCase()))
            throw new Error(`Unsupported TypeScript input: ${relative}`);
        const stat = fs.statSync(absolute);
        if (!stat.isFile() || stat.size > maxFileBytes)
            throw new Error(`TypeScript input exceeds limits: ${relative}`);
        return normalize(path.relative(root, absolute));
    });
}

function validateRoot(input) {
    if (typeof input !== "string" || input.length === 0)
        throw new Error("A project root is required.");
    const root = normalize(fs.realpathSync(input));
    if (!fs.statSync(root).isDirectory())
        throw new Error("Project root is not a directory.");
    return root;
}

function validatedInside(root, relative) {
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
    const real = normalize(fs.realpathSync(path.join(root, relative)));
    if (!contains(root, real))
        throw new Error("Project-relative path escapes the root.");
    return real;
}

function allowedCompilerPath(root, candidate) {
    const normalized = normalize(path.resolve(candidate));
    const defaultLib = normalize(path.dirname(ts.getDefaultLibFilePath({})));
    if (contains(defaultLib, normalized)) return true;
    if (!contains(root, normalized)) return false;
    try {
        return contains(root, normalize(fs.realpathSync(normalized)));
    } catch {
        return true;
    }
}

function relativeInside(root, candidate) {
    const normalized = normalize(path.resolve(candidate));
    if (!contains(root, normalized)) return null;
    return normalize(path.relative(root, normalized));
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
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        if (EXCLUDED_DIRECTORIES.has(entry.name)) continue;
        const absolute = path.join(directory, entry.name);
        const relative = normalize(path.relative(root, absolute));
        if (entry.isSymbolicLink()) continue;
        if (entry.isDirectory()) walk(root, absolute, onFile);
        else if (entry.isFile()) onFile(absolute, relative);
    }
}
