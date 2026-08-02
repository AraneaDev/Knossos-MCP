import path from "node:path";
import ts from "typescript";
import {
    addFrameworkRoute,
    callName,
    propertyNameText,
    reference,
} from "./typescript-fact-utils.js";

/** Adds framework-convention facts without owning or traversing the AST. */
export class TypeScriptApplicationEnricher {
    constructor(context) {
        this.context = context;
    }

    declaration(node, id, canonical, name) {
        const roles = [];
        const lower = this.context.relative.toLowerCase();
        const base = path.basename(lower).replace(/\.(tsx?|jsx?)$/, "");
        const exported =
            hasModifier(node, ts.SyntaxKind.ExportKeyword) ||
            hasModifier(node, ts.SyntaxKind.DefaultKeyword);
        if (base === "page" && exported) roles.push("nextjs.page");
        if (base === "layout" && exported) roles.push("nextjs.layout");
        if (
            base === "route" &&
            exported &&
            [
                "GET",
                "POST",
                "PUT",
                "PATCH",
                "DELETE",
                "HEAD",
                "OPTIONS",
            ].includes(name)
        ) {
            roles.push("nextjs.route_handler");
            const routePath = nextRoutePath(this.context.relative);
            const routeCanonical = `${name} ${routePath} => ${canonical}`;
            const routeId = reference("route", routeCanonical);
            addFrameworkRoute(this.context, {
                id: routeId,
                canonical: routeCanonical,
                displayName: `${name} ${routePath}`,
                node,
                framework: "nextjs",
                httpMethod: name,
                path: routePath,
                target: id,
            });
        }
        const functionNode = isFunctionLike(node)
            ? node
            : ts.isVariableDeclaration(node) &&
                node.initializer &&
                isFunctionLike(node.initializer)
              ? node.initializer
              : null;
        if (functionNode && hasUseDirective(functionNode, "use server"))
            roles.push("nextjs.server_action");
        roles.push(
            ...componentRoles(
                this.context.sourceFile,
                name,
                functionNode !== null || ts.isClassDeclaration(node),
                functionNode !== null,
                node,
            ),
        );
        const initializer = ts.isVariableDeclaration(node)
            ? node.initializer
            : null;
        const factory =
            initializer && ts.isCallExpression(initializer)
                ? callName(initializer.expression)
                : null;
        if (factory === "defineComponent") roles.push("vue.component");
        if (
            ["defineStore", "createStore", "configureStore", "create"].includes(
                factory,
            )
        )
            roles.push("state.store");
        return [...new Set(roles)].sort();
    }

    variable(node) {
        if (!ts.isIdentifier(node.name) || !node.initializer) return;
        const initializer = node.initializer;
        const factory = ts.isCallExpression(initializer)
            ? callName(initializer.expression)
            : null;
        const functionNode = isFunctionLike(initializer) ? initializer : null;
        const roles = [];
        if (factory === "defineComponent") roles.push("vue.component");
        if (
            ["defineStore", "createStore", "configureStore", "create"].includes(
                factory,
            )
        )
            roles.push("state.store");
        if (functionNode)
            roles.push(
                ...componentRoles(
                    this.context.sourceFile,
                    node.name.text,
                    true,
                    true,
                    functionNode,
                ),
            );
        if (functionNode && hasUseDirective(functionNode, "use server"))
            roles.push("nextjs.server_action");
        if (roles.length === 0) return;
        const canonical = `${this.context.relative}#${node.name.text}`;
        const id = reference("variable", canonical);
        this.context.addNode(id, "variable", canonical, node.name.text, node, {
            typescript_framework_roles: [...new Set(roles)].sort(),
        });
        this.context.addEdge("contains", this.context.moduleId, id, node);
    }

    call(node, source, calledName) {
        if (
            source === null ||
            ![
                "fetch",
                "axios.get",
                "axios.post",
                "axios.put",
                "axios.patch",
                "axios.delete",
            ].includes(calledName) ||
            !node.arguments[0] ||
            (!ts.isStringLiteral(node.arguments[0]) &&
                !ts.isNoSubstitutionTemplateLiteral(node.arguments[0]))
        )
            return;
        const method =
            calledName === "fetch"
                ? fetchMethod(node)
                : calledName.split(".").at(-1).toUpperCase();
        const uri = node.arguments[0].text;
        const endpointId = reference("endpoint", `${method} ${uri}`);
        this.context.addNode(
            endpointId,
            "endpoint",
            `${method} ${uri}`,
            `${method} ${uri}`,
            node,
            { framework: "web", method, uri },
            "framework_convention",
        );
        this.context.addEdge(
            "calls_endpoint",
            source,
            endpointId,
            node,
            {},
            "framework_convention",
        );
    }
}

function fetchMethod(call) {
    const options = call.arguments[1];
    if (!options || !ts.isObjectLiteralExpression(options)) return "GET";
    const property = options.properties.find(
        (item) =>
            ts.isPropertyAssignment(item) &&
            propertyNameText(item.name) === "method",
    );
    return property &&
        ts.isPropertyAssignment(property) &&
        (ts.isStringLiteral(property.initializer) ||
            ts.isNoSubstitutionTemplateLiteral(property.initializer))
        ? property.initializer.text.toUpperCase()
        : "GET";
}

function hasModifier(node, kind) {
    return (
        ts.canHaveModifiers(node) &&
        (ts.getModifiers(node) ?? []).some((modifier) => modifier.kind === kind)
    );
}

function isFunctionLike(node) {
    return (
        ts.isFunctionDeclaration(node) ||
        ts.isMethodDeclaration(node) ||
        ts.isArrowFunction(node) ||
        ts.isFunctionExpression(node)
    );
}

/**
 * The React/Vue roles a named declaration earns, if any.
 *
 * Each rule is keyed on evidence from the code rather than on the file's name
 * or extension: a component renders JSX, and a `use`-prefixed function is a
 * hook or a composable according to which library the file actually imports.
 */
function componentRoles(sourceFile, name, componentShaped, callable, body) {
    const roles = [];
    if (componentShaped && /^[A-Z]/.test(name) && containsJsx(body))
        roles.push("react.component");
    if (callable && /^use[A-Z0-9]/.test(name)) {
        if (importsModule(sourceFile, "react")) roles.push("react.hook");
        if (importsModule(sourceFile, "vue")) roles.push("vue.composable");
    }
    return roles;
}

/**
 * Whether a declaration actually contains JSX.
 *
 * The TypeScript compiler reports languageVariant JSX for every .js, .jsx, .mjs
 * and .cjs file — only .ts is Standard — so that flag cannot distinguish a
 * React component from any other capitalized declaration in a JavaScript
 * project. Looking for JSX in the declaration itself does, and it keeps working
 * for the plenty of real React code that lives in plain .js files.
 */
function containsJsx(node) {
    let found = false;
    const walk = (current) => {
        if (found) return;
        if (
            ts.isJsxElement(current) ||
            ts.isJsxSelfClosingElement(current) ||
            ts.isJsxFragment(current)
        ) {
            found = true;
            return;
        }
        ts.forEachChild(current, walk);
    };
    ts.forEachChild(node, walk);
    return found;
}

/**
 * Whether the file imports the given package, by import declaration or require.
 *
 * A hook and a composable are both just a `use`-prefixed function; only the
 * library in scope says which — or whether it is neither, as with a
 * `useDatabase()` helper in a plain Node service.
 */
function importsModule(sourceFile, packageName) {
    const cache = (sourceFile.knossosImportedModules ??=
        collectImports(sourceFile));
    return cache.has(packageName);
}

function collectImports(sourceFile) {
    const modules = new Set();
    const record = (specifier) => {
        if (typeof specifier !== "string" || specifier === "") return;
        // "react-dom/client" and "react/jsx-runtime" both count as react.
        modules.add(specifier.split("/")[0].replace(/^(@[^/]+)$/, "$1"));
    };
    const walk = (node) => {
        if (
            (ts.isImportDeclaration(node) || ts.isExportDeclaration(node)) &&
            node.moduleSpecifier &&
            ts.isStringLiteral(node.moduleSpecifier)
        ) {
            record(node.moduleSpecifier.text);
        } else if (
            ts.isCallExpression(node) &&
            (node.expression.kind === ts.SyntaxKind.ImportKeyword ||
                (ts.isIdentifier(node.expression) &&
                    node.expression.text === "require")) &&
            node.arguments[0] &&
            ts.isStringLiteral(node.arguments[0])
        ) {
            record(node.arguments[0].text);
        }
        ts.forEachChild(node, walk);
    };
    walk(sourceFile);
    return modules;
}

function hasUseDirective(node, directive) {
    return (
        node.body &&
        ts.isBlock(node.body) &&
        node.body.statements.some(
            (statement) =>
                ts.isExpressionStatement(statement) &&
                ts.isStringLiteral(statement.expression) &&
                statement.expression.text === directive,
        )
    );
}

function nextRoutePath(relative) {
    const normalized = relative.replaceAll("\\", "/");
    const marker = normalized.includes("/app/")
        ? normalized.split("/app/").at(-1)
        : normalized.replace(/^app\//, "");
    const parts = marker
        .split("/")
        .slice(0, -1)
        .filter((part) => !/^\(.+\)$/.test(part));
    return `/${parts.join("/")}`.replaceAll("//", "/") || "/";
}
