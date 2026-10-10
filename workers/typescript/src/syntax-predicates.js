/**
 * Syntax predicates the fact collector applies to a node.
 *
 * Whether a file is a classic script or a k6 script, what a Vue options
 * object or a store's members are, and how `import.meta.glob` and webpack's
 * `require.context` name the files they load. Each one looks only at the
 * syntax tree it is given.
 */

import path from "node:path";
import ts from "typescript";

/** The initializer of `key` in an object literal, or undefined. */
/** A module that imports `k6` or one of its `k6/...` modules. */
export function isK6Script(sourceFile) {
    return sourceFile.statements.some(
        (statement) =>
            ts.isImportDeclaration(statement) &&
            ts.isStringLiteral(statement.moduleSpecifier) &&
            (statement.moduleSpecifier.text === "k6" ||
                statement.moduleSpecifier.text.startsWith("k6/")),
    );
}

export function objectField(node, key) {
    if (!ts.isObjectLiteralExpression(node)) return undefined;
    return node.properties.find(
        (property) =>
            ts.isPropertyAssignment(property) &&
            staticPropertyName(property.name) === key,
    )?.initializer;
}

/**
 * The store members a call asks for by name: `dispatch('cookie/setInternal')`
 * and `commit('SET_INTERNAL')` name an action or a mutation, and
 * `mapActions`, `mapMutations` and `mapGetters` name them in an array or as
 * an object's values. A namespace passed on its own names no member.
 */
export function storeMemberNames(node) {
    const callee = node.expression;
    const name = ts.isIdentifier(callee)
        ? callee.text
        : ts.isPropertyAccessExpression(callee)
          ? callee.name.text
          : null;
    const member = (text) => text.slice(text.lastIndexOf("/") + 1);
    if (name === "dispatch" || name === "commit") {
        const first = node.arguments[0];
        return first !== undefined && ts.isStringLiteralLike(first)
            ? [member(first.text)].filter((text) => text !== "")
            : [];
    }
    if (!["mapActions", "mapMutations", "mapGetters"].includes(name)) return [];
    const names = [];
    for (const argument of node.arguments) {
        const values = ts.isArrayLiteralExpression(argument)
            ? argument.elements
            : ts.isObjectLiteralExpression(argument)
              ? argument.properties.map((property) =>
                    ts.isPropertyAssignment(property)
                        ? property.initializer
                        : undefined,
                )
              : [];
        for (const value of values)
            if (value !== undefined && ts.isStringLiteralLike(value))
                names.push(member(value.text));
    }
    return names.filter((text) => text !== "");
}

/**
 * A JavaScript file with no import, export, `require` or `module.exports`:
 * a page loads it with a `<script>` tag, or Node runs it, and nothing can
 * import anything from it. The binder has set both indicators by the time
 * facts are collected.
 */
export function isClassicScript(sourceFile) {
    return (
        /\.c?js$/.test(sourceFile.fileName) &&
        sourceFile.externalModuleIndicator === undefined &&
        sourceFile.commonJsModuleIndicator === undefined
    );
}

/**
 * A module that exports nothing and calls something when it loads: run, not
 * imported (`node scripts/seed.js` ending in `seed();`, or a bundler entry
 * awaiting its start). A file with no module syntax at all is left to
 * {@link isClassicScript}; one that exports, in either module system, can be
 * imported, whatever it calls on load.
 */
export function runsOnLoad(sourceFile) {
    if (
        sourceFile.externalModuleIndicator === undefined &&
        sourceFile.commonJsModuleIndicator === undefined
    ) {
        return false;
    }
    return (
        !exportsAnything(sourceFile) &&
        sourceFile.statements.some(
            (statement) =>
                ts.isExpressionStatement(statement) &&
                isCall(statement.expression),
        )
    );
}

/** A call, once `await`, `void` and parentheses around it are taken off. */
function isCall(expression) {
    let inner = expression;
    while (
        ts.isAwaitExpression(inner) ||
        ts.isVoidExpression(inner) ||
        ts.isParenthesizedExpression(inner)
    ) {
        inner = inner.expression;
    }
    return ts.isCallExpression(inner);
}

/**
 * Whether the file exports: an `export` of any form, or `module.exports` or
 * `exports` named anywhere in it (a UMD wrapper assigns them inside a
 * function), so a CommonJS export is never missed.
 */
function exportsAnything(sourceFile) {
    const exported = sourceFile.statements.some(
        (statement) =>
            ts.isExportDeclaration(statement) ||
            ts.isExportAssignment(statement) ||
            (ts.canHaveModifiers(statement) &&
                (ts.getModifiers(statement) ?? []).some(
                    (modifier) => modifier.kind === ts.SyntaxKind.ExportKeyword,
                )),
    );
    if (exported) {
        return true;
    }
    const namesExports = (node) =>
        (ts.isIdentifier(node) && node.text === "exports") ||
        ts.forEachChild(node, namesExports) === true;
    return ts.forEachChild(sourceFile, namesExports) === true;
}

/** What a `require` specifier may leave off, in the order it is tried. */
export const REQUIRE_SUFFIXES = [
    // Node's own order, then what a TypeScript loader adds.
    ...["", ".js", "/index.js"],
    ...[".ts", ".tsx", ".cts", ".mts", ".jsx", ".cjs", ".mjs", ".d.ts"],
    ...["ts", "tsx", "jsx", "d.ts"].map((extension) => `/index.${extension}`),
];

/** The literal `base` an `import.meta.glob` options object sets, or null. */
export function globBase(options) {
    if (options === undefined || !ts.isObjectLiteralExpression(options))
        return null;
    for (const property of options.properties) {
        if (
            ts.isPropertyAssignment(property) &&
            staticPropertyName(property.name) === "base" &&
            ts.isStringLiteralLike(property.initializer)
        )
            return property.initializer.text;
    }
    return null;
}

/** A relative glob read from `base`: `./*.vue` from `./Widgets` is `./Widgets/*.vue`. */
export function relativeGlob(base, pattern) {
    const joined = path.posix.join(base, pattern);
    return joined.startsWith("/") || joined.startsWith("../")
        ? joined
        : `./${joined}`;
}

/** `import.meta.glob(<literal or array>, …)`, Vite's glob import. */
export function isImportMetaGlob(node) {
    const callee = node.expression;
    return (
        ts.isPropertyAccessExpression(callee) &&
        ts.isMetaProperty(callee.expression) &&
        callee.expression.keywordToken === ts.SyntaxKind.ImportKeyword &&
        (callee.name.text === "glob" || callee.name.text === "globEager") &&
        node.arguments.length >= 1
    );
}

/**
 * A glob split into the directory before its first wildcard and a pattern
 * over `./<path below it>`, the key form a module context matches. Null for
 * a negated pattern, or one without a directory to anchor it.
 */
export function globContext(glob) {
    if (glob.startsWith("!")) return null;
    const segments = glob.split("/");
    const wild = segments.findIndex((segment) => /[*?[{]/.test(segment));
    if (wild <= 0) return null;
    const rest = segments.slice(wild).join("/");
    let pattern = "";
    for (let index = 0; index < rest.length; index++) {
        const char = rest[index];
        if (rest.startsWith("**/", index)) {
            pattern += "(?:.*/)?";
            index += 2;
        } else if (rest.startsWith("**", index)) {
            pattern += ".*";
            index += 1;
        } else if (char === "[" && rest.indexOf("]", index + 2) !== -1) {
            // `[ab]` and `[!ab]`: one character of the class, never a `/`.
            const close = rest.indexOf("]", index + 2);
            const body = rest.slice(index + 1, close);
            const negated = body.startsWith("!") || body.startsWith("^");
            const members = (negated ? body.slice(1) : body).replace(
                /[\\\]^]/g,
                "\\$&",
            );
            pattern += negated ? `[^/${members}]` : `[${members}]`;
            index = close;
        } else if (char === "*") pattern += "[^/]*";
        else if (char === "?") pattern += "[^/]";
        else if (char === "{") pattern += "(?:";
        else if (char === "}") pattern += ")";
        else if (char === ",") pattern += "|";
        else pattern += char.replace(/[.+^$()|[\]\\]/g, "\\$&");
    }
    return {
        directory: segments.slice(0, wild).join("/"),
        recursive: rest.includes("/") || rest.includes("**"),
        pattern: `^\\./${pattern}$`,
    };
}

/** `require.context('<literal>', …)`, webpack's directory import. */
export function isRequireContext(node) {
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
export function regularExpressionOf(node) {
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
export const VUE_HOOKS = new Set([
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
export function vueOptionsObject(sourceFile) {
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
export function memberName(member) {
    return member.name === undefined ? null : staticPropertyName(member.name);
}

/** A property name written as an identifier or a string, or null. */
export function staticPropertyName(name) {
    return ts.isIdentifier(name) || ts.isStringLiteral(name) ? name.text : null;
}
