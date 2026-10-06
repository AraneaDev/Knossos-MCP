import { fileURLToPath } from "node:url";

import { defineConfig } from "vitest/config";

import budgets from "./coverage-budgets.json" with { type: "json" };

const jsxStub = fileURLToPath(
    new URL("./tools/jsx-runtime-stub.mjs", import.meta.url),
);

export default defineConfig({
    // The mod brings no React package: Claude Code compiles its JSX, and the suite resolves the runtime to a stub.
    resolve: {
        alias: {
            "react/jsx-dev-runtime": jsxStub,
            "react/jsx-runtime": jsxStub,
        },
    },
    test: {
        include: [
            "hooks/lib/**/*.spec.ts",
            "hooks/mod/**/*.spec.ts",
            "tools/*.spec.mjs",
            "tools/capture/*.spec.mjs",
        ],
        environment: "node",
        // hooks/register.tsx is outside this measurement because the engine loads it; tools/quality type-checks it and runs register.test.ts through `claude plugin test`.
        coverage: {
            provider: "v8",
            include: [
                "hooks/lib/**/*.ts",
                "hooks/mod/**/*.ts",
                "hooks/mod/**/*.tsx",
            ],
            exclude: ["**/*.spec.ts", "**/__tests__/**"],
            reportsDirectory: "coverage/mod",
            reporter: ["text-summary", "json-summary"],
            thresholds: budgets.mod,
        },
    },
});
