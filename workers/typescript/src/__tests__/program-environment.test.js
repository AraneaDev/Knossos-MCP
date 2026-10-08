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

function scan(root, files, configFiles = ["tsconfig.json"]) {
    const contributions = [];
    new TypeScriptScanner().scan(
        { root, files, config_files: configFiles },
        (contribution) => contributions.push(contribution),
    );
    return Object.fromEntries(
        contributions.map((contribution) => [
            contribution.owner_key.replace("knossos.typescript:file:", ""),
            contribution,
        ]),
    );
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

    it("leaves a contribution no program described without either", () => {
        const root = fixture(LAYOUT);

        const byOwner = scan(root, ["src/a.ts", "src/gone.ts"]);

        expect(byOwner["src/gone.ts"]).not.toHaveProperty("program");
        expect(byOwner["src/gone.ts"]).not.toHaveProperty("environment");
    });
});
