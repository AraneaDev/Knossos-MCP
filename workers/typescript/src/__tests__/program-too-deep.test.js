import { describe, it, expect, afterEach, vi } from "vitest";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { scanThreadFailure } from "../scan-thread-exit.js";
import {
    SCAN_THREAD_STACK_MB,
    TEST_HEAP_MB_VARIABLE,
    TEST_STACK_MB_VARIABLE,
    scanThreadResourceLimits,
} from "../scan-thread-limits.js";

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

function overflow() {
    return new RangeError("Maximum call stack size exceeded");
}

function scan(root, files, configFiles) {
    const contributions = [];
    const result = new TypeScriptScanner().scan(
        { root, files, config_files: configFiles },
        (contribution) => contributions.push(contribution),
    );
    const byPath = Object.fromEntries(
        contributions.map((contribution) => [
            contribution.owner_key.replace("knossos.typescript:file:", ""),
            contribution,
        ]),
    );
    return { result, byPath, contributions };
}

const codes = (contribution) =>
    contribution.diagnostics.map((diagnostic) => diagnostic.code);

describe("a program whose construction overflows the stack", () => {
    const files = {
        "tsconfig.json": JSON.stringify({ include: ["deep"] }),
        "deep/a.ts": 'import { b } from "./b";\nexport const a = b;\n',
        "deep/b.ts": "export const b = 1;\n",
        "other/ok.ts": "export class Ok {}\n",
    };

    it("reports the files of that program and still scans the rest", () => {
        const root = fixture(files);
        hook.createProgram = (createProgram, options) => {
            if (options.rootNames.some((name) => name.includes("/deep/")))
                throw overflow();
            return createProgram(options);
        };

        const { result, byPath, contributions } = scan(
            root,
            ["deep/a.ts", "other/ok.ts"],
            ["tsconfig.json"],
        );

        expect(contributions).toHaveLength(2);
        expect(byPath["deep/a.ts"]).toEqual({
            owner_key: "knossos.typescript:file:deep/a.ts",
            nodes: [],
            edges: [],
            diagnostics: [
                {
                    severity: "error",
                    code: "TS_PROGRAM_TOO_DEEP",
                    message: expect.stringContaining("exceeded its stack"),
                    evidence: { path: "deep/a.ts", start_line: 1, end_line: 1 },
                },
            ],
        });
        expect(codes(byPath["other/ok.ts"])).toEqual([]);
        expect(byPath["other/ok.ts"].nodes.length).toBeGreaterThan(0);
        expect(byPath["other/ok.ts"].content_hash).toEqual(expect.any(String));
        expect(result.programs).toBe(1);
        expect(result.files_scanned).toBe(2);
    });

    it("reports every remaining file when the fallback program overflows", () => {
        const root = fixture(files);
        hook.createProgram = () => {
            throw overflow();
        };

        const { result, byPath } = scan(root, ["other/ok.ts"], []);

        expect(codes(byPath["other/ok.ts"])).toEqual(["TS_PROGRAM_TOO_DEEP"]);
        expect(result.programs).toBe(0);
    });

    it("keeps any other RangeError fatal to the request", () => {
        const root = fixture(files);
        hook.createProgram = () => {
            throw new RangeError("Invalid array length");
        };

        expect(() => scan(root, ["other/ok.ts"], [])).toThrow(
            "Invalid array length",
        );
    });

    it("keeps any other error fatal to the request", () => {
        const root = fixture(files);
        hook.createProgram = () => {
            throw new Error("Maximum call stack size exceeded");
        };

        expect(() => scan(root, ["other/ok.ts"], [])).toThrow();
    });
});

describe("a program that overflows the stack after it was built", () => {
    it("reports the requested files it covers, including ones reached by import, and keeps the reads", () => {
        const root = fixture({
            "tsconfig.json": JSON.stringify({ files: ["deep/a.ts"] }),
            "deep/a.ts": 'import { b } from "./b";\nexport const a = b;\n',
            "deep/b.ts": "export const b = 1;\n",
        });
        hook.createProgram = (createProgram, options) => {
            const program = createProgram(options);
            // Only the config's program: the fallback, handed whatever this
            // one left unreported, must build normally.
            if (!options.rootNames.some((name) => name.endsWith("/deep/a.ts")))
                return program;
            return new Proxy(program, {
                get(target, property, receiver) {
                    if (property === "getTypeChecker")
                        return () => {
                            throw overflow();
                        };
                    return Reflect.get(target, property, receiver);
                },
            });
        };

        const { result, byPath } = scan(
            root,
            ["deep/a.ts", "deep/b.ts"],
            ["tsconfig.json"],
        );

        expect(codes(byPath["deep/a.ts"])).toEqual(["TS_PROGRAM_TOO_DEEP"]);
        expect(codes(byPath["deep/b.ts"])).toEqual(["TS_PROGRAM_TOO_DEEP"]);
        expect(byPath["deep/b.ts"].content_hash).toBeUndefined();
        expect(result.input_hashes["deep/a.ts"]).toEqual(expect.any(String));
        expect(result.input_hashes["deep/b.ts"]).toEqual(expect.any(String));
    });
});

describe("scanThreadResourceLimits", () => {
    it("gives the thread the large stack and no heap cap of its own by default", () => {
        expect(scanThreadResourceLimits()).toEqual({
            stackSizeMb: SCAN_THREAD_STACK_MB,
        });
        expect(SCAN_THREAD_STACK_MB).toBe(64);
    });

    it("mirrors the process's --max-old-space-size, the last one winning", () => {
        expect(
            scanThreadResourceLimits({
                nodeOptions:
                    "  --max-old-space-size=512  --enable-source-maps ",
                execArgv: ["--max_old_space_size", "1024"],
            }),
        ).toEqual({ stackSizeMb: 64, maxOldGenerationSizeMb: 1024 });
        expect(
            scanThreadResourceLimits({
                nodeOptions: "--max-old-space-size=512",
                execArgv: ["--max-old-space-size=abc"],
            }),
        ).toEqual({ stackSizeMb: 64, maxOldGenerationSizeMb: 512 });
        expect(
            scanThreadResourceLimits({ execArgv: ["--max-old-space-size"] }),
        ).toEqual({ stackSizeMb: 64 });
    });

    it("lets the test-only variables lower the stack and the heap", () => {
        expect(
            scanThreadResourceLimits({
                execArgv: ["--max-old-space-size=1024"],
                env: {
                    [TEST_STACK_MB_VARIABLE]: "0.5",
                    [TEST_HEAP_MB_VARIABLE]: "8",
                },
            }),
        ).toEqual({ stackSizeMb: 0.5, maxOldGenerationSizeMb: 8 });
    });

    it("ignores test variables that are not positive numbers", () => {
        for (const value of ["", " ", "0", "-1", "x", "Infinity"]) {
            expect(
                scanThreadResourceLimits({
                    env: {
                        [TEST_STACK_MB_VARIABLE]: value,
                        [TEST_HEAP_MB_VARIABLE]: value,
                    },
                }),
            ).toEqual({ stackSizeMb: 64 });
        }
    });
});

describe("scanThreadFailure", () => {
    it("repeats V8's heap exhaustion message for a thread out of memory", () => {
        const error = Object.assign(new Error("JS heap out of memory"), {
            code: "ERR_WORKER_OUT_OF_MEMORY",
        });

        const failure = scanThreadFailure(error, 1);

        expect(failure.exitCode).toBe(134);
        expect(failure.message).toContain("\nJavaScript heap out of memory\n");
    });

    it("reports any other thread error as a crash", () => {
        const error = new RangeError("Maximum call stack size exceeded");

        expect(scanThreadFailure(error, 1)).toEqual({
            message: `FATAL ERROR: TypeScript scanner thread crashed: ${error.stack}\n`,
            exitCode: 70,
        });
        expect(scanThreadFailure("thrown text", 1)).toEqual({
            message:
                "FATAL ERROR: TypeScript scanner thread crashed: thrown text\n",
            exitCode: 70,
        });
    });

    it("never lets an unexpected exit look like success", () => {
        expect(scanThreadFailure(undefined, 0).exitCode).toBe(70);
        expect(scanThreadFailure(undefined, 3)).toEqual({
            message:
                "FATAL ERROR: TypeScript scanner thread exited unexpectedly (exit 3).\n",
            exitCode: 3,
        });
    });
});
