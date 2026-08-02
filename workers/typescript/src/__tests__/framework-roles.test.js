import { describe, it, expect, afterEach } from "vitest";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { TypeScriptScanner } from "../scanner.js";

const created = [];

/** Materialize a { relativePath: contents } map into a fresh temp project root. */
function fixture(files) {
    const root = mkdtempSync(join(tmpdir(), "knossos-roles-"));
    created.push(root);
    for (const [rel, contents] of Object.entries(files)) {
        const abs = join(root, rel);
        mkdirSync(dirname(abs), { recursive: true });
        writeFileSync(abs, contents);
    }
    return root;
}

/** Every framework role the scan assigned, keyed by declaration display name. */
function rolesByName(root, files) {
    const contributions = [];
    new TypeScriptScanner().scan({ root, files }, (c) => contributions.push(c));
    const roles = {};
    for (const node of contributions.flatMap((c) => c.nodes)) {
        const assigned = node.attributes?.typescript_framework_roles ?? [];
        if (assigned.length > 0) roles[node.display_name] = assigned;
    }
    return roles;
}

afterEach(() => {
    while (created.length > 0) {
        rmSync(created.pop(), { recursive: true, force: true });
    }
});

describe("react and vue role detection", () => {
    // The TypeScript compiler reports languageVariant JSX for every .js, .jsx,
    // .mjs and .cjs file — only .ts is Standard. Keying the React guard on that
    // tagged every capitalized declaration in any plain JavaScript file as a
    // component, which is how this scanner's own TypeScriptScanner and
    // FactAccumulator classes came to be labelled React components.
    it("does not label plain JavaScript classes as react components", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "src/scanner.js": [
                "export class FactAccumulator {",
                "  add(fact) { return fact; }",
                "}",
                "export function BuildIndex(items) { return items.length; }",
                "",
            ].join("\n"),
        });

        expect(rolesByName(root, ["src/scanner.js"])).toEqual({});
    });

    it("labels a component that actually returns JSX, in a .js file too", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "src/Widget.jsx": [
                "import React from 'react';",
                "export function Widget() { return <div>hi</div>; }",
                "",
            ].join("\n"),
            "src/Legacy.js": [
                "import React from 'react';",
                "export function Legacy() { return <span>old</span>; }",
                "",
            ].join("\n"),
        });

        const roles = rolesByName(root, ["src/Widget.jsx", "src/Legacy.js"]);

        expect(roles.Widget).toContain("react.component");
        expect(roles.Legacy).toContain("react.component");
    });

    it("does not label a use-prefixed function a react hook outside react", () => {
        // `useDatabase` in a Node service is not a hook; the rule carried no
        // React guard at all, so any use-prefixed function anywhere matched.
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "src/db.ts": [
                "export function useDatabase() { return 1; }",
                "",
            ].join("\n"),
        });

        expect(rolesByName(root, ["src/db.ts"])).toEqual({});
    });

    it("labels a use-prefixed function a react hook when react is imported", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "src/hooks.ts": [
                "import { useState } from 'react';",
                "export function useCounter() { return useState(0); }",
                "",
            ].join("\n"),
        });

        expect(rolesByName(root, ["src/hooks.ts"]).useCounter).toContain(
            "react.hook",
        );
    });

    it("keys vue composables on the vue import, not on the file path", () => {
        // The rule tested whether the path contained "vue", so any file under a
        // directory like `revue/` or `value-objects/` matched.
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "src/value-objects/money.ts": [
                "export function useMoney() { return 1; }",
                "",
            ].join("\n"),
            "src/composables/counter.ts": [
                "import { ref } from 'vue';",
                "export function useCounter() { return ref(0); }",
                "",
            ].join("\n"),
        });

        const roles = rolesByName(root, [
            "src/value-objects/money.ts",
            "src/composables/counter.ts",
        ]);

        expect(roles.useMoney ?? []).not.toContain("vue.composable");
        expect(roles.useCounter).toContain("vue.composable");
        // A Vue composable is not also a React hook.
        expect(roles.useCounter).not.toContain("react.hook");
    });
});
