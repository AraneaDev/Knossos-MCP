import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

// A method called on a receiver the checker cannot type has no edge to it, so
// it read as certainly dead. The module records the names called that way and
// the core demotes a method by one of them to possibly dead.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function scan(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-untyped-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    const contributions = [];
    new TypeScriptScanner().scan({ root, files: Object.keys(files) }, (c) =>
        contributions.push(c),
    );
    return contributions.flatMap((c) => c.nodes);
}

describe("a member call on an untyped receiver", () => {
    it("is recorded by name on its module", () => {
        const nodes = scan({
            "src/loop.js":
                "export function run(mode, list) {\n    mode.label();\n    mode.label();\n    list.push(1);\n    [].push(2);\n    Math.max(1, 2);\n}\n",
        });
        const module = nodes.find((n) => n.kind === "module");

        // `[].push` and `Math.max` resolve to a declaration and are not recorded.
        expect(module.attributes.unresolved_member_calls).toEqual([
            "label",
            "push",
        ]);
    });

    it("leaves a module whose calls all resolve without the attribute", () => {
        const nodes = scan({
            "src/typed.ts":
                "class A { go(): void {} }\nexport function run(a: A): void { a.go(); }\n",
        });
        const module = nodes.find((n) => n.kind === "module");

        expect(module.attributes).not.toHaveProperty("unresolved_member_calls");
    });
});
