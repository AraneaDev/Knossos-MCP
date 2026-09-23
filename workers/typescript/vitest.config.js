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
        globals: false,
        coverage: {
            provider: "v8",
            include: ["src/**/*.js"],
            exclude: ["src/__tests__/**"],
            reporter: ["text"],
        },
    },
});
