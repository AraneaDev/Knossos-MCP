#!/usr/bin/env node

import { createInterface } from "node:readline";
import { TypeScriptScanner } from "../src/scanner.js";

const scanner = new TypeScriptScanner();
const input = createInterface({ input: process.stdin, crlfDelay: Infinity });

for await (const line of input) {
    let request;
    try {
        request = JSON.parse(line);
        if (!request || Array.isArray(request) || typeof request !== "object") {
            throw new Error("Request must be a JSON object.");
        }
        handle(request);
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

function handle(request) {
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
                version: "0.3.0",
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
                capabilities: ["discover", "project_program", "partial_ast"],
            };
            break;
        case "discover":
            result = scanner.discover(params);
            break;
        case "scan":
            result = scanner.scan(params, (contribution) => {
                write({
                    jsonrpc: "2.0",
                    method: "scan/contribution",
                    params: contribution,
                });
            });
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

function write(message) {
    process.stdout.write(`${JSON.stringify(message)}\n`);
}
