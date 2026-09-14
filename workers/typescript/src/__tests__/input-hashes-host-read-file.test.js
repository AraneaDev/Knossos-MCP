import { afterEach, describe, expect, it, vi } from "vitest";
import fs from "node:fs";
import { createHash } from "node:crypto";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

const created = [];

function fixture(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-read-file-")),
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

const tsconfig = JSON.stringify({
    compilerOptions: { module: "nodenext", moduleResolution: "nodenext" },
    include: ["src/**/*", "sub/**/*"],
});
const importer = 'import { s } from "../sub/s.js";\nexport const a = s;\n';
const packageJson = '{"type":"module"}\n';

function scan(root) {
    return new TypeScriptScanner({}).scan(
        { root, files: ["src/a.ts"] },
        () => {},
    );
}

// Module resolution reads package.json through the compiler host's readFile,
// and the fields it reads there decide how an import resolves.
describe("input_hashes: a package.json module resolution reads", () => {
    it("is recorded by the hash of the bytes read", () => {
        const root = fixture({
            "tsconfig.json": tsconfig,
            "package.json": packageJson,
            "src/a.ts": importer,
            "sub/package.json": packageJson,
            "sub/s.ts": "export const s = 1;\n",
        });

        const result = scan(root);

        expect(result.input_hashes["package.json"]).toBe(sha256(packageJson));
        expect(result.input_hashes["sub/package.json"]).toBe(
            sha256(packageJson),
        );
    });

    it("is recorded as null when the read fails", () => {
        const root = fixture({
            "tsconfig.json": tsconfig,
            "package.json": packageJson,
            "src/a.ts": importer,
            "sub/package.json": packageJson,
            "sub/s.ts": "export const s = 1;\n",
        });
        const failing = join(root, "sub/package.json");
        const openSync = fs.openSync;
        const spy = vi
            .spyOn(fs, "openSync")
            .mockImplementation((target, ...rest) => {
                if (String(target) === failing)
                    throw new Error("EIO: the read failed");
                return openSync(target, ...rest);
            });
        let result;
        try {
            result = scan(root);
        } finally {
            spy.mockRestore();
        }

        expect(result.input_hashes["sub/package.json"]).toBeNull();
        expect(result.input_hashes["package.json"]).toBe(sha256(packageJson));
    });
});
