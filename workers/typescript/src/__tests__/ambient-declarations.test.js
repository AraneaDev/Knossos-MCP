import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

// An ambient `declare module 'x'` in a project declaration file satisfies
// `import … from 'x'` only when that file is in the importer's program. A
// file no tsconfig includes is read in a program of the files requested with
// it, so an incremental scan of the importer alone, or a batch that split the
// two, used to report the import as a missing module (TS2307).

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function fixture(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-ambient-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    return root;
}

const FILES = {
    "hooks/tsconfig.json":
        '{"compilerOptions":{"strict":true,"noEmit":true},"include":["lib/**/*.ts"]}',
    "hooks/lib/a.ts": "export const a = 1;\n",
    "hooks/host.d.ts":
        "declare module 'host-engine' {\n  export function atom(x: number): number\n}\n",
    "hooks/register.tsx":
        "import { atom } from 'host-engine'\nexport const v = atom(1)\n",
    "other/elsewhere.d.ts":
        "declare module 'elsewhere' {\n  export const e: number\n}\n",
    "other/uses.ts": "import { e } from 'elsewhere'\nexport const u = e\n",
};

function scan(root, files, declarationFiles) {
    const contributions = [];
    const result = new TypeScriptScanner().scan(
        {
            root,
            files,
            config_files: ["hooks/tsconfig.json"],
            declaration_files: declarationFiles,
        },
        (c) => contributions.push(c),
    );
    return { contributions, result };
}

describe("a file outside every tsconfig importing an ambient module", () => {
    it("resolves it when the declaration is scanned in another request", () => {
        const root = fixture(FILES);

        const { contributions, result } = scan(
            root,
            ["hooks/register.tsx"],
            ["hooks/host.d.ts", "other/elsewhere.d.ts"],
        );

        expect(contributions).toHaveLength(1);
        expect(contributions[0].owner_key).toBe(
            "knossos.typescript:file:hooks/register.tsx",
        );
        expect(contributions[0].diagnostics).toEqual([]);
        // The declaration was read, so a change to it rescans the importer.
        expect(Object.keys(result.input_hashes)).toContain("hooks/host.d.ts");
    });

    it("does not lend a declaration to a different package's files", () => {
        const root = fixture({
            ...FILES,
            "other/package.json": '{"name":"other"}',
            "other/uses.ts":
                "import { atom } from 'host-engine'\nexport const u = atom(2)\n",
        });
        const contributions = [];
        new TypeScriptScanner().scan(
            {
                root,
                files: ["other/uses.ts"],
                config_files: ["hooks/tsconfig.json"],
                declaration_files: ["hooks/host.d.ts", "other/elsewhere.d.ts"],
                package_directories: ["other"],
            },
            (c) => contributions.push(c),
        );

        expect(contributions[0].diagnostics.map((d) => d.code)).toEqual([
            "TS2307",
        ]);
    });

    it("still reports a module no declaration names", () => {
        const root = fixture({
            ...FILES,
            "hooks/register.tsx":
                "import { atom } from 'nowhere'\nexport const v = atom\n",
        });

        const { contributions } = scan(
            root,
            ["hooks/register.tsx"],
            ["hooks/host.d.ts"],
        );

        expect(contributions[0].diagnostics.map((d) => d.code)).toEqual([
            "TS2307",
        ]);
    });

    it("ignores a declaration path that is not a project-relative name", () => {
        const root = fixture(FILES);

        const { contributions } = scan(
            root,
            ["hooks/register.tsx"],
            ["../outside.d.ts", "/abs/x.d.ts", 42, "hooks/host.d.ts"],
        );

        expect(contributions[0].diagnostics).toEqual([]);
    });
});
