import { afterEach, describe, expect, it, vi } from "vitest";
import fs from "node:fs";
import { createHash } from "node:crypto";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

const created = [];

function fixture(files) {
    const root = fs.realpathSync(fs.mkdtempSync(join(tmpdir(), "knossos-ts-")));
    created.push(root);
    for (const [relative, contents] of Object.entries(files)) {
        const absolute = join(root, relative);
        fs.mkdirSync(dirname(absolute), { recursive: true });
        fs.writeFileSync(absolute, contents);
    }
    return root;
}

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

const sha256 = (text) => createHash("sha256").update(text).digest("hex");

function scan(root, files, { limits, observeHostPath } = {}) {
    const contributions = [];
    const result = new TypeScriptScanner({ observeHostPath }).scan(
        { root, files, limits },
        (contribution) => contributions.push(contribution),
    );
    return {
        result,
        byOwner: Object.fromEntries(
            contributions.map((contribution) => [
                contribution.owner_key.replace("knossos.typescript:file:", ""),
                contribution,
            ]),
        ),
    };
}

describe("input_hashes: a requested file whose read fails", () => {
    it("reports null for a requested file the filesystem refuses, and nothing for a policy refusal", () => {
        // Its contribution carries no facts, so a discovered file must not pass
        // verification as if it had been read.
        const root = fixture({
            "src/big.ts": `export const big = 1;\n${"// pad\n".repeat(20)}`,
            "src/notes.txt": "text\n",
            "bin/plain": "echo not a script\n",
        });
        const outside = fixture({ "out.ts": "export const out = 1;\n" });
        fs.mkdirSync(join(root, "src/dir.ts"));
        fs.symlinkSync(join(outside, "out.ts"), join(root, "src/out.ts"));

        const { result, byOwner } = scan(
            root,
            [
                "src/big.ts",
                "src/dir.ts",
                "src/gone.ts",
                "src/notes.txt",
                "src/out.ts",
                "bin/plain",
            ],
            { limits: { max_file_bytes: 60 } },
        );

        expect(result.input_hashes).toEqual({
            "src/big.ts": null,
            "src/dir.ts": null,
            "src/gone.ts": null,
            "src/out.ts": null,
        });
        expect(Object.keys(byOwner).sort()).toEqual([
            "bin/plain",
            "src/big.ts",
            "src/dir.ts",
            "src/gone.ts",
            "src/notes.txt",
            "src/out.ts",
        ]);
        for (const contribution of Object.values(byOwner)) {
            expect(contribution.nodes).toEqual([]);
            expect(contribution.diagnostics[0].code).toBe(
                "TS_UNSCANNABLE_FILE",
            );
        }
    });

    it("reports null for an extensionless requested path whose shebang cannot be read", () => {
        // The shebang probe runs on a path that just resolved: a directory there
        // is a failed read, not a script naming another interpreter.
        const root = fixture({});
        fs.mkdirSync(join(root, "bin/tool"), { recursive: true });

        const { result, byOwner } = scan(root, ["bin/tool"]);

        expect(result.input_hashes).toEqual({ "bin/tool": null });
        expect(byOwner["bin/tool"].nodes).toEqual([]);
    });

    it("emits a requested file swapped for a link to another file under its own key, as a failed read", () => {
        // Reproduction: src/b.ts became a link to src/c.ts. The file was read
        // under c's realpath and emitted as c, so the core stood an empty
        // contribution in for b and dropped c's, with a map that only named c.
        const root = fixture({
            "src/b.ts": "export class B {}\n",
            "src/c.ts": "export class C {}\n",
        });
        fs.unlinkSync(join(root, "src/b.ts"));
        fs.symlinkSync("c.ts", join(root, "src/b.ts"));

        const { result, byOwner } = scan(root, ["src/b.ts"]);

        expect(Object.keys(byOwner)).toEqual(["src/b.ts"]);
        expect(byOwner["src/b.ts"].nodes).toEqual([]);
        expect(byOwner["src/b.ts"].diagnostics[0].message).toBe(
            "TypeScript input no longer resolves to itself: src/b.ts",
        );
        expect(result.input_hashes).toEqual({ "src/b.ts": null });
    });
});

describe("a requested file whose extension is not in lower case", () => {
    it("is scanned, hashed and keyed under its own name", () => {
        // TypeScript recognises only lower-case extensions, so FOO.TS was in
        // no program and lost its facts on every scan of a stable tree.
        const root = fixture({
            "src/FOO.TS": "export class Foo {}\n",
            "src/Bar.Tsx": "export const Bar = () => null;\n",
        });

        const { result, byOwner } = scan(root, ["src/Bar.Tsx", "src/FOO.TS"]);

        expect(result.input_hashes).toEqual({
            "src/Bar.Tsx": sha256("export const Bar = () => null;\n"),
            "src/FOO.TS": sha256("export class Foo {}\n"),
        });
        expect(byOwner["src/FOO.TS"].content_hash).toBe(
            sha256("export class Foo {}\n"),
        );
        expect(
            byOwner["src/FOO.TS"].nodes.map((node) => node.canonical_name),
        ).toContain("src/FOO.TS#Foo");
        expect(byOwner["src/Bar.Tsx"].nodes.length).toBeGreaterThan(0);
    });
});

describe("input_hashes: a file that grows past the cap before the host reads it", () => {
    it("reads at most one byte past the cap and reports null", () => {
        const importer = 'import { B } from "./b";\nexport const a = B;\n';
        const root = fixture({
            "src/a.ts": importer,
            "src/b.ts": "export const B = 1;\n",
        });
        const b = join(root, "src/b.ts");
        const openSync = fs.openSync;
        const readSync = fs.readSync;
        const closeSync = fs.closeSync;
        const descriptors = new Set();
        let bytesOfB = 0;
        const opens = vi
            .spyOn(fs, "openSync")
            .mockImplementation((file, ...rest) => {
                const descriptor = openSync(file, ...rest);
                if (String(file) === b) descriptors.add(descriptor);
                return descriptor;
            });
        const reads = vi
            .spyOn(fs, "readSync")
            .mockImplementation((descriptor, ...rest) => {
                const read = readSync(descriptor, ...rest);
                if (descriptors.has(descriptor)) bytesOfB += read;
                return read;
            });
        // Descriptor numbers are reused once closed.
        const closes = vi
            .spyOn(fs, "closeSync")
            .mockImplementation((descriptor) => {
                descriptors.delete(descriptor);
                return closeSync(descriptor);
            });
        let result;
        try {
            ({ result } = scan(root, ["src/a.ts"], {
                limits: { max_file_bytes: 100 },
                observeHostPath: (stage, absolute) => {
                    if (stage === "read" && absolute === b)
                        fs.writeFileSync(b, "x".repeat(1_000_000));
                },
            }));
        } finally {
            opens.mockRestore();
            reads.mockRestore();
            closes.mockRestore();
        }

        expect(bytesOfB).toBe(101);
        expect(result.input_hashes).toEqual({
            "src/a.ts": sha256(importer),
            "src/b.ts": null,
        });
    });
});

describe("the byte cap and the default library", () => {
    it("reads default-library declaration files whatever the cap", () => {
        // lib.d.ts is far over a 500-byte cap; capped, `toUpperCase` would not
        // resolve and the file would carry TS2339 instead of its call edge.
        const root = fixture({
            "src/a.ts":
                'export function f(): string {\n    return "a".toUpperCase();\n}\n',
        });
        const contributions = [];

        new TypeScriptScanner().scan(
            { root, files: ["src/a.ts"], limits: { max_file_bytes: 500 } },
            (contribution) => contributions.push(contribution),
        );

        expect(contributions[0].diagnostics).toEqual([]);
        expect(contributions[0].edges.map((edge) => edge.target)).toContain(
            "ts:external_method:toUpperCase",
        );
    });
});
