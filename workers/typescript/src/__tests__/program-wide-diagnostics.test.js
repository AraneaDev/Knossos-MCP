import { describe, it, expect, afterEach, vi } from "vitest";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

// TypeScript's exports are non-configurable getters, so vi.spyOn cannot replace
// createProgram; the module is wrapped instead, with a hook each test sets.
const hook = vi.hoisted(() => ({
    createProgram: null,
    getPreEmitDiagnostics: null,
}));
vi.mock("typescript", async (importOriginal) => {
    const actual = (await importOriginal()).default;
    const wrapped = new Proxy(actual, {
        get(target, property, receiver) {
            if (property === "createProgram" && hook.createProgram !== null) {
                return (...args) =>
                    hook.createProgram(target.createProgram, ...args);
            }
            if (
                property === "getPreEmitDiagnostics" &&
                hook.getPreEmitDiagnostics !== null
            ) {
                return (...args) =>
                    hook.getPreEmitDiagnostics(
                        target.getPreEmitDiagnostics,
                        ...args,
                    );
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
    hook.getPreEmitDiagnostics = null;
    vi.restoreAllMocks();
    while (created.length > 0) {
        rmSync(created.pop(), { recursive: true, force: true });
    }
});

function scan(scanner, root, files, configFiles) {
    const contributions = [];
    scanner.scan({ root, files, config_files: configFiles }, (contribution) =>
        contributions.push(contribution),
    );
    return Object.fromEntries(
        contributions.map((contribution) => [
            contribution.owner_key.replace("knossos.typescript:file:", ""),
            contribution,
        ]),
    );
}

const optionError = {
    file: undefined,
    start: undefined,
    length: undefined,
    category: 1,
    code: 5023,
    messageText: "Unknown compiler option.",
};

const programWide = (contribution) =>
    contribution.diagnostics.filter(
        (diagnostic) => diagnostic.code === "TS5023",
    );

const files = {
    "tsconfig.json": JSON.stringify({ include: ["src"] }),
    "src/a.ts": "export const a = 1;\n",
    "src/b.ts": "export const b = 2;\n",
};

describe("a diagnostic that names no file", () => {
    it("is attached once, to the program's first file, however the files are batched", () => {
        const root = fixture(files);
        hook.getPreEmitDiagnostics = (real, program) => [
            ...real(program),
            optionError,
        ];
        const scanner = new TypeScriptScanner();

        const first = scan(scanner, root, ["src/b.ts"], ["tsconfig.json"]);
        const second = scan(scanner, root, ["src/a.ts"], ["tsconfig.json"]);

        expect(programWide(first["src/b.ts"])).toEqual([]);
        expect(programWide(second["src/a.ts"])).toEqual([
            {
                severity: "error",
                code: "TS5023",
                message:
                    "Unknown compiler option. (applies to the whole program)",
                evidence: { path: "src/a.ts", start_line: 1, end_line: 1 },
            },
        ]);
    });

    it("is not attached at all when the program's first file is not requested", () => {
        const root = fixture(files);
        hook.getPreEmitDiagnostics = (real, program) => [
            ...real(program),
            optionError,
        ];

        const byPath = scan(
            new TypeScriptScanner(),
            root,
            ["src/b.ts"],
            ["tsconfig.json"],
        );

        expect(Object.keys(byPath)).toEqual(["src/b.ts"]);
        expect(programWide(byPath["src/b.ts"])).toEqual([]);
    });

    it("is still attached when collecting the first file's facts fails", () => {
        const root = fixture(files);
        hook.getPreEmitDiagnostics = (real, program) => [
            ...real(program),
            optionError,
        ];
        hook.createProgram = (createProgram, options) => {
            const program = createProgram(options);
            return new Proxy(program, {
                get(target, property, receiver) {
                    if (property !== "getSourceFiles")
                        return Reflect.get(target, property, receiver);
                    return () =>
                        target.getSourceFiles().map((sourceFile) =>
                            sourceFile.fileName.endsWith("/src/a.ts")
                                ? new Proxy(sourceFile, {
                                      get(file, key, fileReceiver) {
                                          if (key === "statements")
                                              throw new Error(
                                                  "collector failed",
                                              );
                                          return Reflect.get(
                                              file,
                                              key,
                                              fileReceiver,
                                          );
                                      },
                                  })
                                : sourceFile,
                        );
                },
            });
        };

        const byPath = scan(
            new TypeScriptScanner(),
            root,
            ["src/a.ts", "src/b.ts"],
            ["tsconfig.json"],
        );

        expect(byPath["src/a.ts"].diagnostics.map((item) => item.code)).toEqual(
            ["TS_INTERNAL_ERROR", "TS5023"],
        );
        expect(programWide(byPath["src/b.ts"])).toEqual([]);
    });
});

describe("the fallback program for files outside every tsconfig", () => {
    const deprecated = {
        "tsconfig.json": JSON.stringify({
            compilerOptions: { baseUrl: ".", ignoreDeprecations: "6.0" },
            include: ["src"],
        }),
        "src/a.ts": "export const a = 1;\n",
        "test/a.test.ts": 'import { a } from "src/a";\nexport const t = a;\n',
    };

    it("honours the config's ignoreDeprecations along with its baseUrl", () => {
        const root = fixture(deprecated);

        const byPath = scan(
            new TypeScriptScanner(),
            root,
            ["src/a.ts", "test/a.test.ts"],
            ["tsconfig.json"],
        );

        expect(Object.keys(byPath).sort()).toEqual([
            "src/a.ts",
            "test/a.test.ts",
        ]);
        for (const contribution of Object.values(byPath)) {
            expect(
                contribution.diagnostics.map((item) => item.code),
            ).not.toContain("TS5101");
        }
    });

    it("never reports a diagnostic that names no file", () => {
        const root = fixture(deprecated);
        // Every program, the fallback included, is handed an option error.
        hook.getPreEmitDiagnostics = (real, program) => [
            ...real(program),
            optionError,
        ];

        const byPath = scan(
            new TypeScriptScanner(),
            root,
            ["test/a.test.ts"],
            ["tsconfig.json"],
        );

        expect(programWide(byPath["test/a.test.ts"])).toEqual([]);
    });
});
