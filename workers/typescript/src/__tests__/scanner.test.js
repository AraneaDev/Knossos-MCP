import { describe, it, expect, afterEach } from "vitest";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { TypeScriptScanner } from "../scanner.js";

const created = [];

/** Materialize a { relativePath: contents } map into a fresh temp project root. */
function fixture(files) {
    const root = mkdtempSync(join(tmpdir(), "knossos-ts-"));
    created.push(root);
    for (const [rel, contents] of Object.entries(files)) {
        const abs = join(root, rel);
        mkdirSync(dirname(abs), { recursive: true });
        writeFileSync(abs, contents);
    }
    return root;
}

afterEach(() => {
    while (created.length > 0) {
        rmSync(created.pop(), { recursive: true, force: true });
    }
});

describe("TypeScriptScanner.discover", () => {
    it("returns sorted tsconfig and package inputs, skipping node_modules", () => {
        const root = fixture({
            "package.json": "{}",
            "tsconfig.json": "{}",
            "src/index.ts": "export const x = 1;\n",
            "node_modules/dep/package.json": "{}",
            "node_modules/dep/tsconfig.json": "{}",
        });

        const result = new TypeScriptScanner().discover({ root });

        expect(result.config_files).toEqual(["tsconfig.json"]);
        expect(result.package_files).toEqual(["package.json"]);
    });
});

describe("TypeScriptScanner.scan", () => {
    it("emits module/class/method nodes and a calls edge for a method call", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/math.ts": [
                "export class Calc {",
                "  add(a: number, b: number): number {",
                "    return this.sum(a, b);",
                "  }",
                "  sum(a: number, b: number): number {",
                "    return a + b;",
                "  }",
                "}",
                "",
            ].join("\n"),
        });

        const contributions = [];
        const summary = new TypeScriptScanner().scan(
            { root, files: ["src/math.ts"] },
            (c) => contributions.push(c),
        );

        expect(summary.files_scanned).toBe(1);
        const nodes = contributions.flatMap((c) => c.nodes);
        const edges = contributions.flatMap((c) => c.edges);

        const moduleNode = nodes.find((n) => n.kind === "module");
        expect(moduleNode?.canonical_name).toBe("src/math.ts");
        expect(
            nodes.find((n) => n.kind === "class" && n.display_name === "Calc"),
        ).toBeDefined();

        const add = nodes.find(
            (n) => n.kind === "method" && n.display_name === "add",
        );
        const sum = nodes.find(
            (n) => n.kind === "method" && n.display_name === "sum",
        );
        expect(add).toBeDefined();
        expect(sum).toBeDefined();

        // The class contains its members, and add() calls sum().
        expect(edges.some((e) => e.kind === "contains")).toBe(true);
        expect(
            edges.some(
                (e) =>
                    e.kind === "calls" &&
                    e.source === add.local_id &&
                    e.target === sum.local_id,
            ),
        ).toBe(true);
    });

    it("bounds the retained program cache when a project has many tsconfigs", () => {
        // Regression guard for the OOM fix: one full ts.Program per tsconfig,
        // all retained at once, exhausted the worker heap. The cache is LRU-capped.
        const root = fixture({
            "package.json": "{}",
            "a/tsconfig.json": '{"include":["x.ts"]}',
            "a/x.ts": "export const a = 1;\n",
            "b/tsconfig.json": '{"include":["y.ts"]}',
            "b/y.ts": "export const b = 2;\n",
            "c/tsconfig.json": '{"include":["z.ts"]}',
            "c/z.ts": "export const c = 3;\n",
        });

        const scanner = new TypeScriptScanner();
        const emitted = [];
        const summary = scanner.scan(
            { root, files: ["a/x.ts", "b/y.ts", "c/z.ts"] },
            (c) => emitted.push(c),
        );

        expect(summary.files_scanned).toBe(3);
        expect(new Set(emitted.map((c) => c.owner_key)).size).toBe(3);
        // Never retains more than the documented bound, however many configs exist.
        expect(scanner.programCache.size).toBeLessThanOrEqual(2);
    });
});
