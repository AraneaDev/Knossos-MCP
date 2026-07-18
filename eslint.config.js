import js from "@eslint/js";

export default [
    {
        ignores: ["node_modules/**", "workers/**/node_modules/**", "vendor/**"],
    },
    js.configs.recommended,
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
