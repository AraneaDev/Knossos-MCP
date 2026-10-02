import js from "@eslint/js";
import tseslint from "typescript-eslint";

export default [
    {
        ignores: ["node_modules/**", "workers/**/node_modules/**", "vendor/**"],
    },
    js.configs.recommended,
    ...tseslint.configs.recommended.map((config) => ({
        ...config,
        files: ["hooks/**/*.{ts,tsx}"],
    })),
    {
        files: ["workers/typescript/**/*.js"],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: "module",
            globals: {
                Buffer: "readonly",
                console: "readonly",
                process: "readonly",
                setTimeout: "readonly",
            },
        },
        rules: {
            complexity: ["error", 29],
            "max-lines-per-function": [
                "error",
                { max: 101, skipBlankLines: true, skipComments: true },
            ],
        },
    },
];
