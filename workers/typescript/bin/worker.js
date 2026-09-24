#!/usr/bin/env node

import { clearInterval, setInterval } from "node:timers";
import { writeSync } from "node:fs";
import { createInterface } from "node:readline";
import { URL } from "node:url";
import { Worker } from "node:worker_threads";

import { scanThreadFailure } from "../src/scan-thread-exit.js";
import { scanThreadResourceLimits } from "../src/scan-thread-limits.js";

// How often a busy scan says so. Well inside the core's default request
// timeout (30 s), which each heartbeat restarts; tests set it lower.
const HEARTBEAT_MS =
    Number.parseInt(process.env.KNOSSOS_WORKER_HEARTBEAT_MS ?? "", 10) || 5_000;

// The scanner runs in a thread with a larger stack than the main thread gets:
// the TypeScript compiler recurses once per import while it builds a program,
// and on the default stack a linear import chain past about 1000 files threw
// out of the whole scan (see src/scan-thread-limits.js). NDJSON framing stays
// here on the main thread; the thread posts each frame back in order.
//
// The thread pulls in the TypeScript compiler, which costs about 190ms of the
// ~220ms this worker used to take to answer `initialize` — paid on every scan,
// including the ones where no TypeScript file changed and the compiler is never
// asked to parse anything. Started on first use instead, so the handshake and
// shutdown cost only Node's own startup.
let thread = null;
let pending = null;
let closing = false;
let threadError;

function scanThread() {
    if (thread === null) {
        thread = new Worker(new URL("../src/scan-thread.js", import.meta.url), {
            resourceLimits: scanThreadResourceLimits({
                execArgv: process.execArgv,
                nodeOptions: process.env.NODE_OPTIONS,
                env: process.env,
            }),
        });
        thread.on("message", onThreadMessage);
        thread.on("error", onThreadError);
        thread.on("exit", onThreadExit);
    }
    return thread;
}

const input = createInterface({ input: process.stdin, crlfDelay: Infinity });

for await (const line of input) {
    let request;
    try {
        request = JSON.parse(line);
        if (!request || Array.isArray(request) || typeof request !== "object") {
            throw new Error("Request must be a JSON object.");
        }
        await handle(request);
    } catch (error) {
        write({
            jsonrpc: "2.0",
            id: request?.id ?? null,
            error: {
                code: -32602,
                message: error instanceof Error ? error.message : String(error),
            },
        });
    }
}
closeThread();

async function handle(request) {
    const { method, id, params = {} } = request;
    if (
        typeof method !== "string" ||
        !params ||
        Array.isArray(params) ||
        typeof params !== "object"
    ) {
        throw new Error("Method and params are required.");
    }

    if (method === "cancel") return;

    let result;
    switch (method) {
        case "initialize":
            result = {
                id: "knossos.typescript",
                version: "0.6.0",
                protocol_version: "1.0",
                output_schema_version: "1.0",
                languages: ["typescript", "javascript"],
                file_extensions: [
                    "ts",
                    "tsx",
                    "mts",
                    "cts",
                    "js",
                    "jsx",
                    "mjs",
                    "cjs",
                ],
                capabilities: [
                    "project_program",
                    "partial_ast",
                    "content_hash",
                    "input_hashes",
                ],
            };
            break;
        case "scan":
            result = await scan(params);
            break;
        case "shutdown":
            result = { status: "bye" };
            break;
        default:
            throw new Error(`Unknown method: ${method}`);
    }

    write({ jsonrpc: "2.0", id, result });
    if (method === "shutdown") {
        process.exitCode = 0;
        input.close();
    }
}

/**
 * Hand one scan to the thread and settle with its result. Requests are handled
 * one at a time, as they were when the scan ran on this thread, so a `cancel`
 * or `shutdown` line is still read only once the scan in progress has answered.
 */
function scan(params) {
    const worker = scanThread();
    return new Promise((resolve, reject) => {
        // Building a program is silent until its first fact, and the core
        // times a request out on silence: say the scan is busy meanwhile.
        const heartbeat = setInterval(
            () => write({ jsonrpc: "2.0", method: "scan/heartbeat" }),
            HEARTBEAT_MS,
        );
        const settled = (settle) => (value) => {
            clearInterval(heartbeat);
            settle(value);
        };
        pending = { resolve: settled(resolve), reject: settled(reject) };
        // Held only while a scan is in flight: stdin closing mid-scan must not
        // let the process exit before the answer is written.
        worker.ref();
        worker.postMessage({ type: "scan", params });
    });
}

function onThreadMessage(message) {
    if (message.type === "frame") {
        process.stdout.write(`${message.line}\n`);
        return;
    }
    const settle = pending;
    pending = null;
    thread.unref();
    if (message.type === "result") settle.resolve(message.result);
    else settle.reject(new Error(message.message));
}

// A thread error is always followed by its exit, so the exit alone decides:
// a thread asked to close ends quietly, anything else ends the process.
function onThreadError(error) {
    threadError = error;
}

function onThreadExit(code) {
    if (closing && threadError === undefined) return;
    const failure = scanThreadFailure(threadError, code);
    writeSync(2, failure.message);
    process.exit(failure.exitCode);
}

// Let the thread finish on its own rather than terminating it, so a
// NODE_V8_COVERAGE run still writes the thread's coverage.
function closeThread() {
    if (thread === null) return;
    closing = true;
    thread.ref();
    thread.postMessage({ type: "close" });
}

function write(message) {
    process.stdout.write(`${JSON.stringify(message)}\n`);
}
