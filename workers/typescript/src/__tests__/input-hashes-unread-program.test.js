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
    it("reports null for every project file whose SourceFile an earlier request created", () => {
        // A program whose SourceFiles came from an earlier request's reads must
        // not vouch for today's bytes with that earlier read's hash. TypeScript
        // 6.0 never hands such a program back (see the reuse test in
        // scanner.test.js); this stands in for a compiler that would.
        const root = fixture({
            "src/a.ts":
                'import { B } from "./b";\nexport class A extends B {}\n',
            "src/b.ts": "export class B {}\n",
        });
        const scanner = new TypeScriptScanner();
        let stale;
        hook.createProgram = (createProgram, ...args) => {
            stale ??= createProgram(...args);
            return stale;
        };
        const scan = () => {
            const contributions = [];
            const result = scanner.scan({ root, files: ["src/a.ts"] }, (c) =>
                contributions.push(c),
            );
            return { result, contributions };
        };

        const first = scan();
        scanner.programCache.clear();
        const second = scan();

        expect(Object.values(first.result.input_hashes)).not.toContain(null);
        expect(second.result.input_hashes).toEqual({
            "src/a.ts": null,
            "src/b.ts": null,
        });
    });
});
