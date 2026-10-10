/**
 * Imports no static import declaration names.
 *
 * The default export and destructured exports of `import('./x')`, the
 * files webpack's `require.context` and Vite's `import.meta.glob` load,
 * a source path a call names by literal, and a bundler's `entryPoints`.
 * Each is an edge the fact collector would otherwise miss.
 */

import path from "node:path";
import ts from "typescript";
import {
    isDirnameExpression,
    sourcePathTarget,
    unwrapParentheses,
} from "./module-entry.js";
import { normalize, realSourcePath, relativeInside } from "./project-paths.js";
import {
    globBase,
    globContext,
    isImportMetaGlob,
    isRequireContext,
    regularExpressionOf,
    relativeGlob,
} from "./syntax-predicates.js";
import { reference } from "./typescript-fact-utils.js";

/** Adds the edges of imports no static import declaration names. */
export class DynamicImportCollector {
    constructor(context) {
        this.context = context;
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
        const moduleSymbol =
            this.context.checker.getSymbolAtLocation(specifier);
        if (!moduleSymbol) return;
        const exported = this.context.checker.tryGetMemberInModuleExports(
            "default",
            moduleSymbol,
        );
        const target = exported
            ? this.context.symbolReference(exported, "function", true)
            : null;
        const source = this.context.currentSource();
        if (source !== null && target !== null && source !== target)
            this.context.addEdge("references", source, target, specifier);
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
        if (this.context.internalModuleTarget(specifier) === null) return;
        const moduleSymbol =
            this.context.checker.getSymbolAtLocation(specifier);
        if (!moduleSymbol) return;
        const source = this.context.currentSource();
        for (const element of node.name.elements) {
            if (element.dotDotDotToken) continue;
            const name = element.propertyName ?? element.name;
            if (!ts.isIdentifier(name) && !ts.isStringLiteral(name)) continue;
            const exported = this.context.checker.tryGetMemberInModuleExports(
                name.text,
                moduleSymbol,
            );
            const target = exported
                ? this.context.symbolReference(exported, "function", true)
                : null;
            if (source !== null && target !== null && source !== target)
                this.context.addEdge("references", source, target, element);
        }
    }

    /**
     * A speculative import of a project module a source path names as a
     * literal: the only evidence, so the core keeps the edge only when the
     * graph holds that module.
     */
    speculativeImport(relative, node) {
        this.context.addEdge(
            "imports",
            this.context.currentSource() ?? this.context.moduleId,
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
            absolute === null
                ? null
                : relativeInside(this.context.root, absolute);
        const pattern =
            patternArg === undefined
                ? { source: "^\\.\\/.*$", flags: "" }
                : regularExpressionOf(patternArg);
        if (directory === null || pattern === null) return;
        this.context.addEdge(
            "imports",
            this.context.currentSource() ?? this.context.moduleId,
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
                ? normalize(
                      path.resolve(this.context.root, "." + glob.directory),
                  )
                : this.contextDirectory(glob.directory);
            const directory =
                absolute === null
                    ? null
                    : relativeInside(this.context.root, absolute);
            if (directory === null) continue;
            this.context.addEdge(
                "imports",
                this.context.currentSource() ?? this.context.moduleId,
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
            realSourcePath(normalize(this.context.sourceFile.fileName)),
        );
        if (specifier.startsWith("."))
            return normalize(path.resolve(here, specifier));
        const options = this.context.project.options ?? {};
        const base =
            options.pathsBasePath ?? options.baseUrl ?? this.context.root;
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
                ? [path.dirname(this.context.sourceFile.fileName)]
                : [
                      path.dirname(this.context.sourceFile.fileName),
                      this.context.root,
                  ];
            for (const base of bases) {
                const relative = sourcePathTarget(
                    this.context.root,
                    base,
                    joined,
                );
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
                    this.context.root,
                    this.context.root,
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
                this.context.root,
                path.dirname(this.context.sourceFile.fileName),
                value.text,
            );
            if (relative !== null) this.speculativeImport(relative, value);
        }
    }
}
