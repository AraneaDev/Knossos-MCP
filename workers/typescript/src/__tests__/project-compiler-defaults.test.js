import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

// TypeScript 6 changed defaults a project built with 5.x never opted into:
// `types` went from every `@types` package to none, `strict` went from false to
// true. The worker bundles 6.x, so a 5.x project whose tsconfig left those
// unset was checked under rules its own compiler never applies, and every
// `process`, `Buffer` and implicit `any` came back as an error that `tsc`
// does not report. The project's own TypeScript decides which defaults apply:
// the core reads each manifest's declared range and sends the majors, keyed by
// the manifest's directory.

const created = [];

function fixture(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-defaults-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        const absolute = join(root, path);
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

const SOURCE =
    "export function run(value) {\n    return fooGlobal + value;\n}\n";

function tree(extra) {
    return fixture({
        "tsconfig.json": '{"compilerOptions":{"noEmit":true},"include":["src"]}',
        "src/a.ts": SOURCE,
        "node_modules/@types/foo/package.json":
            '{"name":"@types/foo","types":"index.d.ts"}',
        "node_modules/@types/foo/index.d.ts":
            "declare const fooGlobal: number;\n",
        ...extra,
    });
}

function scan(root, versions, files = ["src/a.ts"]) {
    const contributions = [];
    const result = new TypeScriptScanner().scan(
        { root, files, typescript_versions: versions },
        (c) => contributions.push(c),
    );
    const codes = contributions
        .flatMap((c) => c.diagnostics)
        .map((d) => d.code)
        .sort();
    return { codes, hashes: result.input_hashes };
}

describe("compiler defaults follow the project's own TypeScript", () => {
    it("applies 5.x defaults to a project on 5.x", () => {
        const root = tree({});

        const { codes, hashes } = scan(root, { "": 5 });

        // `fooGlobal` comes from an automatically included `@types` package,
        // and `value` is an implicit any that non-strict 5.x accepts.
        expect(codes).toEqual([]);
        // Decided from the request alone: nothing new is read to decide it.
        expect(Object.keys(hashes)).not.toContain("package.json");
    });

    it("keeps 6.x defaults for a project on 6.x", () => {
        expect(scan(tree({}), { "": 6 }).codes).toEqual(["TS2304", "TS7006"]);
    });

    it("keeps the bundled defaults when no manifest names TypeScript", () => {
        expect(scan(tree({}), {}).codes).toEqual(["TS2304", "TS7006"]);
        expect(scan(tree({}), undefined).codes).toEqual(["TS2304", "TS7006"]);
    });

    it("lets an explicit tsconfig setting win over either default", () => {
        const root = tree({
            "tsconfig.json":
                '{"compilerOptions":{"noEmit":true,"strict":true,"types":[]},"include":["src"]}',
        });

        expect(scan(root, { "": 5 }).codes).toEqual(["TS2304", "TS7006"]);
    });

    it("takes the nearest manifest at or above the config's directory", () => {
        const root = tree({
            "packages/app/tsconfig.json":
                '{"compilerOptions":{"noEmit":true,"typeRoots":["../../node_modules/@types"]},"include":["src"]}',
            "packages/app/src/b.ts": SOURCE,
        });

        expect(
            scan(root, { "": 6, "packages/app": 5 }, ["packages/app/src/b.ts"])
                .codes,
        ).toEqual([]);
        expect(
            scan(root, { "": 5, packages: 6 }, ["packages/app/src/b.ts"])
                .codes,
        ).toEqual(["TS2304", "TS7006"]);
    });
});
