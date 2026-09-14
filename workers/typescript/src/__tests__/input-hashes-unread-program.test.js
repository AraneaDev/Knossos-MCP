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

describe("input_hashes for a requested file the compiler leaves out of every program", () => {
    // Stands in for a compiler that drops a root file without asking the host
    // for it, which the worker's backstop answers with a facts-free
    // contribution.
    const dropping = (dropped) => (createProgram, options) =>
        createProgram({
            ...options,
            rootNames: options.rootNames.filter(
                (name) => !name.endsWith(dropped),
            ),
        });

    function scanDropping(root, onScan = () => {}) {
        hook.createProgram = (createProgram, options) => {
            onScan();
            return dropping("/src/b.ts")(createProgram, options);
        };
        const contributions = [];
        const result = new TypeScriptScanner().scan(
            { root, files: ["src/a.ts", "src/b.ts"] },
            (contribution) => contributions.push(contribution),
        );
        return { result, contributions };
    }

    it("reports nothing for a stable file that is still readable as itself", () => {
        // A null here would fail every scan of a tree that is not changing.
        const root = fixture({
            "src/a.ts": "export const a = 1;\n",
            "src/b.ts": "export const b = 1;\n",
        });

        const { result, contributions } = scanDropping(root);

        expect(Object.keys(result.input_hashes)).toEqual(["src/a.ts"]);
        expect(
            contributions.find((c) => c.owner_key.endsWith(":src/b.ts"))
                .diagnostics[0].code,
        ).toBe("TS_UNSCANNABLE_FILE");
    });

    it("reports null for a file that is no longer readable as itself", () => {
        const root = fixture({
            "src/a.ts": "export const a = 1;\n",
            "src/b.ts": "export const b = 1;\n",
        });

        const { result } = scanDropping(root, () =>
            rmSync(join(root, "src/b.ts")),
        );

        expect(result.input_hashes["src/b.ts"]).toBeNull();
    });
});
