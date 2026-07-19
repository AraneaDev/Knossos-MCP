import { describe, it, expect } from "vitest";
import ts from "typescript";
import {
    callName,
    propertyNameText,
    reference,
    addFrameworkRoute,
} from "../typescript-fact-utils.js";

function parse(code) {
    return ts.createSourceFile("t.ts", code, ts.ScriptTarget.Latest, true);
}

function firstOfKind(sf, predicate) {
    let found = null;
    const visit = (node) => {
        if (found) return;
        if (predicate(node)) {
            found = node;
            return;
        }
        ts.forEachChild(node, visit);
    };
    visit(sf);
    return found;
}

describe("reference", () => {
    it("builds a stable ts-prefixed id from kind and canonical name", () => {
        expect(reference("class", "src/a.ts#Foo")).toBe(
            "ts:class:src/a.ts#Foo",
        );
        expect(reference("module", "src/a.ts")).toBe("ts:module:src/a.ts");
    });
});

describe("callName", () => {
    const exprOf = (code) =>
        firstOfKind(parse(code), ts.isCallExpression).expression;

    it("returns the identifier name for a bare call", () => {
        expect(callName(exprOf("foo();"))).toBe("foo");
    });

    it("joins a property-access chain with dots", () => {
        expect(callName(exprOf("a.b.c();"))).toBe("a.b.c");
    });

    it("drops a non-nameable receiver such as `this`", () => {
        // `this` is neither an identifier nor a property access, so callName on it
        // yields null and only the trailing member name is kept.
        expect(callName(exprOf("this.sum(1, 2);"))).toBe("sum");
    });

    it("returns null for a call target that is neither identifier nor property access", () => {
        // A parenthesized/immediately-invoked expression has no simple name.
        expect(callName(exprOf("(function () {})();"))).toBeNull();
    });
});

describe("propertyNameText", () => {
    const nameOf = (code) =>
        firstOfKind(parse(code), ts.isPropertyAssignment).name;

    it("reads identifier, string, and numeric literal keys", () => {
        expect(propertyNameText(nameOf("const o = { foo: 1 };"))).toBe("foo");
        expect(propertyNameText(nameOf('const o = { "bar": 1 };'))).toBe("bar");
        expect(propertyNameText(nameOf("const o = { 5: 1 };"))).toBe("5");
    });

    it("returns null for a computed property name", () => {
        expect(propertyNameText(nameOf("const o = { [x]: 1 };"))).toBeNull();
    });
});

describe("addFrameworkRoute", () => {
    it("emits a route node and a routes_to edge with framework-convention origin", () => {
        const nodes = [];
        const edges = [];
        const context = {
            addNode: (...args) => nodes.push(args),
            addEdge: (...args) => edges.push(args),
        };
        const node = {};

        addFrameworkRoute(context, {
            id: "n1",
            canonical: "src/cats.ts#CatsController.find",
            displayName: "find",
            node,
            framework: "nestjs",
            httpMethod: "GET",
            path: "/cats",
            target: "n-handler",
        });

        expect(nodes).toEqual([
            [
                "n1",
                "route",
                "src/cats.ts#CatsController.find",
                "find",
                node,
                { framework: "nestjs", http_methods: ["GET"], path: "/cats" },
                "framework_convention",
            ],
        ]);
        expect(edges).toEqual([
            [
                "routes_to",
                "n1",
                "n-handler",
                node,
                { framework: "nestjs" },
                "framework_convention",
            ],
        ]);
    });
});
