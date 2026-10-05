import { mkdtemp, mkdir, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { afterEach, describe, expect, it } from "vitest";
import { markdownFiles, matchesAny, parseJsonc } from "./lint-md.mjs";

describe("parseJsonc", () => {
    it("drops line and block comments", () => {
        expect(parseJsonc('{ // c\n "a": 1, /* b */ "b": 2 }')).toEqual({
            a: 1,
            b: 2,
        });
    });

    it("drops trailing commas before a closing bracket", () => {
        expect(parseJsonc('{ "a": [1, 2,], "b": 3, }')).toEqual({
            a: [1, 2],
            b: 3,
        });
    });

    it("leaves comment markers and commas inside strings alone", () => {
        expect(parseJsonc('{ "u": "http://x/*y*/", "c": ",}", "d": ",]" }')).toEqual({
            u: "http://x/*y*/",
            c: ",}",
            d: ",]",
        });
    });

    it("handles an escaped quote inside a string", () => {
        expect(parseJsonc('{ "q": "a\\",}" , }')).toEqual({ q: 'a",}' });
    });
});

describe("matchesAny", () => {
    it("matches exact paths and ** wildcards", () => {
        expect(matchesAny("vendor/a/b.md", ["vendor/**"])).toBe(true);
        expect(matchesAny("workers/php/vendor/x.md", ["workers/**/vendor/**"])).toBe(true);
        expect(matchesAny("docs/a.md", ["docs/a.md"])).toBe(true);
    });

    it("does not treat other characters as wildcards", () => {
        expect(matchesAny("docsxa.md", ["docs.a.md"])).toBe(false);
        expect(matchesAny("src/vendor/a.md", ["vendor/**"])).toBe(false);
    });
});

describe("markdownFiles", () => {
    let dir = "";
    afterEach(async () => {
        if (dir !== "") await rm(dir, { recursive: true, force: true });
    });

    it("walks dot-directories, prunes .git and the excluded trees only", async () => {
        dir = await mkdtemp(path.join(os.tmpdir(), "lint-md-"));
        for (const file of [
            ".github/pr.md",
            ".git/notes.md",
            "vendor/a/b.md",
            "node_modules/p/r.md",
            "src/vendor/keep.md",
            "src/node_modules/keep.md",
            "workers/php/vendor/x.md",
            "README.md",
        ]) {
            await mkdir(path.dirname(path.join(dir, file)), { recursive: true });
            await writeFile(path.join(dir, file), "# t\n");
        }
        expect((await markdownFiles(dir)).sort()).toEqual([
            ".github/pr.md",
            "README.md",
            "src/node_modules/keep.md",
            "src/vendor/keep.md",
        ]);
    });
});
