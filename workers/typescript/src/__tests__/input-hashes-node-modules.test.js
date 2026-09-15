import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { createHash } from "node:crypto";
import { tmpdir } from "node:os";
import { spawnSync } from "node:child_process";
import { dirname, join } from "node:path";
import { fileURLToPath, URL } from "node:url";
import ts from "typescript";

import { TypeScriptScanner } from "../scanner.js";

// Declarations under node_modules decide the facts of every file importing a
// package, so their reads are reported like any other. Discovery never hashes
// them, so the core re-reads each key when the scan commits, and fails the scan
// when one path was reported with two values. On a stable tree every key must
// therefore carry the same value in every request and every program: the value
// the path itself has, not the value of whichever read passed through it.

const created = [];

function fixture(files = {}) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-node-modules-")),
    );
    created.push(root);
    write(root, files);
    return root;
}

function write(root, files) {
    for (const [path, contents] of Object.entries(files)) {
        const absolute = join(root, path);
        fs.mkdirSync(dirname(absolute), { recursive: true });
        fs.writeFileSync(absolute, contents);
    }
}

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

const sha256 = (bytes) => createHash("sha256").update(bytes).digest("hex");

function scan(scanner, root, files, limits) {
    return scanner.scan({ root, files, limits }, () => {});
}

/**
 * The value a key names on the tree as it stands: the hash of the in-root
 * regular file the path resolves to, or null. What the core's re-read compares
 * a reported hash with, and the only value that cannot depend on which read
 * reported it.
 */
function pathState(root, key, maxFileBytes = 2_000_000) {
    let real;
    try {
        real = fs.realpathSync.native(join(root, key));
    } catch {
        return null;
    }
    if (real !== root && !real.startsWith(`${root}/`)) return null;
    const stat = fs.statSync(real);
    if (!stat.isFile() || stat.size > maxFileBytes) return null;
    return sha256(fs.readFileSync(real));
}

// A UTF-8 byte-order mark, which TypeScript drops before parsing: the hash must
// be over the raw bytes, not over the text the compiler saw.
const DECLARATION = "\uFEFFexport declare class Dep {}\n";
const EXTRA = "export declare class Extra {}\n";
const MANIFEST = '{"name":"dep","version":"1.0.0","types":"index.d.ts"}\n';
const PNPM = "node_modules/.pnpm/dep@1.0.0/node_modules/dep";

function pnpmTree() {
    const root = fixture({
        "src/a.ts":
            'import { Dep } from "dep";\nexport class A extends Dep {}\n',
        "src/b.ts":
            '/// <reference path="../node_modules/dep/index.d.ts" />\nexport const b = 1;\n',
        "src/c.ts":
            '/// <reference path="../node_modules/dep/extra.d.ts" />\nexport const c = 1;\n',
        "src/d.ts": 'import type { Dep } from "dep";\nexport type D = Dep;\n',
        [`${PNPM}/package.json`]: MANIFEST,
        [`${PNPM}/index.d.ts`]: DECLARATION,
        [`${PNPM}/extra.d.ts`]: EXTRA,
    });
    fs.symlinkSync(
        ".pnpm/dep@1.0.0/node_modules/dep",
        join(root, "node_modules/dep"),
    );
    return root;
}

describe("input_hashes: a package under node_modules", () => {
    it("reports the declaration and manifest a plain package resolves to by their raw bytes", () => {
        const root = fixture({
            "src/a.ts":
                'import { Dep } from "dep";\nexport class A extends Dep {}\n',
            "node_modules/dep/package.json": MANIFEST,
            "node_modules/dep/index.d.ts": DECLARATION,
        });

        const hashes = scan(new TypeScriptScanner(), root, [
            "src/a.ts",
        ]).input_hashes;

        expect(hashes["node_modules/dep/index.d.ts"]).toBe(
            sha256(Buffer.from(DECLARATION)),
        );
        expect(hashes["node_modules/dep/index.d.ts"]).not.toBe(
            sha256(DECLARATION.slice(1)),
        );
        expect(hashes["node_modules/dep/package.json"]).toBe(sha256(MANIFEST));
    });

    it("keys a pnpm-style link's reads by the real .pnpm path and the link path, and the linked directory by null", () => {
        const root = pnpmTree();

        const hashes = scan(new TypeScriptScanner(), root, [
            "src/a.ts",
        ]).input_hashes;

        expect(hashes[`${PNPM}/index.d.ts`]).toBe(sha256(DECLARATION));
        expect(hashes[`${PNPM}/package.json`]).toBe(sha256(MANIFEST));
        expect(hashes["node_modules/dep/index.d.ts"]).toBe(sha256(DECLARATION));
        expect(hashes["node_modules/dep/package.json"]).toBe(sha256(MANIFEST));
        expect(hashes["node_modules/dep"]).toBeNull();
    });

    it("reports every key with the value its path has, identically across requests and scanners", () => {
        const root = pnpmTree();
        write(root, {
            "src/e.ts": 'import { P } from "plain";\nexport const e = P;\n',
            "node_modules/plain/package.json":
                '{"name":"plain","types":"index.d.ts"}\n',
            "node_modules/plain/index.d.ts": "export declare const P: 1;\n",
        });
        const files = [
            "src/a.ts",
            "src/b.ts",
            "src/c.ts",
            "src/d.ts",
            "src/e.ts",
        ];
        const shared = new TypeScriptScanner();
        const requests = [
            // One request per file on one scanner whose programs are reused,
            // then all files at once on a fresh one.
            ...files.map((file) => scan(shared, root, [file])),
            scan(new TypeScriptScanner(), root, files),
        ].map((result) => result.input_hashes);

        const seen = {};
        for (const hashes of requests) {
            for (const [key, value] of Object.entries(hashes)) {
                expect([key, value]).toEqual([key, pathState(root, key)]);
                seen[key] = value;
            }
        }
        // The layouts that disagreed before: a link path probed in one request
        // and read in another, and a linked directory read below for two
        // different files.
        expect(seen["node_modules/dep/index.d.ts"]).toBe(sha256(DECLARATION));
        expect(seen["node_modules/dep/extra.d.ts"]).toBe(sha256(EXTRA));
        expect(seen["node_modules/dep"]).toBeNull();
        expect(seen["node_modules/plain/index.d.ts"]).toBe(
            sha256("export declare const P: 1;\n"),
        );
    }, 30_000);

    it("reports an over-cap declaration behind a link by null from its probe and from its refusal alike", () => {
        const root = pnpmTree();
        write(root, {
            [`${PNPM}/index.d.ts`]: `${DECLARATION}${"// pad\n".repeat(40)}`,
        });
        const limits = { max_file_bytes: 200 };
        const shared = new TypeScriptScanner();

        for (const file of ["src/a.ts", "src/b.ts", "src/d.ts"]) {
            const hashes = scan(shared, root, [file], limits).input_hashes;
            for (const [key, value] of Object.entries(hashes)) {
                expect([key, value]).toEqual([key, pathState(root, key, 200)]);
            }
        }
    });
});

describe("input_hashes: the default library, file links and FIFOs", () => {
    it("never reports the default library, even under a root that contains it", () => {
        // The worker's own package is such a root, and scanning one of its
        // import-free sources builds a program over the default library
        // without writing anything into an installed package.
        const library = fs.realpathSync(dirname(ts.getDefaultLibFilePath({})));
        const workerRoot = fs.realpathSync(
            fileURLToPath(new URL("../..", import.meta.url)),
        );
        const file = "src/scan-thread-limits.js";
        expect(library.startsWith(`${workerRoot}/`)).toBe(true);

        const hashes = scan(new TypeScriptScanner(), workerRoot, [
            file,
        ]).input_hashes;

        expect(hashes[file]).toBe(
            sha256(fs.readFileSync(join(workerRoot, file))),
        );
        expect(
            Object.keys(hashes).filter((key) =>
                join(workerRoot, key).startsWith(`${library}/`),
            ),
        ).toEqual([]);
    });

    it("keys a file link the same whether it is read as itself or walked below as a directory", () => {
        // `/// <reference path="./lnk.ts/x.d.ts" />` walks the link to a file
        // and fails below it (ENOTDIR). The link still names that file, so it
        // must carry the file's hash there too, as the request reading it
        // directly reports.
        const C = "export const c = 1;\n";
        const root = fixture({
            "src/a.ts":
                '/// <reference path="./lnk.ts" />\nexport const a = 1;\n',
            "src/b.ts":
                '/// <reference path="./lnk.ts/x.d.ts" />\nexport const b = 1;\n',
            "src/c.ts": C,
        });
        fs.symlinkSync("c.ts", join(root, "src/lnk.ts"));
        const shared = new TypeScriptScanner();

        const requests = [
            scan(shared, root, ["src/a.ts"]),
            scan(shared, root, ["src/b.ts"]),
            scan(new TypeScriptScanner(), root, ["src/b.ts", "src/a.ts"]),
        ].map((result) => result.input_hashes);

        for (const hashes of requests) {
            expect(hashes["src/lnk.ts"]).toBe(sha256(C));
            for (const [key, value] of Object.entries(hashes)) {
                expect([key, value]).toEqual([key, pathState(root, key)]);
            }
        }
        expect(Object.keys(requests[1])).toContain("src/lnk.ts/x.d.ts");
    });

    it("reports a FIFO reached by a probe through a link, or by a read, as null without blocking", () => {
        // Opening a FIFO for reading blocks until a writer appears, which on
        // a synchronous read would hang the worker for good. Run in a child
        // process so a hang fails the test instead of the test runner.
        const root = fixture({
            "src/a.ts": 'import { p } from "./lnk";\nexport const a = p;\n',
            "src/b.ts":
                '/// <reference path="./pipe.d.ts" />\nexport const b = 1;\n',
        });
        const made = spawnSync("mkfifo", [
            join(root, "src/pipe.ts"),
            join(root, "src/pipe.d.ts"),
        ]);
        expect(made.status).toBe(0);
        fs.symlinkSync("pipe.ts", join(root, "src/lnk.ts"));
        const scanner = new URL("../scanner.js", import.meta.url).href;
        const script = `
            const { TypeScriptScanner } = await import(${JSON.stringify(scanner)});
            const hashes = new TypeScriptScanner().scan(
                { root: ${JSON.stringify(root)}, files: ["src/a.ts", "src/b.ts"] },
                () => {},
            ).input_hashes;
            process.stdout.write(JSON.stringify(hashes));
        `;

        const child = spawnSync(
            process.execPath,
            ["--input-type=module", "-e", script],
            { timeout: 20_000, encoding: "utf8" },
        );

        expect(child.signal).toBeNull();
        expect(child.status).toBe(0);
        const hashes = JSON.parse(child.stdout);
        expect(hashes["src/lnk.ts"]).toBeNull();
        expect(hashes["src/pipe.d.ts"]).toBeNull();
    }, 30_000);
});

// The project's excluded-directory list names where a project builds to and
// vendors under, so it describes the project's own layout. A dependency's
// layout is its own business, and `dist` is where npm packages most commonly
// ship their declarations. Applying the project's rules inside node_modules
// refused those declarations: the compiler then resolved every importer as if
// the package had no types, and the refusal — recorded as a failed read of a
// file that reads perfectly well — failed the whole scan when the core re-read
// the key at commit.
describe("input_hashes: a dependency's own layout governs below node_modules", () => {
    const DIST = "node_modules/dep/dist/cjs";
    const DIST_MANIFEST = `{"name":"dep","version":"1.0.0","types":"${"dist/cjs/types.d.cts"}"}\n`;

    function distTree(extra = {}) {
        return fixture({
            "src/a.ts":
                'import { Dep } from "dep";\nexport class A extends Dep {}\n',
            "node_modules/dep/package.json": DIST_MANIFEST,
            [`${DIST}/types.d.cts`]: DECLARATION,
            ...extra,
        });
    }

    it("reads a declaration under a dependency's dist/ by its raw bytes rather than refusing it", () => {
        const root = distTree();

        const hashes = scan(new TypeScriptScanner(), root, [
            "src/a.ts",
        ]).input_hashes;

        // The regression: this key was reported null, which the core's
        // commit-time re-read rejects for a readable in-root regular file.
        expect(hashes[`${DIST}/types.d.cts`]).toBe(
            sha256(Buffer.from(DECLARATION)),
        );
        expect(hashes[`${DIST}/types.d.cts`]).toBe(
            pathState(root, `${DIST}/types.d.cts`),
        );
    });

    it("resolves facts through that declaration instead of treating the package as untyped", () => {
        const root = distTree();

        const contributions = [];
        new TypeScriptScanner().scan({ root, files: ["src/a.ts"] }, (c) =>
            contributions.push(c),
        );

        // The quiet half of the same defect. The edge exists either way — it
        // is syntax — but its target is only as good as the resolution behind
        // it: with the declaration refused the base class resolves to
        // `ts:external_class:unknown`, so the graph records that A extends
        // something nobody can name.
        const edges = contributions.flatMap((c) => c.edges);
        const base = edges.find((e) => e.kind === "extends");
        expect(base?.target).toBe("ts:external_class:Dep");
    });

    it("still refuses the project's own dist/, which the exclusions do describe", () => {
        const root = distTree({
            "dist/generated.ts": "export const generated = 1;\n",
            "src/g.ts":
                '/// <reference path="../dist/generated.ts" />\nexport const g = 1;\n',
        });

        const hashes = scan(new TypeScriptScanner(), root, [
            "src/g.ts",
        ]).input_hashes;

        expect(hashes["dist/generated.ts"]).toBeNull();
    });

    it("applies the exclusions above the dependency root, so a build dir holding a node_modules stays out", () => {
        const root = distTree({
            "build/vendored/node_modules/dep/dist/index.d.ts": DECLARATION,
            "src/h.ts":
                '/// <reference path="../build/vendored/node_modules/dep/dist/index.d.ts" />\nexport const h = 1;\n',
        });

        const hashes = scan(new TypeScriptScanner(), root, [
            "src/h.ts",
        ]).input_hashes;

        expect(
            hashes["build/vendored/node_modules/dep/dist/index.d.ts"],
        ).toBeNull();
    });
});
