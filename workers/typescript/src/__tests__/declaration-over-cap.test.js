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
            "node_modules/icons/dist/icons.d.ts": `export declare const Icon: number;\n${"// pad\n".repeat(200)}`,
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
});
