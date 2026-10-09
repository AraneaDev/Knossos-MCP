import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

const created = [];

function scanSource(source) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-names-")),
    );
    created.push(root);
    const absolute = join(root, "src/a.ts");
    fs.mkdirSync(dirname(absolute), { recursive: true });
    fs.writeFileSync(absolute, source);
    const contributions = [];
    new TypeScriptScanner().scan(
        { root, files: ["src/a.ts"] },
        (contribution) => contributions.push(contribution),
    );
    return contributions.flatMap((contribution) => contribution.nodes);
}

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

// The core rejects a node with an empty display name, and with it every fact
// of the language, so one malformed file used to drop the whole project's
// TypeScript facts.
describe("declaration names", () => {
    it("names a declaration whose name the parser could not read by its position", () => {
        const nodes = scanSource("export function (( {\n");

        expect(nodes.filter((node) => node.display_name === "")).toEqual([]);
        const fn = nodes.find((node) => node.kind === "function");
        expect(fn.display_name).toBe("{anonymous}@1:1");
        expect(fn.canonical_name).toBe("src/a.ts#{anonymous}@1:1");
    });

    it("names an enum and a class with a missing name the same way", () => {
        const nodes = scanSource("enum { A }\nexport class (( {\n");

        expect(nodes.filter((node) => node.display_name === "")).toEqual([]);
        expect(
            nodes
                .filter((node) => ["enum", "class"].includes(node.kind))
                .map((node) => node.display_name)
                .sort(),
        ).toEqual(["{anonymous}@1:1", "{anonymous}@2:1"]);
    });

    it('names a member declared as "" by its quoted name', () => {
        const nodes = scanSource(
            'export class A { ""() {} "": number = 1; }\n',
        );

        expect(nodes.filter((node) => node.display_name === "")).toEqual([]);
        expect(
            nodes
                .filter((node) => ["method", "property"].includes(node.kind))
                .map((node) => [
                    node.kind,
                    node.display_name,
                    node.canonical_name,
                ]),
        ).toEqual([
            ["method", '""', 'src/a.ts#A::""'],
            ["property", '""', 'src/a.ts#A::""'],
        ]);
    });
});
