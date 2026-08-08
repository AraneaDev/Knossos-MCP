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
function ask(requests) {
    return new Promise((resolve, reject) => {
        // The child reports when it resolves the TypeScript compiler, so
        // "did the handshake load it?" is answered from the module graph rather
        // than from timing, which proves nothing about why the worker was fast.
        const hook = path.resolve(
            path.dirname(fileURLToPath(import.meta.url)),
            "support/record-typescript-load.mjs",
        );
        const marker = path.join(
            fs.mkdtempSync(path.join(os.tmpdir(), "knossos-ts-load-")),
            "loaded",
        );
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
            const responses = out
                .split("\n")
                .filter((line) => line.trim() !== "")
                .map((line) => JSON.parse(line));
            resolve({
                responses,
                stderr: err,
                loadedCompiler: fs.existsSync(marker),
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
            expect(loadedCompiler).toBe(true);
        },
        SUBPROCESS_AND_PROGRAM_TIMEOUT_MS,
    );
});
