import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import { fileURLToPath } from "node:url";
import path from "node:path";
import { describe, expect, it } from "vitest";

const workerPath = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    "../../bin/worker.js",
);

/**
 * Send one request per line and resolve with the responses, in order.
 *
 * @param {Array<object>} requests
 * @returns {Promise<{responses: Array<object>, stderr: string, loadedCompiler: boolean}>}
 */
function ask(requests, { strict = true } = {}) {
    return new Promise((resolve, reject) => {
        // The child reports when it resolves the TypeScript compiler, so
        // "did the handshake load it?" is answered from the module graph rather
        // than from timing, which proves nothing about why the worker was fast.
        const hook = path.resolve(
            path.dirname(fileURLToPath(import.meta.url)),
            "support/record-typescript-load.mjs",
        );
        const directory = fs.mkdtempSync(
            path.join(os.tmpdir(), "knossos-ts-load-"),
        );
        const marker = path.join(directory, "loaded");
        const child = spawn(process.execPath, ["--import", hook, workerPath], {
            stdio: ["pipe", "pipe", "pipe"],
            env: { ...process.env, KNOSSOS_TS_LOAD_MARKER: marker },
        });
        let out = "";
        let err = "";
        child.stdout.on("data", (chunk) => {
            out += chunk;
        });
        child.stderr.on("data", (chunk) => {
            err += chunk;
        });
        child.on("error", reject);
        child.on("close", () => {
            const loadedCompiler = fs.existsSync(marker);
            fs.rmSync(directory, { recursive: true, force: true });
            const lines = out.split("\n").filter((line) => line.trim() !== "");
            const responses = strict
                ? lines.map((line) => JSON.parse(line))
                : [];
            resolve({
                lines,
                responses,
                stderr: err,
                loadedCompiler,
            });
        });
        for (const request of requests) {
            child.stdin.write(`${JSON.stringify(request)}\n`);
        }
        child.stdin.end();
    });
}

// Spawning a real worker subprocess and building a full ts.Program via
// ts.createProgram measures ~2s on a quiet run. Vitest's 5000ms default
// leaves no headroom for that under the quality gate's parallel load
// (concurrent docker build + PHP suite), which is exactly how this test
// timed out there. 30s gives real headroom without masking an actual hang.
const SUBPROCESS_AND_PROGRAM_TIMEOUT_MS = 30000;

describe("worker startup", () => {
    it("answers the handshake without loading the TypeScript compiler", async () => {
        // The compiler is roughly 190ms of load time and is not needed to say
        // what this worker is. A scan that changed no TypeScript file pays that
        // for nothing, on every scan.
        const { responses, loadedCompiler } = await ask([
            { jsonrpc: "2.0", id: 1, method: "initialize", params: {} },
            { jsonrpc: "2.0", id: 2, method: "shutdown", params: {} },
        ]);

        expect(responses.map((item) => item.id)).toEqual([1, 2]);
        expect(responses[0].result.id).toBe("knossos.typescript");
        expect(responses[0].result.capabilities).toEqual([
            "project_program",
            "partial_ast",
            "content_hash",
            "input_hashes",
        ]);
        expect(responses[1].result.status).toBe("bye");
        expect(loadedCompiler).toBe(false);
    });

    it(
        "loads the compiler when a scan actually needs it",
        async () => {
            const root = path.resolve(
                path.dirname(fileURLToPath(import.meta.url)),
                "../../../../tests/Fixtures/typescript-scanner",
            );
            const { responses, loadedCompiler } = await ask([
                { jsonrpc: "2.0", id: 1, method: "initialize", params: {} },
                {
                    jsonrpc: "2.0",
                    id: 2,
                    method: "scan",
                    params: {
                        root,
                        files: ["packages/shared/src/contracts.ts"],
                    },
                },
                { jsonrpc: "2.0", id: 3, method: "shutdown", params: {} },
            ]);

            const scan = responses.find((item) => item.id === 2);
            expect(scan.error).toBeUndefined();
            expect(scan.result.files_scanned).toBe(1);
            expect(Object.keys(scan.result.input_hashes)).toContain(
                "packages/shared/src/contracts.ts",
            );
            expect(loadedCompiler).toBe(true);
        },
        SUBPROCESS_AND_PROGRAM_TIMEOUT_MS,
    );
});

describe("worker stdout under module resolution tracing", () => {
    function project(files) {
        const root = fs.realpathSync(
            fs.mkdtempSync(path.join(os.tmpdir(), "knossos-ts-trace-")),
        );
        for (const [name, content] of Object.entries(files)) {
            fs.mkdirSync(path.dirname(path.join(root, name)), {
                recursive: true,
            });
            fs.writeFileSync(path.join(root, name), content);
        }
        return root;
    }

    async function scanLines(root, file) {
        const { lines } = await ask(
            [
                { jsonrpc: "2.0", id: 1, method: "initialize", params: {} },
                {
                    jsonrpc: "2.0",
                    id: 2,
                    method: "scan",
                    params: { root, files: [file] },
                },
                { jsonrpc: "2.0", id: 3, method: "shutdown", params: {} },
            ],
            { strict: false },
        );
        return lines;
    }

    function expectOnlyFrames(lines, file) {
        const bad = lines.filter((line) => {
            try {
                JSON.parse(line);
                return false;
            } catch {
                return true;
            }
        });
        expect(bad).toEqual([]);
        const scan = lines.map((line) => JSON.parse(line)).find((m) => m.id === 2);
        expect(scan?.error).toBeUndefined();
        expect(Object.keys(scan.result.input_hashes)).toContain(file);
    }

    it(
        "keeps trace lines off stdout when the root tsconfig turns tracing on",
        async () => {
            const root = project({
                "tsconfig.json": JSON.stringify({
                    compilerOptions: { traceResolution: true },
                    include: ["src"],
                }),
                "src/a.ts": 'import { b } from "./b";\nexport const a = b;\n',
                "src/b.ts": "export const b = 1;\n",
            });
            try {
                expectOnlyFrames(await scanLines(root, "src/a.ts"), "src/a.ts");
            } finally {
                fs.rmSync(root, { recursive: true, force: true });
            }
        },
        SUBPROCESS_AND_PROGRAM_TIMEOUT_MS,
    );

    it(
        "keeps trace lines off stdout when only a referenced project turns tracing on",
        async () => {
            const root = project({
                "tsconfig.json": JSON.stringify({
                    files: [],
                    references: [{ path: "./lib" }],
                }),
                "lib/tsconfig.json": JSON.stringify({
                    compilerOptions: {
                        composite: true,
                        traceResolution: true,
                    },
                    include: ["src"],
                }),
                "lib/src/a.ts":
                    'import { b } from "./b";\nexport const a = b;\n',
                "lib/src/b.ts": "export const b = 1;\n",
            });
            try {
                expectOnlyFrames(
                    await scanLines(root, "lib/src/a.ts"),
                    "lib/src/a.ts",
                );
            } finally {
                fs.rmSync(root, { recursive: true, force: true });
            }
        },
        SUBPROCESS_AND_PROGRAM_TIMEOUT_MS,
    );
});
