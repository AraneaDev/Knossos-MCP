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
        // Most of that peak is garbage, not a leak: after a forced collection
        // a fork's heap stays a few tens of MB through all of scanner.test.js,
        // but V8 sizes its heap to the host's memory and leaves the rest
        // uncollected. Anything that stops a fork under memory pressure (the
        // kernel, or a userspace OOM guard signalling the largest process)
        // shows up as "Worker exited unexpectedly". Capping old space keeps a
        // fork near its live set at no cost in speed; measured on one 6 GB
        // host, the heaviest file's peak fell from about 770 MB to 455 MB.
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
