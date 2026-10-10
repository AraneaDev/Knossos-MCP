/**
 * How the worker classifies a declaration and the position a reference holds.
 *
 * The fact collector asks these questions of a node while it walks a file:
 * what kind of declaration a node is and the name and canonical name it is
 * known by, whether an object literal is a contract or a value, whether an
 * import or declaration is type-only, and whether an identifier stands where
 * its value is used rather than merely named. None of them reads a file or
 * holds state.
 */

import ts from "typescript";
import { nodeBuiltinPackage } from "./node-builtins.js";
import {
    bindingKeyword,
    declarationModifiers,
    holderOf,
    isExpressionWrapper,
    isFunctionBinding,
    unwrapExpression,
} from "./typescript-fact-utils.js";

export function declarationDescriptor(node, sourceFile) {
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

export function declarationKind(node, fallback) {
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
        if (node.name.text !== "") return node.name.text;
        // Never an empty name, which the core refuses along with every fact of
        // the language. `""` is a legal member name and is written as one; a
        // name the parser could not read (`export function (( {`) recovers as
        // an empty identifier and is named by its position.
        return ts.isStringLiteral(node.name)
            ? '""'
            : anonymousName(node, sourceFile);
    }
    if (
        (ts.isClassDeclaration(node) ||
            ts.isClassExpression(node) ||
            ts.isFunctionDeclaration(node) ||
            isContextualObjectLiteral(node)) &&
        !node.name
    ) {
        return anonymousName(node, sourceFile);
    }
    return null;
}

/** The name of a declaration that has none: its kind of label and its position. */
function anonymousName(node, sourceFile) {
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

// Inside `declare global { ... }` or `declare module 'x' { ... }`: a
// description of something the runtime or another package defines, which is
// no more code than a `.d.ts` is. The block itself counts as inside.
function insideAmbientDeclaration(node) {
    for (let current = node; current; current = current.parent) {
        // `declare global`, `declare module 'x'`, and `declare namespace L`,
        // which describes a library a script tag loads.
        if (
            ts.isModuleDeclaration(current) &&
            ((current.flags & ts.NodeFlags.GlobalAugmentation) !== 0 ||
                ts.isStringLiteral(current.name) ||
                (ts.getCombinedModifierFlags(current) &
                    ts.ModifierFlags.Ambient) !==
                    0)
        )
            return true;
    }
    return false;
}

export function isDeclaration(node) {
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
export function isObjectLiteralBinding(node) {
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
export function isContextualObjectLiteral(node) {
    if (
        !ts.isObjectLiteralExpression(node) ||
        !node.properties.some((member) => ts.isMethodDeclaration(member))
    )
        return false;
    const current = holderOf(node);
    return !(
        current !== undefined &&
        ts.isVariableDeclaration(current) &&
        isObjectLiteralBinding(current)
    );
}

// The object literal an initializer evaluates to, through parentheses, `as`
// and `satisfies`, or null.
function objectLiteralOf(expression) {
    const current = unwrapExpression(expression);
    return current !== undefined && ts.isObjectLiteralExpression(current)
        ? current
        : null;
}

// The type an object-literal binding declares it implements: its annotation,
// or the nearest `satisfies`.
export function objectLiteralContract(node) {
    if (node.type !== undefined) return node.type;
    let current = node.initializer;
    while (current !== undefined && isExpressionWrapper(current)) {
        if (ts.isSatisfiesExpression(current)) return current.type;
        current = current.expression;
    }
    return undefined;
}

/** A declaration that names only a type: no value is behind it. */
export function isTypeOnlyDeclaration(node) {
    return ts.isTypeAliasDeclaration(node) || ts.isInterfaceDeclaration(node);
}

export function containerDeclaration(node) {
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

export function memberDeclaration(node) {
    return (
        ts.isMethodDeclaration(node) ||
        ts.isMethodSignature(node) ||
        ts.isConstructorDeclaration(node) ||
        ts.isPropertyDeclaration(node) ||
        ts.isPropertySignature(node)
    );
}

export function canonicalForDeclaration(declaration, relative) {
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
export function referenceableDeclaration(node) {
    return (
        ts.isFunctionDeclaration(node) ||
        ts.isMethodDeclaration(node) ||
        // An interface's method, read off a receiver typed by the interface
        // and handed on: `{ tick: board.tick }`.
        ts.isMethodSignature(node) ||
        ts.isClassDeclaration(node) ||
        ts.isInterfaceDeclaration(node) ||
        ts.isEnumDeclaration(node) ||
        ts.isTypeAliasDeclaration(node) ||
        isFunctionBinding(node) ||
        isObjectLiteralBinding(node)
    );
}

/** A declaration's attributes, marked `ambient` inside `declare global` / `declare module`. */
export function ambientAttributes(node, attributes) {
    return insideAmbientDeclaration(node)
        ? { ...attributes, ambient: true }
        : attributes;
}

/** `handler = run;`: assigned to a variable or field declared elsewhere. */
function isAssignedValue(parent, node) {
    return (
        ts.isBinaryExpression(parent) &&
        parent.operatorToken.kind === ts.SyntaxKind.EqualsToken &&
        parent.right === node
    );
}

/** `return handler;` and `() => handler`. */
function isReturnedValue(parent, node) {
    return (
        (ts.isReturnStatement(parent) && parent.expression === node) ||
        (ts.isArrowFunction(parent) && parent.body === node)
    );
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
export function isNamePosition(node) {
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
export function valueReferencePosition(node) {
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
    if (isChoiceOperand(parent, node) || isAssignedValue(parent, node))
        return true;
    // `handler;` and `(handler)`: how a component's tags and event handlers
    // reach the checker (see component-source.js), and a value use anywhere.
    if (isBareValue(parent, node)) return true;
    // `<Button onClick={addItem}>` and `{renderRow}`: a function handed to
    // React inside JSX, as a prop or a child.
    if (ts.isJsxExpression(parent) && parent.expression === node) return true;
    if (isReturnedValue(parent, node)) return true;
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
    // `handler.bind(ctx)`, `this.handler.call(ctx)`, `fn.apply(ctx, args)`:
    // the function is handed on or invoked through Function.prototype, so
    // the checker resolves the call to `bind` and never to the function.
    if (boundFunction(parent) === node) return true;

    return false;
}

/**
 * The function a `.bind(...)`, `.call(...)` or `.apply(...)` call is made
 * on, given the `x.bind` access, or undefined.
 */
export function boundFunction(access) {
    return ts.isPropertyAccessExpression(access) &&
        ["bind", "call", "apply"].includes(access.name.text) &&
        ts.isCallExpression(access.parent) &&
        access.parent.expression === access
        ? access.expression
        : undefined;
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
export function importIsTypeOnly(clause) {
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

export function callableKind(declaration) {
    return declaration &&
        (ts.isMethodDeclaration(declaration) ||
            ts.isMethodSignature(declaration))
        ? "method"
        : "function";
}

export function unalias(checker, symbol) {
    if (!symbol) return undefined;
    return (symbol.flags & ts.SymbolFlags.Alias) !== 0
        ? checker.getAliasedSymbol(symbol)
        : symbol;
}

/**
 * A name npm can publish: an optional scope and a name of letters, digits,
 * `-`, `.` and `_`, neither starting with `.`, `_` or `-`. Capitals are
 * allowed, as older packages have them.
 */
const NPM_PACKAGE_NAME = /^(?:@[a-z0-9][a-z0-9._-]*\/)?[a-z0-9][a-z0-9._-]*$/i;

/**
 * The package a specifier names, or null when it names none.
 *
 * Only a name npm can publish is a package. A specifier nothing resolved
 * that is not one (`@/components`, `~/stores/user`, `$lib/x`, a bundler's
 * `virtual:` module) is a path under a name the project's bundler gives it,
 * and no dependency. A `node:` specifier is named by nodeBuiltinPackage.
 */
export function externalPackageName(specifier) {
    if (specifier.startsWith("node:")) return nodeBuiltinPackage(specifier);
    // A built-in Node also offers bare, `_http_agent` included, which npm's
    // grammar would refuse for its leading underscore.
    const builtin = nodeBuiltinPackage(`node:${specifier}`);
    if (builtin !== null && !builtin.startsWith("node:")) return builtin;
    const parts = specifier.split("/");
    const name = specifier.startsWith("@")
        ? parts.slice(0, 2).join("/")
        : parts[0];
    return NPM_PACKAGE_NAME.test(name) ? name : null;
}

export function evidence(sourceFile, relative, node) {
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
