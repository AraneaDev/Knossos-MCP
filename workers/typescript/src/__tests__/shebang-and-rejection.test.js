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

// A shebang says the module is executed rather than imported, which is what
// dead-code analysis keys on: nothing in a codebase references a script, so its
// module having no inbound edge says nothing about whether it is wanted.
// Nothing here asserted the attribute, so mutating the marker to "" — which
// makes startsWith() true for every file and marks the whole tree executable —
// survived a mutation run.
describe("the executable module attribute", () => {
    it("marks a shebang script executable and leaves an ordinary module alone", () => {
        const root = fixture({
            "package.json": '{"name":"executable-fixture"}',
            "bin/cli":
                "#!/usr/bin/env node\nexport function run() {\n    return 1;\n}\n",
            "src/loop.mjs":
                "#!/usr/bin/env node\nexport function loop() {\n    return 2;\n}\n",
            "src/helper.js": "export function helper() {\n    return 3;\n}\n",
        });

        const { byOwner } = scanned(root, [
            "bin/cli",
            "src/loop.mjs",
            "src/helper.js",
        ]);
        const executableOf = (owner) =>
            byOwner.get(owner).nodes.find((node) => node.kind === "module")
                .attributes.executable;

        // An extensionless script and a suffixed one are both entered by a shell.
        expect(executableOf("bin/cli")).toBe(true);
        expect(executableOf("src/loop.mjs")).toBe(true);
        // A library module nothing imports is exactly what dead-code analysis
        // exists to surface, so it must stay reportable.
        expect(executableOf("src/helper.js")).toBe(false);
    });
});

// An ES module has no `require.main`, so a CLI written as one compares its own
// URL or path with the script node was started on. Without a shebang that
// comparison is the only sign the file is entered by a shell, and a module that
// carried neither was reported as reached only by its tests.
describe("the ES-module main guard", () => {
    const guarded = {
        "src/url.mjs":
            'import { pathToFileURL } from "node:url";\nexport function run() {}\nif (import.meta.url === pathToFileURL(process.argv[1]).href) run();\n',
        "src/url-reversed.mjs":
            'import { pathToFileURL } from "node:url";\nexport function run() {}\nif (pathToFileURL(process.argv[1]).href == import.meta.url) {\n    run();\n}\n',
        "src/path.mjs":
            'import { fileURLToPath } from "node:url";\nexport function run() {}\nif (fileURLToPath(import.meta.url) === process.argv[1]) run();\n',
        "src/path-reversed.mjs":
            'import { fileURLToPath } from "node:url";\nexport function run() {}\nif (process.argv[1] === fileURLToPath(import.meta.url)) run();\n',
        "src/realpath-url.mjs":
            'import { realpathSync } from "node:fs";\nimport { pathToFileURL } from "node:url";\nexport function run() {}\nif (import.meta.url === pathToFileURL(realpathSync(process.argv[1])).href) run();\n',
        "src/realpath-path.mjs":
            'import fs from "node:fs";\nimport url from "node:url";\nexport function run() {}\nif (url.fileURLToPath(import.meta.url) === fs.realpathSync(process.argv[1])) run();\n',
        "src/meta-main.mjs":
            "export function run() {}\nif (import.meta.main) run();\n",
        // The conversions are bound by import, under any local name.
        "src/aliased.mjs":
            'import { fileURLToPath as toPath } from "url";\nexport function run() {}\nif (toPath(import.meta.url) === process.argv[1]) run();\n',
        "src/namespace.mjs":
            'import * as nodeUrl from "node:url";\nimport * as nodeFs from "fs";\nexport function run() {}\nif (nodeUrl.pathToFileURL(nodeFs.realpathSync(process.argv[1])).href === import.meta.url) run();\n',
        // `process` imported from its own module is the global under a binding.
        "src/process-import.mjs":
            'import process from "node:process";\nimport { fileURLToPath } from "node:url";\nexport function run() {}\nif (fileURLToPath(import.meta.url) === process.argv[1]) run();\n',
        "src/main.cjs":
            "function run() {}\nif (require.main === module) run();\n",
    };
    const unguarded = {
        // A URL is never equal to a path, so this guard never fires.
        "src/mixed.mjs":
            "export function run() {}\nif (import.meta.url === process.argv[1]) run();\n",
        // argv[2] is the first argument, not the script.
        "src/argument.mjs":
            'import { fileURLToPath } from "node:url";\nexport function run() {}\nif (fileURLToPath(import.meta.url) === process.argv[2]) run();\n',
        "src/negated.mjs":
            'import { pathToFileURL } from "node:url";\nexport function run() {}\nif (import.meta.url !== pathToFileURL(process.argv[1]).href) run();\n',
        "src/nested.mjs":
            'import { pathToFileURL } from "node:url";\nexport function run() {\n    if (import.meta.url === pathToFileURL(process.argv[1]).href) return 1;\n    return 0;\n}\n',
        "src/self.mjs":
            "export function run() {}\nif (import.meta.url === import.meta.url) run();\n",
        // A local `process` is not the one node fills in.
        "src/shadowed-process.mjs":
            'import { fileURLToPath } from "node:url";\nconst process = { argv: ["node", fileURLToPath(import.meta.url)] };\nexport function run() {}\nif (fileURLToPath(import.meta.url) === process.argv[1]) run();\n',
        // A method of that name on anything but the url module.
        "src/foreign-method.mjs":
            'import * as foo from "./helpers.mjs";\nexport function run() {}\nif (foo.fileURLToPath(import.meta.url) === process.argv[1]) run();\n',
        "src/helpers.mjs":
            "export function fileURLToPath(url) {\n    return url;\n}\n",
        // The project's own function of that name.
        "src/own-realpath.mjs":
            'import { fileURLToPath } from "node:url";\nfunction realpathSync(path) {\n    return path;\n}\nexport function run() {}\nif (fileURLToPath(import.meta.url) === realpathSync(process.argv[1])) run();\n',
        // Not imported at all: node has no global of that name.
        "src/unbound.mjs":
            "export function run() {}\nif (fileURLToPath(import.meta.url) === process.argv[1]) run();\n",
        // A local `module` or `require` is not CommonJS's.
        "src/shadowed-module.cjs":
            "const module = require.main;\nfunction run() {}\nif (require.main === module) run();\n",
        "src/shadowed-require.cjs":
            "function require() {\n    return null;\n}\nfunction run() {}\nif (module === require.main) run();\n",
    };

    it("marks each form executable and leaves look-alikes alone", () => {
        const root = fixture({
            "package.json": '{"name":"esm-main-guard","type":"module"}',
            ...guarded,
            ...unguarded,
        });
        const { byOwner } = scanned(root, [
            ...Object.keys(guarded),
            ...Object.keys(unguarded),
        ]);
        const executableOf = (owner) =>
            byOwner.get(owner).nodes.find((node) => node.kind === "module")
                .attributes.executable;

        const expected = Object.fromEntries([
            ...Object.keys(guarded).map((owner) => [owner, true]),
            ...Object.keys(unguarded).map((owner) => [owner, false]),
        ]);
        expect(
            Object.fromEntries(
                Object.keys(expected).map((owner) => [
                    owner,
                    executableOf(owner),
                ]),
            ),
        ).toEqual(expected);
    });
});
