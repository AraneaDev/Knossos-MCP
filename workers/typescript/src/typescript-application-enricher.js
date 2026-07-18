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
        if (
            this.context.sourceFile.languageVariant ===
                ts.LanguageVariant.JSX &&
            /^[A-Z]/.test(name) &&
            (functionNode !== null || ts.isClassDeclaration(node))
        )
            roles.push("react.component");
        if (functionNode && /^use[A-Z0-9]/.test(name)) roles.push("react.hook");
        if (functionNode && /^use[A-Z0-9]/.test(name) && lower.includes("vue"))
            roles.push("vue.composable");
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
        if (
            functionNode &&
            /^[A-Z]/.test(node.name.text) &&
            this.context.sourceFile.languageVariant === ts.LanguageVariant.JSX
        )
            roles.push("react.component");
        if (functionNode && /^use[A-Z0-9]/.test(node.name.text))
            roles.push("react.hook");
        if (
            functionNode &&
            /^use[A-Z0-9]/.test(node.name.text) &&
            this.context.relative.toLowerCase().includes("vue")
        )
            roles.push("vue.composable");
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
