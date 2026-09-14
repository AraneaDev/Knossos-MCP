import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { createHash } from "node:crypto";
import { tmpdir } from "node:os";
import { dirname, join, relative } from "node:path";
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

    it("never reports the default library, even under a root that contains it", () => {
        const library = fs.realpathSync(dirname(ts.getDefaultLibFilePath({})));
        const packageRoot = dirname(library);
        const directory = fs.mkdtempSync(
            join(packageRoot, "knossos-vitest-library-"),
        );
        created.push(directory);
        fs.writeFileSync(
            join(directory, "a.ts"),
            "export const a: Array<string> = [].map(String);\n",
        );
        const file = relative(packageRoot, join(directory, "a.ts"));

        const hashes = scan(new TypeScriptScanner(), packageRoot, [
            file,
        ]).input_hashes;

        // Resolution still probes the package scope above the file, which
        // is the project's, but nothing below the library directory is keyed.
        expect(hashes[file]).toBe(
            sha256("export const a: Array<string> = [].map(String);\n"),
        );
        expect(
            Object.keys(hashes).filter((key) =>
                join(packageRoot, key).startsWith(`${library}/`),
            ),
        ).toEqual([]);
    });
});
