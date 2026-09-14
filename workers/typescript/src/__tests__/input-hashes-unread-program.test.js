import { describe, it, expect, afterEach, vi } from "vitest";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

// TypeScript's exports are non-configurable getters, so vi.spyOn cannot replace
// createProgram; the module is wrapped instead, with a hook each test sets.
const hook = vi.hoisted(() => ({ createProgram: null }));
vi.mock("typescript", async (importOriginal) => {
    const actual = (await importOriginal()).default;
    const wrapped = new Proxy(actual, {
        get(target, property, receiver) {
            if (property === "createProgram" && hook.createProgram !== null) {
                return (...args) =>
                    hook.createProgram(target.createProgram, ...args);
            }
            return Reflect.get(target, property, receiver);
        },
    });
    return { default: wrapped };
});

const { TypeScriptScanner } = await import("../scanner.js");

const created = [];

function fixture(files) {
    const root = mkdtempSync(join(tmpdir(), "knossos-ts-"));
    created.push(root);
    for (const [rel, contents] of Object.entries(files)) {
        const abs = join(root, rel);
        mkdirSync(dirname(abs), { recursive: true });
        writeFileSync(abs, contents);
    }
    return root;
}

afterEach(() => {
    hook.createProgram = null;
    while (created.length > 0) {
        rmSync(created.pop(), { recursive: true, force: true });
    }
});

describe("input_hashes for a program this request did not read", () => {
    const files = {
        "src/a.ts": 'import { B } from "./b";\nexport class A extends B {}\n',
        "src/b.ts": "export class B {}\n",
    };

    function scanner(root, program) {
        const instance = new TypeScriptScanner();
        hook.createProgram = program;
        return () => {
            const result = instance.scan(
                { root, files: ["src/a.ts"] },
                () => {},
            );
            instance.programCache.clear();
            return result;
        };
    }

    it("reports the hash each SourceFile was parsed from when an earlier request created it", () => {
        // TypeScript 6.0 never hands such a program back (see the reuse test in
        // scanner.test.js); this stands in for a compiler that would. The facts
        // come from the earlier read, so that read's hash, bound to the object,
        // is what describes them: if the file changed since, it disagrees with
        // what discovery hashed today and the scan fails.
        const root = fixture(files);
        let stale;
        const scan = scanner(root, (createProgram, ...args) => {
            stale ??= createProgram(...args);
            return stale;
        });

        const first = scan();
        writeFileSync(
            join(root, "src/b.ts"),
            "export class B { changed = 1; }\n",
        );
        const second = scan();

        expect(Object.values(first.input_hashes)).not.toContain(null);
        expect(second.input_hashes).toEqual(first.input_hashes);
    });

    it("reports null for a SourceFile no read of this worker describes", () => {
        // A program built by a host other than the worker's own: nothing binds
        // a hash to its SourceFiles.
        const root = fixture(files);
        const scan = scanner(root, (createProgram, { rootNames, options }) =>
            createProgram({ rootNames, options }),
        );

        expect(scan().input_hashes).toEqual({
            "src/a.ts": null,
            "src/b.ts": null,
        });
    });
});
