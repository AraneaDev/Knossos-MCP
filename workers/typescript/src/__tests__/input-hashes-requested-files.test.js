import { afterEach, describe, expect, it } from "vitest";
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

    it("does not report a stable requested file the compiler never loads", () => {
        // TypeScript does not recognise an upper-case extension, so FOO.TS is
        // in no program on any scan; a null would fail every scan of the tree.
        const root = fixture({
            "src/FOO.TS": "export const foo = 1;\n",
            "src/bar.ts": "export const bar = 1;\n",
        });

        const { result, byOwner } = scan(root, ["src/FOO.TS", "src/bar.ts"]);

        expect(result.input_hashes).toEqual({
            "src/bar.ts": sha256("export const bar = 1;\n"),
        });
        expect(byOwner["src/FOO.TS"].nodes).toEqual([]);
    });

    it("reports null for a requested file in no program that is no longer readable as itself", () => {
        const root = fixture({
            "src/FOO.TS": "export const foo = 1;\n",
            "src/bar.ts": "export const bar = 1;\n",
        });
        let removed = false;

        const { result } = scan(root, ["src/FOO.TS", "src/bar.ts"], {
            observeHostPath: (stage, absolute) => {
                if (removed || !absolute.endsWith("/src/bar.ts")) return;
                removed = true;
                fs.unlinkSync(join(root, "src/FOO.TS"));
            },
        });

        expect(removed).toBe(true);
        expect(result.input_hashes).toEqual({
            "src/FOO.TS": null,
            "src/bar.ts": sha256("export const bar = 1;\n"),
        });
    });
});
