import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

// A package whose declaration file is over the per-file byte cap is refused to
// bound memory, which is deliberate. The compiler then reports the import as
// `TS2307 Cannot find module`, which reads as a missing dependency when the
// package is installed and resolves: the scan simply chose not to read it.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function fixture(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-over-cap-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    return root;
}

describe("an import resolving to a declaration over the byte cap", () => {
    it("is reported as not read, not as a missing module", () => {
        const root = fixture({
            "src/a.ts":
                'import { Icon } from "icons";\nimport { Gone } from "missing";\nexport const a = [Icon, Gone];\n',
            "node_modules/icons/package.json":
                '{"name":"icons","typings":"dist/icons.d.ts"}',
            "node_modules/icons/dist/icons.d.ts": `export declare const Icon: number;\n${"// pad\n".repeat(3000)}`,
        });
        const contributions = [];
        new TypeScriptScanner().scan(
            { root, files: ["src/a.ts"], limits: { max_file_bytes: 1000 } },
            (c) => contributions.push(c),
        );
        const diagnostics = contributions.flatMap((c) => c.diagnostics);

        const overCap = diagnostics.filter(
            (d) => d.code === "TS_DECLARATION_OVER_CAP",
        );
        expect(overCap).toHaveLength(1);
        expect(overCap[0].severity).toBe("warning");
        expect(overCap[0].message).toContain("'icons'");
        expect(overCap[0].message).toContain(
            "node_modules/icons/dist/icons.d.ts",
        );
        // A module that resolves nowhere is still the compiler's own error.
        const missing = diagnostics.filter((d) => d.code === "TS2307");
        expect(missing).toHaveLength(1);
        expect(missing[0].message).toContain("'missing'");
    });

    it("reads a dependency's declaration over the source cap but within its own", () => {
        // A framework's whole type surface in one file (phaser.d.ts is 6 MB)
        // is ordinary for a dependency, and refusing it cost every subclass
        // its inherited members. Declarations below node_modules get sixteen
        // times the cap a project's own sources get.
        const declaration = `export declare class Sprite { active: boolean; }\n${"// pad\n".repeat(400)}`;
        const root = fixture({
            "src/actor.ts":
                'import { Sprite } from "engine";\nexport class Actor extends Sprite {\n    alive(): boolean { return this.active; }\n}\n',
            "node_modules/engine/package.json":
                '{"name":"engine","types":"index.d.ts"}',
            "node_modules/engine/index.d.ts": declaration,
        });
        const contributions = [];
        const result = new TypeScriptScanner().scan(
            { root, files: ["src/actor.ts"], limits: { max_file_bytes: 1000 } },
            (c) => contributions.push(c),
        );
        const codes = contributions
            .flatMap((c) => c.diagnostics)
            .map((d) => d.code);

        expect(codes).not.toContain("TS_DECLARATION_OVER_CAP");
        expect(codes).not.toContain("TS2339");
        expect(result.input_hashes["node_modules/engine/index.d.ts"]).toMatch(
            /^[0-9a-f]{64}$/,
        );
    });
});
