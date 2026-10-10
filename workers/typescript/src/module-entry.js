/**
 * How a module names itself and whether it runs as an entry point.
 *
 * A path resolved against `import.meta.url`, `__dirname` or
 * `import.meta.dirname` names the module it points at, and a main guard
 * (`import.meta.main`, `require.main === module`, or `import.meta.url`
 * compared with `process.argv[1]`) marks a file as a program entry. The
 * binding helpers confirm that each name involved is the import or global it
 * appears to be.
 */

import path from "node:path";
import ts from "typescript";
import {
    belowNodeModules,
    excludedByProjectLayout,
    normalize,
    relativeInside,
} from "./project-paths.js";

// Source extensions a module URL can name; an asset URL names none of them.
const MODULE_URL_EXTENSION = /\.(?:[cm]?[jt]sx?)$/;

// The project module `new URL('./gen.worker.ts', import.meta.url)` names: how
// Vite, webpack and the browser load a module worker, which no import names.
// Decided from the literal alone, so the answer cannot depend on which files
// share a request; a path that leaves the project, lands below node_modules or
// in build output, or names an asset, is no module of this project.
export function importMetaUrlModule(node, sourceFile, root) {
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
export function isDirnameExpression(expression) {
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
export function sourcePathTarget(root, directory, specifier) {
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
// entered: `import.meta.main` (Bun, Deno), CommonJS `require.main === module`,
// or the ES-module comparison of `import.meta.url` with `process.argv[1]`.
// This is JavaScript's `__main__` guard, and it says the same thing a shebang
// does: something outside the graph runs the file, so no inbound edge is owed.
// A guard nested in a function, or a negated one, says nothing about that, and
// neither does one whose names the file binds to something of its own.
export function hasMainGuard(sourceFile, checker) {
    return sourceFile.statements.some(
        (statement) =>
            ts.isIfStatement(statement) &&
            isMainGuardCondition(
                unwrapParentheses(statement.expression),
                checker,
            ),
    );
}

function isMainGuardCondition(expression, checker) {
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
    const isModule = (node) => isUnshadowed(node, "module", checker);
    if (
        (isRequireMain(left, checker) && isModule(right)) ||
        (isModule(left) && isRequireMain(right, checker))
    )
        return true;
    // The ES-module form compares this module's location with the script node
    // was started on, as two URLs or as two paths. A URL never equals a path,
    // so a comparison that mixes them is no guard at all.
    const isEntry = (node) => isScriptArgument(node, checker);
    const self = locationOf(left, isImportMetaUrl, checker);
    const entry = locationOf(right, isEntry, checker);
    if (self !== null && self === entry) return true;
    const reversedSelf = locationOf(right, isImportMetaUrl, checker);
    const reversedEntry = locationOf(left, isEntry, checker);
    return reversedSelf !== null && reversedSelf === reversedEntry;
}

const URL_MODULES = ["url", "node:url"];

const FS_MODULES = ["fs", "node:fs"];

const PROCESS_MODULES = ["process", "node:process"];

/**
 * Whether an expression is the location of `isSource`'s value, and in which
 * form: "url" for a file URL, "path" for a file-system path. The conversions
 * `fileURLToPath`, `pathToFileURL(...).href` and `realpathSync`, imported from
 * node's own modules, keep the location while changing or normalising its
 * form; anything else loses it.
 *
 * @returns {"url" | "path" | null}
 */
function locationOf(expression, isSource, checker) {
    const current = unwrapParentheses(expression);
    if (isSource(current)) return isImportMetaUrl(current) ? "url" : "path";
    const inner = (call) => locationOf(call.arguments[0], isSource, checker);
    if (
        ts.isPropertyAccessExpression(current) &&
        current.name.text === "href"
    ) {
        const call = unwrapParentheses(current.expression);
        return isImportedCall(call, "pathToFileURL", URL_MODULES, checker) &&
            inner(call) === "path"
            ? "url"
            : null;
    }
    if (isImportedCall(current, "fileURLToPath", URL_MODULES, checker))
        return inner(current) === "url" ? "path" : null;
    if (isImportedCall(current, "realpathSync", FS_MODULES, checker))
        return inner(current) === "path" ? "path" : null;
    return null;
}

/**
 * A one-argument call to the export `name` of one of `modules`: through a
 * named import under any local name, or as a member of the module's default
 * or namespace import (`url.fileURLToPath`).
 */
function isImportedCall(expression, name, modules, checker) {
    if (!ts.isCallExpression(expression) || expression.arguments.length !== 1)
        return false;
    const callee = unwrapParentheses(expression.expression);
    if (ts.isIdentifier(callee)) {
        const binding = importBinding(callee, checker);
        return binding !== null && modules.includes(binding.module)
            ? binding.imported === name
            : false;
    }
    if (
        !ts.isPropertyAccessExpression(callee) ||
        callee.name.text !== name ||
        !ts.isIdentifier(callee.expression)
    )
        return false;
    return isModuleObject(callee.expression, modules, checker);
}

/** An identifier bound to a module's default or namespace import. */
function isModuleObject(identifier, modules, checker) {
    const binding = importBinding(identifier, checker);
    return (
        binding !== null &&
        modules.includes(binding.module) &&
        (binding.imported === "default" || binding.imported === "*")
    );
}

/**
 * The import an identifier is bound to: the module it names and the export
 * (`"default"`, `"*"` for a namespace, or the export's own name). Null when
 * the identifier is bound to anything else, or to nothing.
 *
 * @returns {{module: string, imported: string} | null}
 */
function importBinding(identifier, checker) {
    const declarations =
        checker.getSymbolAtLocation(identifier)?.declarations ?? [];
    if (declarations.length !== 1) return null;
    const [declaration] = declarations;
    let imported;
    let clause;
    if (ts.isImportSpecifier(declaration)) {
        imported = (declaration.propertyName ?? declaration.name).text;
        clause = declaration.parent.parent;
    } else if (ts.isNamespaceImport(declaration)) {
        imported = "*";
        clause = declaration.parent;
    } else if (ts.isImportClause(declaration)) {
        imported = "default";
        clause = declaration;
    } else return requireBinding(declaration);
    const specifier = clause.parent.moduleSpecifier;
    return ts.isStringLiteral(specifier)
        ? { module: specifier.text, imported }
        : null;
}

/**
 * The same for a CommonJS `require`: `const url = require("url")` binds the
 * whole module, `const { fileURLToPath: toPath } = require("url")` one export.
 *
 * @returns {{module: string, imported: string} | null}
 */
function requireBinding(declaration) {
    if (
        ts.isVariableDeclaration(declaration) &&
        ts.isIdentifier(declaration.name)
    ) {
        const module = requiredModule(declaration.initializer);
        return module === null ? null : { module, imported: "*" };
    }
    if (
        !ts.isBindingElement(declaration) ||
        declaration.dotDotDotToken !== undefined ||
        !ts.isObjectBindingPattern(declaration.parent) ||
        !ts.isVariableDeclaration(declaration.parent.parent)
    )
        return null;
    const module = requiredModule(declaration.parent.parent.initializer);
    const property = declaration.propertyName ?? declaration.name;
    return module !== null &&
        (ts.isIdentifier(property) || ts.isStringLiteral(property))
        ? { module, imported: property.text }
        : null;
}

/** The module `require("name")` loads, or null for any other expression. */
function requiredModule(expression) {
    if (expression === undefined) return null;
    const call = unwrapParentheses(expression);
    return ts.isCallExpression(call) &&
        isIdentifierNamed(unwrapParentheses(call.expression), "require") &&
        call.arguments.length === 1 &&
        ts.isStringLiteral(call.arguments[0])
        ? call.arguments[0].text
        : null;
}

/**
 * Whether `node` is the identifier `name` and no binding the file declares in
 * a scope holding it could be what it refers to: it is the global, or
 * CommonJS's own `module`, `exports` or `require`. Only a real binding counts.
 * Assigning `module.exports` or `exports.x` gives the file a binder symbol of
 * that name too, and that symbol is CommonJS's own, not a local.
 */
function isUnshadowed(node, name, checker) {
    if (!isIdentifierNamed(node, name)) return false;
    const sourceFile = node.getSourceFile();
    return !(checker.getSymbolAtLocation(node)?.declarations ?? []).some(
        (declaration) =>
            isLocalBinding(declaration) &&
            declaration.getSourceFile() === sourceFile,
    );
}

/**
 * A declaration that binds a name in its scope, as code writes it. An ambient
 * one (`declare const process`, anything in a `declare` block) describes what
 * the runtime defines and binds nothing of its own.
 */
function isLocalBinding(declaration) {
    if (
        (declaration.flags & ts.NodeFlags.Ambient) !== 0 ||
        (ts.getCombinedModifierFlags(declaration) &
            ts.ModifierFlags.Ambient) !==
            0
    )
        return false;
    return (
        // Covers a catch clause's binding too.
        ts.isVariableDeclaration(declaration) ||
        ts.isBindingElement(declaration) ||
        ts.isParameter(declaration) ||
        ts.isFunctionDeclaration(declaration) ||
        ts.isClassDeclaration(declaration) ||
        ts.isEnumDeclaration(declaration) ||
        ts.isModuleDeclaration(declaration) ||
        ts.isImportClause(declaration) ||
        ts.isImportSpecifier(declaration) ||
        ts.isNamespaceImport(declaration) ||
        ts.isImportEqualsDeclaration(declaration)
    );
}

/** `process.argv[1]`: the path of the script node was started on. */
function isScriptArgument(expression, checker) {
    if (
        !ts.isElementAccessExpression(expression) ||
        !ts.isNumericLiteral(expression.argumentExpression) ||
        expression.argumentExpression.text !== "1" ||
        !ts.isPropertyAccessExpression(expression.expression) ||
        expression.expression.name.text !== "argv"
    )
        return false;
    const process = expression.expression.expression;
    return (
        isUnshadowed(process, "process", checker) ||
        (ts.isIdentifier(process) &&
            isModuleObject(process, PROCESS_MODULES, checker))
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

function isRequireMain(expression, checker) {
    return (
        ts.isPropertyAccessExpression(expression) &&
        expression.name.text === "main" &&
        isUnshadowed(expression.expression, "require", checker)
    );
}

function isIdentifierNamed(expression, name) {
    return ts.isIdentifier(expression) && expression.text === name;
}

export function unwrapParentheses(expression) {
    let current = expression;
    while (ts.isParenthesizedExpression(current)) current = current.expression;
    return current;
}
