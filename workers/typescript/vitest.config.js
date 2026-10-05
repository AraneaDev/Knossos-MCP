import { defineConfig } from "vitest/config";

export default defineConfig({
    test: {
        include: ["src/__tests__/**/*.test.js"],
        environment: "node",
        // Each fork loads the TypeScript compiler and peaks near 600 MB, so
        // one per core ran an 8-core, 6 GB machine out of memory and the
        // runner reported "Worker exited unexpectedly"; four still did, beside
        // the rest of a quality run. Two leaves room for it.
        maxWorkers: 2,
        // That peak was garbage, not a leak: after a forced collection a
        // fork's heap stays near 35 MB through all of scanner.test.js, but V8
        // sizes its heap to the host and left the rest uncollected. On a host
        // with an OOM guard (earlyoom SIGTERMs the largest process below 15%
        // free; a fork is "node-MainThread", which its --avoid list misses)
        // one run in five lost a fork that way, as "Worker exited
        // unexpectedly". Capping old space keeps a fork near its live set:
        // the heaviest file peaks at 455 MB instead of 770 MB, no slower.
        execArgv: ["--max-old-space-size=256"],
        globals: false,
        coverage: {
            provider: "v8",
            include: ["src/**/*.js"],
            exclude: ["src/__tests__/**"],
            reporter: ["text"],
        },
    },
});
