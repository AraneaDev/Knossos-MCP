import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

// A class method fulfilling a member of a type its class extends or
// implements is marked `overrides`. A dependency's base class or a built-in
// interface is not in the graph, so the dispatch through it leaves no edge.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function scanned(files, requested) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-supertype-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    const contributions = [];
    new TypeScriptScanner().scan(
        { root, files: requested, config_files: ["tsconfig.json"] },
        (c) => contributions.push(c),
    );
    return Object.fromEntries(
        contributions
            .flatMap((c) => c.nodes)
            .filter((n) => n.kind === "method")
            .map((n) => [n.canonical_name, n.attributes.overrides === true]),
    );
}

describe("a method fulfilling a supertype's member", () => {
    it("is marked from the checker's heritage or contextual types or the override keyword", () => {
        const overrides = scanned(
            {
                "tsconfig.json":
                    '{"compilerOptions":{"strict":true,"lib":["ES2022"]},"include":["src/**/*.ts"]}',
                "src/host.d.ts":
                    "declare module 'host' {\n  export abstract class Visitor { enter(): void; leave(): void }\n}\n",
                "src/walker.ts": [
                    "import { Visitor } from 'host'",
                    "export class Walker extends Visitor implements Iterator<number> {",
                    "  enter(): void {}",
                    "  override leave(): void {}",
                    "  next(): IteratorResult<number> { return { done: true, value: undefined } }",
                    "  helper(): number { return 1 }",
                    "}",
                    "export class Factory extends Visitor {",
                    "  static enter(): number { return 1 }",
                    "}",
                    "export class Alone {",
                    "  enter(): void {}",
                    "}",
                    "interface Hooks { resolve(specifier: string): string }",
                    "declare function register(hooks: Hooks): void",
                    "register({ resolve(specifier) { return specifier } })",
                    "const loose = { resolve(specifier: string) { return specifier } }",
                    "export { loose }",
                    "",
                ].join("\n"),
            },
            ["src/walker.ts"],
        );

        expect(overrides).toEqual({
            "src/walker.ts#Walker::enter": true,
            "src/walker.ts#Walker::leave": true,
            "src/walker.ts#Walker::next": true,
            "src/walker.ts#Walker::helper": false,
            "src/walker.ts#Factory::enter": false,
            "src/walker.ts#Alone::enter": false,
            // A literal handed to a parameter typed by an interface fulfils
            // its members; a literal nothing types fulfils nothing.
            "src/walker.ts#{object}@16:10::resolve": true,
            "src/walker.ts#loose::resolve": false,
            // An interface's own member is the contract, not a fulfilment.
            "src/walker.ts#Hooks::resolve": false,
        });
    });

    it("is not marked when the only member of that name is the method itself", () => {
        const overrides = scanned(
            {
                "tsconfig.json":
                    '{"compilerOptions":{"strict":true,"lib":["ES2022"]},"include":["src/**/*.ts"]}',
                "src/define.ts": [
                    "declare function define<T>(options: T): T",
                    "export const plugin = define({ run(): number { return 1 } })",
                    "",
                ].join("\n"),
            },
            ["src/define.ts"],
        );

        // The contextual type is inferred from the literal itself: it names no contract.
        expect(Object.values(overrides)).toEqual([false]);
    });
});
