import { describe, it, expect, afterEach, vi } from "vitest";
import fs, { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
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

    it("joins a call through an object literal to the emitted method node", () => {
        // Regression guard for the declaration/reference canonicalization split:
        // a method reached through a named `const` + nested object literal was
        // declared as `src/x.ts::run` but call edges targeted
        // `src/x.ts#api.handlers::run`, so the edge dangled. Both paths must now
        // build the same id.
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/x.ts": [
                "const api = { handlers: { run() { return 1; } } };",
                "export function go(): number {",
                "  return api.handlers.run();",
                "}",
                "",
            ].join("\n"),
        });

        const contributions = [];
        new TypeScriptScanner().scan({ root, files: ["src/x.ts"] }, (c) =>
            contributions.push(c),
        );
        const nodes = contributions.flatMap((c) => c.nodes);
        const edges = contributions.flatMap((c) => c.edges);

        const run = nodes.find(
            (n) => n.kind === "method" && n.display_name === "run",
        );
        expect(run).toBeDefined();
        // The calls edge from go() resolves to the declared run method node.
        expect(
            edges.some((e) => e.kind === "calls" && e.target === run.local_id),
        ).toBe(true);
    });
});

// A `references` edge is what keeps a function that is used as a VALUE —
// dispatch tables, registries, callbacks — from being read as dead code. The
// cases below fix both directions: emitted where a use exists, and withheld
// where the identifier is a declaration or is already covered by a calls edge.
describe("TypeScriptScanner.scan value references", () => {
    it("emits a references edge for a function used as a value in a registry array", () => {
        // Regression guard: functions that are never *called* at their use site —
        // dispatch tables, validator registries, callbacks — used to produce no
        // inbound edge at all, so architecture_health reported every one of them
        // as a probable dead-code candidate. Found by scanning a real project
        // whose `TOOL_ARG_VALIDATORS` array is exactly this shape.
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/registry.ts": [
                "function validateA(): string { return 'a'; }",
                "function validateB(): string { return 'b'; }",
                "function unused(): string { return 'u'; }",
                "export const VALIDATORS = [validateA, validateB];",
                "export const BY_NAME = { b: validateB };",
                "",
            ].join("\n"),
        });

        const contributions = [];
        new TypeScriptScanner().scan(
            { root, files: ["src/registry.ts"] },
            (c) => contributions.push(c),
        );
        const nodes = contributions.flatMap((c) => c.nodes);
        const edges = contributions.flatMap((c) => c.edges);

        const idOf = (name) =>
            nodes.find((n) => n.kind === "function" && n.display_name === name)
                ?.local_id;
        const referencesTo = (name) =>
            edges.filter(
                (e) => e.kind === "references" && e.target === idOf(name),
            );

        expect(idOf("validateA")).toBeDefined();
        // Both the array-literal element and the object-literal value count.
        expect(referencesTo("validateA").length).toBeGreaterThan(0);
        expect(referencesTo("validateB").length).toBeGreaterThan(0);
        // A genuinely unreferenced function still gets no inbound reference, so
        // the dead-code signal is not blanket-suppressed.
        expect(referencesTo("unused")).toEqual([]);
    });

    it("does not emit a value reference for a declaration's own name", () => {
        // The identifier in `function f()` is the definition, not a use of it;
        // counting it would make every declared function self-referencing and
        // permanently non-dead.
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/lonely.ts": [
                "function orphan(): number { return 1; }",
                "export const value = 1;",
                "",
            ].join("\n"),
        });

        const contributions = [];
        new TypeScriptScanner().scan({ root, files: ["src/lonely.ts"] }, (c) =>
            contributions.push(c),
        );
        const nodes = contributions.flatMap((c) => c.nodes);
        const edges = contributions.flatMap((c) => c.edges);

        const orphan = nodes.find(
            (n) => n.kind === "function" && n.display_name === "orphan",
        );
        expect(orphan).toBeDefined();
        expect(
            edges.filter(
                (e) => e.kind === "references" && e.target === orphan.local_id,
            ),
        ).toEqual([]);
    });

    it("does not double-count a called function as a value reference", () => {
        // The callee position already produces a `calls` edge; adding a
        // `references` edge for the same identifier would inflate in-degree and
        // distort hub/hotspot ranking.
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/direct.ts": [
                "function helper(): number { return 1; }",
                "export function caller(): number { return helper(); }",
                "",
            ].join("\n"),
        });

        const contributions = [];
        new TypeScriptScanner().scan({ root, files: ["src/direct.ts"] }, (c) =>
            contributions.push(c),
        );
        const nodes = contributions.flatMap((c) => c.nodes);
        const edges = contributions.flatMap((c) => c.edges);

        const helper = nodes.find(
            (n) => n.kind === "function" && n.display_name === "helper",
        );
        expect(
            edges.some(
                (e) => e.kind === "calls" && e.target === helper.local_id,
            ),
        ).toBe(true);
        expect(
            edges.filter(
                (e) => e.kind === "references" && e.target === helper.local_id,
            ),
        ).toEqual([]);
    });
});

describe("TypeScriptScanner.scan program cache", () => {
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

    it("frees a cache slot before building the next program", () => {
        // Retaining the cap and *then* evicting put cap + 1 programs in memory
        // at the peak, because the new program is constructed while the cache
        // is still full — the overshoot the cap exists to prevent. A real
        // 111-file project with three tsconfigs died on it: the worker's
        // 512 MB heap held two programs and OOMed building the third, so the
        // scan failed outright while the retained-size assertion above passed.
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
        // The cache is read immediately before each program is built, so its
        // size at that moment is the number of programs already resident.
        const residentAtBuild = [];
        const cache = scanner.programCache;
        const realGet = cache.get.bind(cache);
        cache.get = (key) => {
            residentAtBuild.push(cache.size);
            return realGet(key);
        };

        scanner.scan({ root, files: ["a/x.ts", "b/y.ts", "c/z.ts"] }, () => {});

        expect(residentAtBuild.length).toBeGreaterThanOrEqual(3);
        expect(Math.max(...residentAtBuild)).toBeLessThanOrEqual(1);
    });
});

describe("TypeScriptScanner.scan backstop", () => {
    it("emits a contribution for a requested file that no program included", () => {
        // validateRequestedFiles stats the file, then createRestrictedProgram stats
        // it again via exceedsByteCap. A file that grows in between passes the first
        // check and is dropped from the program by the second — so it is requested,
        // accepted, and never emitted. The PHP side requires exactly one
        // contribution per requested file, so that gap must not be silent.
        const root = fixture({ "a.ts": "export const a = 1;\n" });
        const real = fs.statSync.bind(fs);
        let stats = 0;
        const spy = vi
            .spyOn(fs, "statSync")
            .mockImplementation((target, options) => {
                const result = real(target, options);
                if (String(target).endsWith("a.ts") && ++stats > 1) {
                    return { ...result, isFile: () => true, size: 10_000_000 };
                }
                return result;
            });
        try {
            const emitted = [];
            new TypeScriptScanner().scan(
                {
                    root,
                    files: ["a.ts"],
                    limits: { max_files: 10, max_file_bytes: 2_000_000 },
                },
                (contribution) => emitted.push(contribution),
            );
            expect(emitted).toHaveLength(1);
            expect(emitted[0].owner_key).toBe("knossos.typescript:file:a.ts");
            expect(emitted[0].diagnostics[0].code).toBe("TS_UNSCANNABLE_FILE");
        } finally {
            spy.mockRestore();
        }
    });

    it("emits exactly one contribution per accepted file", () => {
        // The invariant the PHP side depends on, asserted directly.
        const files = {
            "a.ts": "export const a = 1;\n",
            "b.ts": "export const b = 2;\n",
            "c.js": "module.exports = 3;\n",
        };
        const root = fixture(files);
        const emitted = [];
        const summary = new TypeScriptScanner().scan(
            {
                root,
                files: Object.keys(files),
                limits: { max_files: 10, max_file_bytes: 2_000_000 },
            },
            (contribution) => emitted.push(contribution),
        );
        expect(emitted).toHaveLength(Object.keys(files).length);
        expect(summary.files_scanned).toBe(Object.keys(files).length);
    });
});
