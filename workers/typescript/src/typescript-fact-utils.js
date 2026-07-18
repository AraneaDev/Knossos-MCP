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
