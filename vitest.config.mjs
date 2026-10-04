import { defineConfig } from "vitest/config";

export default defineConfig({
    test: {
        include: [
            "hooks/lib/**/*.spec.ts",
            "hooks/mod/**/*.spec.ts",
            "tools/*.spec.mjs",
        ],
        environment: "node",
    },
});
