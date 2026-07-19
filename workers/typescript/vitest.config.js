import { defineConfig } from "vitest/config";

export default defineConfig({
    test: {
        include: ["src/__tests__/**/*.test.js"],
        environment: "node",
        globals: false,
        coverage: {
            provider: "v8",
            include: ["src/**/*.js"],
            exclude: ["src/__tests__/**"],
            reporter: ["text"],
        },
    },
});
