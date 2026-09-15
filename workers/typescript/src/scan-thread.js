import { parentPort } from "node:worker_threads";
import v8 from "node:v8";

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

/**
 * How much heap this thread has needed, and the cap it runs under.
 *
 * Reported because the number is otherwise invisible until the process dies of
 * it, and the death is not graceful: the worker aborts and the scan commits a
 * graph with that whole language missing. A mid-sized Vue project ran against
 * a 1024 MB cap for a long time looking like an intermittent fault.
 *
 * Both figures are sampled when a request finishes and kept as running maxima,
 * so what the core merges across a language's batches is already the highest
 * this worker has seen. `used` is live data at that moment, which a collection
 * just before the sample can leave far below the request's true high-water
 * mark; `total` is the heap V8 has actually taken and does not give back
 * eagerly, so it is the better guide to how close the cap is to being hit.
 * Neither is a continuous peak — sampling for one would cost more than the
 * number is worth.
 */
let maxUsedMb = 0;
let maxTotalMb = 0;

function heapReport() {
    const stats = v8.getHeapStatistics();
    const mb = (bytes) => Math.round(bytes / 1048576);
    maxUsedMb = Math.max(maxUsedMb, mb(stats.used_heap_size));
    maxTotalMb = Math.max(maxTotalMb, mb(stats.total_heap_size));

    return {
        used_mb: maxUsedMb,
        total_mb: maxTotalMb,
        limit_mb: mb(stats.heap_size_limit),
    };
}

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
            result: { ...withInputHashesParts(result), heap: heapReport() },
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
