import ts from "typescript";

export function callName(expression) {
    if (ts.isIdentifier(expression)) return expression.text;
    if (ts.isPropertyAccessExpression(expression)) {
        const owner = callName(expression.expression);
        return owner
            ? `${owner}.${expression.name.text}`
            : expression.name.text;
    }
    return null;
}

export function propertyNameText(name) {
    return ts.isIdentifier(name) ||
        ts.isStringLiteral(name) ||
        ts.isNumericLiteral(name)
        ? name.text
        : null;
}

export function reference(kind, canonical) {
    return `ts:${kind}:${canonical}`;
}

export function addFrameworkRoute(
    context,
    { id, canonical, displayName, node, framework, httpMethod, path, target },
) {
    context.addNode(
        id,
        "route",
        canonical,
        displayName,
        node,
        {
            framework,
            http_methods: [httpMethod],
            path,
        },
        "framework_convention",
    );
    context.addEdge(
        "routes_to",
        id,
        target,
        node,
        { framework },
        "framework_convention",
    );
}

/** Parentheses, `as` or `satisfies`: syntax around a value that is not a value of its own. */
export function isExpressionWrapper(node) {
    return (
        ts.isParenthesizedExpression(node) ||
        ts.isAsExpression(node) ||
        ts.isSatisfiesExpression(node)
    );
}

/** An expression's value through parentheses, `as` and `satisfies`. */
export function unwrapExpression(expression) {
    let current = expression;
    while (current !== undefined && isExpressionWrapper(current))
        current = current.expression;
    return current;
}

/** The node holding an expression, past any parentheses, `as` and `satisfies` around it. */
export function holderOf(node) {
    let current = node.parent;
    while (current !== undefined && isExpressionWrapper(current))
        current = current.parent;
    return current;
}

/**
 * `const f = () => {}` or `var f = function () {}` as a statement of the
 * module itself: a function written as a binding, which the graph holds as
 * the function it is. Bindings inside a function or block, and initializers
 * that wrap the function in a call, are left alone.
 */
export function isFunctionBinding(node) {
    if (!ts.isVariableDeclaration(node) || !ts.isIdentifier(node.name))
        return false;
    const list = node.parent;
    const statement = list?.parent;
    if (
        list === undefined ||
        !ts.isVariableDeclarationList(list) ||
        statement === undefined ||
        !ts.isVariableStatement(statement) ||
        statement.parent === undefined ||
        !ts.isSourceFile(statement.parent)
    )
        return false;
    const value = unwrapExpression(node.initializer);
    return (
        value !== undefined &&
        (ts.isArrowFunction(value) || ts.isFunctionExpression(value))
    );
}

/** The function binding an arrow or function expression initialises, or null. */
export function functionBindingOf(node) {
    if (
        node === undefined ||
        !(ts.isArrowFunction(node) || ts.isFunctionExpression(node))
    )
        return null;
    const current = holderOf(node);
    return current !== undefined &&
        isFunctionBinding(current) &&
        unwrapExpression(current.initializer) === node
        ? current
        : null;
}

/** The keyword a variable declaration is bound with. */
export function bindingKeyword(declaration) {
    const flags = declaration.parent.flags;
    if ((flags & ts.NodeFlags.Const) !== 0) return "const";
    if ((flags & ts.NodeFlags.Let) !== 0) return "let";
    return "var";
}

/**
 * A declaration's modifiers. `export` and `default` on a variable sit on its
 * statement, not on the declaration, so every binding read as not exported.
 */
export function declarationModifiers(node) {
    const owner =
        ts.isVariableDeclaration(node) &&
        node.parent !== undefined &&
        ts.isVariableDeclarationList(node.parent) &&
        node.parent.parent !== undefined &&
        ts.isVariableStatement(node.parent.parent)
            ? node.parent.parent
            : node;
    return ts.canHaveModifiers(owner) ? (ts.getModifiers(owner) ?? []) : [];
}
