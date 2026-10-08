import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

const created = [];

function fixture(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-environment-")),
    );
    created.push(root);
    write(root, files);
    return root;
}

function write(root, files) {
    for (const [relative, contents] of Object.entries(files)) {
        const absolute = join(root, relative);
        fs.mkdirSync(dirname(absolute), { recursive: true });
        fs.writeFileSync(absolute, contents);
    }
}

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function scan(root, files, configFiles = ["tsconfig.json"], params = {}) {
    const contributions = [];
    const result = new TypeScriptScanner().scan(
        { root, files, config_files: configFiles, ...params },
        (contribution) => contributions.push(contribution),
    );
    const byOwner = Object.fromEntries(
        contributions.map((contribution) => [
            contribution.owner_key.replace("knossos.typescript:file:", ""),
            contribution,
        ]),
    );
    byOwner.result = result;
    return byOwner;
}

const TSCONFIG = JSON.stringify({
    compilerOptions: { module: "esnext", moduleResolution: "bundler" },
    include: ["src"],
});

const LAYOUT = {
    "tsconfig.json": TSCONFIG,
    "node_modules/zone/package.json": JSON.stringify({
        name: "zone",
        types: "index.d.ts",
    }),
    "node_modules/zone/index.d.ts": "declare function zfun(): void;\n",
    "src/globals.d.ts": "declare function gfun(): void;\n",
    "src/a.ts": "export const a = 1;\n",
    "src/d.ts": "export function d(): void { zfun(); gfun(); }\n",
};

describe("the program and global environment of each contribution", () => {
    it("names the program and a digest of its global declarations", () => {
        const root = fixture(LAYOUT);

        const byOwner = scan(root, ["src/a.ts", "src/d.ts"]);

        expect(byOwner["src/a.ts"].program).toBe("tsconfig.json");
        expect(byOwner["src/a.ts"].environment).toMatch(/^[0-9a-f]{64}$/);
        expect(byOwner["src/d.ts"].environment).toBe(
            byOwner["src/a.ts"].environment,
        );
    });

    it("keeps the digest across scans and ordinary edits", () => {
        const root = fixture(LAYOUT);
        const before = scan(root, ["src/a.ts", "src/d.ts"]);

        write(root, { "src/a.ts": "export const a = 2;\n" });
        const after = scan(root, ["src/a.ts"]);

        expect(after["src/a.ts"].environment).toBe(
            before["src/a.ts"].environment,
        );
    });

    it("changes the digest when a global enters, leaves or changes", () => {
        const root = fixture(LAYOUT);
        const base = scan(root, ["src/a.ts"])["src/a.ts"].environment;

        write(root, { "src/a.ts": "import 'zone';\nexport const a = 1;\n" });
        const entered = scan(root, ["src/a.ts"])["src/a.ts"].environment;
        write(root, {
            "src/globals.d.ts": "declare function gfun(): number;\n",
        });
        const changed = scan(root, ["src/a.ts"])["src/a.ts"].environment;
        write(root, {
            "src/a.ts": "export const a = 1;\n",
            "src/m.ts":
                "export const z = 1;\ndeclare global { function hello(): void }\n",
        });
        const augmented = scan(root, ["src/a.ts"])["src/a.ts"].environment;

        expect(new Set([base, entered, changed, augmented]).size).toBe(4);
    });
});

describe("the environment of a program built for another program's file", () => {
    it("reports the environment of every program built, emitting or not", () => {
        // packages/shared/x.ts is included by both configs and emitted by
        // the first; the second is built for it all the same, so the core
        // learns what p2's reused contributions now see.
        const config = JSON.stringify({
            compilerOptions: { module: "esnext", moduleResolution: "bundler" },
            include: [".", "../shared"],
        });
        const root = fixture({
            "tsconfig.json": JSON.stringify({ files: [] }),
            "packages/p1/tsconfig.json": config,
            "packages/p2/tsconfig.json": config,
            "node_modules/zone/package.json": JSON.stringify({
                name: "zone",
                types: "index.d.ts",
            }),
            "node_modules/zone/index.d.ts": "declare function zfun(): void;\n",
            "packages/shared/x.ts": "export const x = 1;\n",
            "packages/p1/a.ts": "export const a = 1;\n",
            "packages/p2/d.ts": "export function d(): void { zfun(); }\n",
        });
        const configs = [
            "packages/p1/tsconfig.json",
            "packages/p2/tsconfig.json",
            "tsconfig.json",
        ];
        const before = scan(root, ["packages/shared/x.ts"], configs);

        write(root, {
            "packages/shared/x.ts": "import 'zone';\nexport const x = 1;\n",
        });
        const after = scan(root, ["packages/shared/x.ts"], configs);

        expect(before["packages/shared/x.ts"].program).toBe(
            "packages/p1/tsconfig.json",
        );
        expect(Object.keys(before.result.environments)).toEqual([
            "packages/p1/tsconfig.json",
            "packages/p2/tsconfig.json",
        ]);
        expect(before.result.environments["packages/p1/tsconfig.json"]).toBe(
            before["packages/shared/x.ts"].environment,
        );
        expect(after.result.environments["packages/p2/tsconfig.json"]).not.toBe(
            before.result.environments["packages/p2/tsconfig.json"],
        );
        expect(after.result.programs).toBe(2);
    });
});

describe("the environment of a program no config describes", () => {
    it("gives a file no config includes the same environment whichever files are requested", () => {
        const sourceFiles = [
            "src/m.ts",
            "test/a.test.ts",
            "test/b.test.ts",
            "test/setup.ts",
        ];
        const root = fixture({
            "tsconfig.json": JSON.stringify({ include: ["src"] }),
            "src/m.ts": "export const m = 1;\n",
            "test/setup.ts":
                "export {};\ndeclare global { function hello(): void }\n",
            "test/a.test.ts": "hello();\nexport {};\n",
            "test/b.test.ts": "export const b = 1;\n",
        });
        const params = { source_files: sourceFiles };

        const all = scan(root, sourceFiles, ["tsconfig.json"], params);
        const one = scan(root, ["test/b.test.ts"], ["tsconfig.json"], params);
        const edited = scan(
            root,
            ["test/a.test.ts"],
            ["tsconfig.json"],
            params,
        );

        expect(one["test/b.test.ts"].program).toBe("fallback:.");
        expect(one["test/b.test.ts"].environment).toBe(
            all["test/b.test.ts"].environment,
        );
        expect(edited["test/a.test.ts"].environment).toBe(
            all["test/a.test.ts"].environment,
        );
        expect(edited["test/a.test.ts"].diagnostics).toEqual(
            all["test/a.test.ts"].diagnostics,
        );
    });
});

describe("a contribution no program described", () => {
    it("leaves a contribution no program described without either", () => {
        const root = fixture(LAYOUT);

        const byOwner = scan(root, ["src/a.ts", "src/gone.ts"]);

        expect(byOwner["src/gone.ts"]).not.toHaveProperty("program");
        expect(byOwner["src/gone.ts"]).not.toHaveProperty("environment");
    });
});
