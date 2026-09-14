import { parentPort } from "node:worker_threads";

import { inputHashesParts } from "./input-hashes-parts.js";
import { TEST_CRASH_VARIABLE } from "./scan-thread-limits.js";
import { TypeScriptScanner } from "./scanner.js";

// Runs inside the scanner thread bin/worker.js starts with a larger stack. One
// scanner lives here for the life of the process, so its program cache carries
// across requests exactly as it did on the main thread.
//
// Frames are serialised here, with the same JSON.stringify the main thread
// used, so what reaches stdout is byte for byte what it was; the main thread
// only adds the response envelope that carries the request id.
const scanner = new TypeScriptScanner();

parentPort.on("message", (message) => {
    if (message.type === "close") {
        parentPort.close();
        return;
    }
    if (process.env[TEST_CRASH_VARIABLE] === "1") {
        throw new Error(
            `Scanner thread crash requested by ${TEST_CRASH_VARIABLE}.`,
        );
    }
    try {
        const result = scanner.scan(message.params, (contribution) => {
            post({
                jsonrpc: "2.0",
                method: "scan/contribution",
                params: contribution,
            });
        });
        parentPort.postMessage({
            type: "result",
            result: withInputHashesParts(result),
        });
    } catch (error) {
        parentPort.postMessage({
            type: "error",
            message: error instanceof Error ? error.message : String(error),
        });
    }
});

/**
 * Send all but the last part of the result's `input_hashes` ahead of it, so no
 * frame outgrows the core's line cap; the result keeps the last part.
 */
function withInputHashesParts(result) {
    const parts = inputHashesParts(result.input_hashes);
    for (const part of parts.slice(0, -1)) {
        post({
            jsonrpc: "2.0",
            method: "scan/input_hashes",
            params: { input_hashes: part },
        });
    }
    return { ...result, input_hashes: parts.at(-1) };
}

function post(frame) {
    parentPort.postMessage({ type: "frame", line: JSON.stringify(frame) });
}
