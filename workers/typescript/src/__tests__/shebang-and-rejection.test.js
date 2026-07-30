import { describe, it, expect, afterEach } from "vitest";
import {
    mkdtempSync,
    mkdirSync,
    writeFileSync,
    rmSync,
    chmodSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { TypeScriptScanner } from "../scanner.js";

const created = [];

/** Materialize a { relativePath: contents } map into a fresh temp project root. */
function fixture(files) {
    const root = mkdtempSync(join(tmpdir(), "knossos-ts-shebang-"));
    created.push(root);
    for (const [rel, contents] of Object.entries(files)) {
        const abs = join(root, rel);
        mkdirSync(dirname(abs), { recursive: true });
        writeFileSync(abs, contents);
    }
    return root;
}

/** Collect every contribution a scan emits, keyed by the file it belongs to. */
function scanned(root, files) {
    const byOwner = new Map();
    const result = new TypeScriptScanner().scan({ root, files }, (c) =>
        byOwner.set(c.owner_key.replace("knossos.typescript:file:", ""), c),
    );
    return { result, byOwner };
}

afterEach(() => {
    while (created.length > 0) {
        rmSync(created.pop(), { recursive: true, force: true });
    }
});

// Discovery classifies an extensionless script by its shebang and hands it to
// this worker, which used to refuse anything without a known extension — so the
// files discovery had just resolved failed the whole request.
describe("extensionless shebang scripts", () => {
    it("scans a shebang script and reports it under its real path", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "bin/cli":
                "#!/usr/bin/env node\nexport function run() {\n  return 1;\n}\n",
        });

        const { result, byOwner } = scanned(root, ["bin/cli"]);

        expect(result.files_scanned).toBe(1);
        // The synthetic name the program needs must never reach the graph.
        expect([...byOwner.keys()]).toEqual(["bin/cli"]);
        const names = byOwner.get("bin/cli").nodes.map((n) => n.canonical_name);
        expect(names.some((n) => n.includes("run"))).toBe(true);
        expect(names.every((n) => !n.includes("knossos-shebang"))).toBe(true);
    });

    it("resolves imports made by a shebang script", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "src/helper.js": "export function helper() {\n  return 2;\n}\n",
            "bin/cli":
                "#!/usr/bin/env node\nimport { helper } from '../src/helper.js';\nexport function run() {\n  return helper();\n}\n",
        });

        const { byOwner } = scanned(root, ["bin/cli"]);

        const edges = byOwner.get("bin/cli").edges;
        expect(
            edges.some((e) => e.kind === "imports" || e.kind === "calls"),
        ).toBe(true);
        expect(
            edges.every((e) => !JSON.stringify(e).includes("knossos-shebang")),
        ).toBe(true);
    });

    it.each([
        ["#!/usr/bin/node\nexport const a = 1;\n", true],
        ["#!/usr/bin/env bun\nexport const a = 1;\n", true],
        ["#!/usr/bin/env deno\nexport const a = 1;\n", true],
        // A path that merely contains an interpreter name is not a script of it.
        ["#!/opt/nodegroup/bin/launcher\nnot javascript\n", false],
        // No shebang at all.
        ["MIT License\n", false],
    ])("classifies %j as JavaScript: %s", (contents, accepted) => {
        const root = fixture({ "package.json": "{}", "bin/probe": contents });

        const { byOwner } = scanned(root, ["bin/probe"]);

        const codes = byOwner.get("bin/probe").diagnostics.map((d) => d.code);
        expect(codes.includes("TS_UNSCANNABLE_FILE")).toBe(!accepted);
    });

    it("reports an unreadable shebang candidate rather than throwing", () => {
        const root = fixture({
            "package.json": "{}",
            "bin/locked": "#!/usr/bin/env node\nexport const a = 1;\n",
        });
        chmodSync(join(root, "bin/locked"), 0o000);

        const { byOwner } = scanned(root, ["bin/locked"]);

        // Root bypasses the permission bit, so accept either outcome: the point
        // is that an unreadable probe never escapes as a request-level error.
        expect(byOwner.has("bin/locked")).toBe(true);
        chmodSync(join(root, "bin/locked"), 0o644);
    });
});

// One file the worker cannot read must cost that file, not the whole batch.
describe("per-file rejection", () => {
    it("reports a missing file and still scans the rest", () => {
        const root = fixture({
            "package.json": "{}",
            "tsconfig.json": '{"include":["src"]}',
            "src/present.ts": "export const present = 1;\n",
        });

        const { result, byOwner } = scanned(root, [
            "src/present.ts",
            "src/absent.ts",
        ]);

        expect(result.files_scanned).toBe(2);
        expect(byOwner.get("src/absent.ts").diagnostics[0].code).toBe(
            "TS_UNSCANNABLE_FILE",
        );
        expect(byOwner.get("src/absent.ts").nodes).toEqual([]);
        expect(byOwner.get("src/present.ts").nodes.length).toBeGreaterThan(0);
    });

    it("reports a file of an unsupported kind rather than failing the request", () => {
        const root = fixture({
            "package.json": "{}",
            "notes.txt": "not source\n",
            "src/present.js": "export const present = 1;\n",
        });

        const { byOwner } = scanned(root, ["notes.txt", "src/present.js"]);

        expect(byOwner.get("notes.txt").diagnostics[0].code).toBe(
            "TS_UNSCANNABLE_FILE",
        );
        expect(byOwner.get("src/present.js").nodes.length).toBeGreaterThan(0);
    });

    it("reports a file over the byte cap and keeps the one under it", () => {
        const root = fixture({
            "package.json": "{}",
            "src/small.js": "export const s = 1;\n",
            "src/big.js": `export const b = "${"x".repeat(500)}";\n`,
        });

        const { byOwner } = scanned(root, ["src/small.js", "src/big.js"]);
        const capped = new TypeScriptScanner();
        const seen = new Map();
        capped.scan(
            {
                root,
                files: ["src/small.js", "src/big.js"],
                limits: { max_file_bytes: 100 },
            },
            (c) =>
                seen.set(
                    c.owner_key.replace("knossos.typescript:file:", ""),
                    c,
                ),
        );

        expect(byOwner.get("src/big.js").nodes.length).toBeGreaterThan(0);
        expect(seen.get("src/big.js").diagnostics[0].code).toBe(
            "TS_UNSCANNABLE_FILE",
        );
        expect(seen.get("src/small.js").nodes.length).toBeGreaterThan(0);
    });

    it.each([
        ["../escape.ts"],
        ["/etc/passwd"],
        ["src//double.ts"],
        ["src/./here.ts"],
        [""],
    ])("still fails the request for the malformed path %j", (bad) => {
        const root = fixture({ "package.json": "{}" });

        // A malformed path names no file, so there is nothing to attribute a
        // diagnostic to and the owner key would be invalid anyway.
        expect(() =>
            new TypeScriptScanner().scan({ root, files: [bad] }, () => {}),
        ).toThrow();
    });
});
