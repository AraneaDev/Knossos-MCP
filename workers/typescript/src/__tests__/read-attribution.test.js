import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { createHash } from "node:crypto";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";
import { ALIAS_CONFIG_NAMES } from "./support/absent-alias-configs.mjs";

const created = [];

function fixture(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-reads-")),
    );
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

function scan(root, files, params = {}) {
    const contributions = [];
    const result = new TypeScriptScanner().scan(
        { root, files, ...params },
        (contribution) => contributions.push(contribution),
    );
    const byOwner = Object.fromEntries(
        contributions.map((contribution) => [
            contribution.owner_key.replace("knossos.typescript:file:", ""),
            contribution,
        ]),
    );
    assertConfirmedByInputHashes(result, contributions);
    assertEveryReadAttributed(result, contributions, files);
    return { result, byOwner };
}

/**
 * The core also refuses a read named nowhere: a change to it would rescan
 * nothing. Only a requested file's own read is its content hash instead.
 */
function assertEveryReadAttributed(result, contributions, requested) {
    const named = new Set([
        ...requested,
        ...Object.keys(result.reads),
        ...contributions.flatMap((contribution) =>
            Object.keys(contribution.reads),
        ),
    ]);
    expect(
        Object.keys(result.input_hashes).filter((key) => !named.has(key)),
    ).toEqual([]);
}

/**
 * The core refuses a read its `input_hashes` does not carry with the same
 * value, and degrades the whole language for it, so every fixture checks it.
 */
function assertConfirmedByInputHashes(result, contributions) {
    expect(result.reads).toBeTypeOf("object");
    const sources = [
        ["the result", result.reads],
        ...contributions.map((contribution) => [
            contribution.owner_key,
            contribution.reads,
        ]),
    ];
    for (const [source, reads] of sources) {
        expect(reads, `${source} carries reads`).toBeTypeOf("object");
        for (const [key, value] of Object.entries(reads)) {
            expect(
                Object.hasOwn(result.input_hashes, key),
                `${source} reads ${key}, which input_hashes lacks`,
            ).toBe(true);
            expect(
                result.input_hashes[key],
                `${source} reads ${key} with another value`,
            ).toBe(value);
        }
    }
}

const CHAIN = {
    "tsconfig.json": JSON.stringify({
        compilerOptions: { strict: true, module: "esnext", target: "es2022" },
        include: ["src"],
    }),
    "src/a.ts":
        'import { C, label } from "./b";\nimport { missing } from "./x";\nexport const a: C = { name: label + missing };\n',
    "src/b.ts": 'export { C, label } from "./c";\n',
    "src/c.ts":
        'export interface C { name: string }\nexport const label = "c";\n',
    "src/d.ts": "export const d = 1;\n",
};

const CHAIN_FILES = ["src/a.ts", "src/b.ts", "src/c.ts", "src/d.ts"];

describe("read attribution: each contribution names the files it read", () => {
    it("attributes an import to its importer and nothing to an unrelated file", () => {
        const root = fixture(CHAIN);

        const { byOwner } = scan(root, CHAIN_FILES, {
            config_files: ["tsconfig.json"],
        });

        expect(byOwner["src/a.ts"].reads["src/b.ts"]).toBe(
            sha256(CHAIN["src/b.ts"]),
        );
        expect(byOwner["src/b.ts"].reads["src/c.ts"]).toBe(
            sha256(CHAIN["src/c.ts"]),
        );
        expect(byOwner["src/d.ts"].reads).not.toHaveProperty("src/b.ts");
        expect(byOwner["src/d.ts"].reads).not.toHaveProperty("src/c.ts");
        expect(byOwner["src/d.ts"].reads).not.toHaveProperty("tsconfig.json");
        // A file's own bytes are its content_hash, not a read of another file.
        expect(byOwner["src/a.ts"].reads).not.toHaveProperty("src/a.ts");
    });

    it("attributes every candidate a missing import probed, as absent, to the importer", () => {
        const root = fixture(CHAIN);

        const { byOwner } = scan(root, CHAIN_FILES, {
            config_files: ["tsconfig.json"],
        });

        for (const candidate of ["src/x.ts", "src/x.tsx", "src/x.d.ts"]) {
            expect(byOwner["src/a.ts"].reads[candidate]).toBeNull();
        }
        expect(byOwner["src/b.ts"].reads).not.toHaveProperty("src/x.ts");
    });

    it("attributes a declaration the checker resolved through a re-export", () => {
        // `C` and `label` are declared in c.ts; a.ts reaches them through b.ts.
        const root = fixture(CHAIN);

        const { byOwner } = scan(root, ["src/a.ts"], {
            config_files: ["tsconfig.json"],
        });

        expect(byOwner["src/a.ts"].reads["src/c.ts"]).toBe(
            sha256(CHAIN["src/c.ts"]),
        );
    });
});

describe("read attribution: what every file of a request shares", () => {
    it("reports the config every file of the request shares on the result", () => {
        const root = fixture(CHAIN);

        const { result } = scan(root, CHAIN_FILES, {
            config_files: ["tsconfig.json"],
        });

        expect(result.reads["tsconfig.json"]).toBe(
            sha256(CHAIN["tsconfig.json"]),
        );
        // Owned project modules are attributed to their importers, so a change
        // to one rescans its readers, not the request.
        expect(result.reads).not.toHaveProperty("src/b.ts");
        expect(result.reads).not.toHaveProperty("src/c.ts");
        expect(result.reads).not.toHaveProperty("src/x.ts");
    });

    it("attributes the extends chain of a config to the request", () => {
        const root = fixture({
            "tsconfig.base.json": JSON.stringify({
                compilerOptions: { strict: true },
            }),
            "tsconfig.json": JSON.stringify({
                extends: "./tsconfig.base.json",
                include: ["src"],
            }),
            "src/a.ts": "export const a = 1;\n",
        });

        const { result, byOwner } = scan(root, ["src/a.ts"], {
            config_files: ["tsconfig.json"],
        });

        expect(Object.keys(result.reads)).toEqual(
            expect.arrayContaining(["tsconfig.json", "tsconfig.base.json"]),
        );
        expect(byOwner["src/a.ts"].reads).toEqual({});
    });

    it("shares the absence of each bundler config the aliases come from", () => {
        // A config created later declares aliases every import may resolve
        // through.
        const root = fixture({
            "tsconfig.json": JSON.stringify({ include: ["src"] }),
            "src/a.ts": "export const a = 1;\n",
        });

        const { result, byOwner } = scan(root, ["src/a.ts"], {
            config_files: ["tsconfig.json"],
        });

        for (const name of ALIAS_CONFIG_NAMES) {
            expect(result.input_hashes[name]).toBeNull();
            expect(result.reads[name]).toBeNull();
        }
        expect(byOwner["src/a.ts"].reads).toEqual({});
    });

    it("shares a global declaration file with the whole request", () => {
        // A script declares globals every file of the program sees, so no
        // import edge says who depends on it.
        const globals = "declare const APP_VERSION: string;\n";
        const root = fixture({
            "tsconfig.json": JSON.stringify({ include: ["src"] }),
            "src/globals.d.ts": globals,
            "src/a.ts": "export const version = APP_VERSION;\n",
        });

        const { result } = scan(root, ["src/a.ts", "src/globals.d.ts"], {
            config_files: ["tsconfig.json"],
        });

        expect(result.reads["src/globals.d.ts"]).toBe(sha256(globals));
    });
});

describe("read attribution: globals every file of a request sees", () => {
    it("shares a dependency's global declarations, which no import names", () => {
        // A type library the config names declares globals; the file that does
        // not use them yet would see one added there.
        const index = "declare function gfun(): void;\n";
        const root = fixture({
            "tsconfig.json": JSON.stringify({
                compilerOptions: { types: ["g"] },
                include: ["src"],
            }),
            "node_modules/@types/g/package.json": JSON.stringify({
                name: "@types/g",
                types: "index.d.ts",
            }),
            "node_modules/@types/g/index.d.ts": index,
            "src/a.ts": "export function a() { gfun(); }\n",
            "src/d.ts": "export function d() { hfun(); }\n",
        });

        const { result } = scan(root, ["src/a.ts", "src/d.ts"], {
            config_files: ["tsconfig.json"],
        });

        expect(result.reads["node_modules/@types/g/index.d.ts"]).toBe(
            sha256(index),
        );
    });

    it("shares a module that exports a UMD global", () => {
        const umd =
            "export declare const version: string;\nexport as namespace Lib;\n";
        const root = fixture({
            "tsconfig.json": JSON.stringify({ include: ["src"] }),
            "src/lib.d.ts": umd,
            "src/a.ts": "export const v = Lib.version;\n",
        });

        const { result } = scan(root, ["src/a.ts"], {
            config_files: ["tsconfig.json"],
        });

        expect(result.reads["src/lib.d.ts"]).toBe(sha256(umd));
    });

    it("shares what a dependency's declarations read with the whole request", () => {
        // No contribution owns a dependency's files, so a file one of them
        // imports reaches the importer only through the shared reads.
        const index = 'export { Shape } from "./shape";\n';
        const shape = "export interface Shape { size: number }\n";
        const root = fixture({
            "tsconfig.json": JSON.stringify({
                compilerOptions: {
                    moduleResolution: "bundler",
                    module: "esnext",
                },
                include: ["src"],
            }),
            "node_modules/geo/package.json": JSON.stringify({
                name: "geo",
                types: "index.d.ts",
            }),
            "node_modules/geo/index.d.ts": index,
            "node_modules/geo/shape.d.ts": shape,
            "src/a.ts":
                'import type { Shape } from "geo";\nexport const s: Shape = { size: 1 };\n',
        });

        const { result, byOwner } = scan(root, ["src/a.ts"], {
            config_files: ["tsconfig.json"],
        });

        expect(byOwner["src/a.ts"].reads["node_modules/geo/index.d.ts"]).toBe(
            sha256(index),
        );
        expect(result.reads["node_modules/geo/shape.d.ts"]).toBe(sha256(shape));
    });
});

describe("read attribution: the other reads a file's facts depend on", () => {
    it("attributes a triple-slash reference to the file that wrote it", () => {
        const shared = "declare const SHARED: number;\n";
        const root = fixture({
            "tsconfig.json": JSON.stringify({
                files: ["src/a.ts", "src/b.ts"],
            }),
            "types/shared.d.ts": shared,
            "src/a.ts":
                '/// <reference path="../types/shared.d.ts" />\nexport const a = SHARED;\n',
            "src/b.ts": "export const b = 1;\n",
        });

        const { byOwner } = scan(root, ["src/a.ts", "src/b.ts"], {
            config_files: ["tsconfig.json"],
        });

        expect(byOwner["src/a.ts"].reads["types/shared.d.ts"]).toBe(
            sha256(shared),
        );
        expect(byOwner["src/b.ts"].reads).not.toHaveProperty(
            "types/shared.d.ts",
        );
    });

    it("attributes the candidates a relative require probed to the requiring file", () => {
        const root = fixture({
            "tsconfig.json": JSON.stringify({ include: ["src"] }),
            "src/a.ts": 'const y = require("./y");\nexport { y };\n',
        });

        const { byOwner } = scan(root, ["src/a.ts"], {
            config_files: ["tsconfig.json"],
        });

        expect(byOwner["src/a.ts"].reads["src/y.js"]).toBeNull();
    });

    it("keys an imported component by the hash of its own bytes", () => {
        // A component is offered under an alias of its name, so a probe of the
        // alias must never stand for a failed read of the component.
        const card =
            '<template><p>card</p></template>\n<script setup lang="ts"></script>\n';
        const root = fixture({
            "tsconfig.json": JSON.stringify({
                include: ["src/**/*.ts", "src/**/*.vue"],
            }),
            "src/main.ts":
                'import Card from "./Card.vue";\nexport default Card;\n',
            "src/Card.vue": card,
        });

        const { result, byOwner } = scan(
            root,
            ["src/main.ts", "src/Card.vue"],
            {
                config_files: ["tsconfig.json"],
            },
        );

        expect(result.input_hashes["src/Card.vue"]).toBe(sha256(card));
        expect(byOwner["src/main.ts"].reads["src/Card.vue"]).toBe(sha256(card));
    });

    it("gives a file it could not scan an empty read set", () => {
        const root = fixture({ "src/a.ts": "export const a = 1;\n" });

        const { byOwner } = scan(root, ["src/a.ts", "src/gone.ts"]);

        expect(byOwner["src/gone.ts"].reads).toEqual({});
    });

    it("reports the same reads when the files arrive over separate requests", () => {
        const root = fixture(CHAIN);
        const scanner = new TypeScriptScanner();
        const contributions = [];
        let result;
        for (const file of CHAIN_FILES) {
            const batch = [];
            result = scanner.scan(
                { root, files: [file], config_files: ["tsconfig.json"] },
                (contribution) => batch.push(contribution),
            );
            assertConfirmedByInputHashes(result, batch);
            assertEveryReadAttributed(result, batch, [file]);
            contributions.push(...batch);
        }

        const a = contributions.find((c) => c.owner_key.endsWith("src/a.ts"));
        expect(a.reads["src/b.ts"]).toBe(sha256(CHAIN["src/b.ts"]));
        expect(a.reads["src/x.ts"]).toBeNull();
    });
});
