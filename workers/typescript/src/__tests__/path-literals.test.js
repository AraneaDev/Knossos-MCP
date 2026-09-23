import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

// A build script names its entry (`build({ entryPoints: ['src/boot.ts'] })`),
// a config aliases a module by path (`resolve(__dirname, 'visual/stubs.tsx')`),
// and an app registers its service worker by URL (`register('/sw.js')`). None
// is an import, so each named file read as unreferenced. The literal is the
// only evidence, so each edge is speculative: kept only for a file the graph
// holds.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function fixture(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-path-literals-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    return root;
}

describe("a source path written as a literal", () => {
    it("is a speculative import of that module", () => {
        const root = fixture({
            "packages/agent/build.ts": [
                "declare function build(options: object): Promise<void>;",
                "await build({ entryPoints: ['src/boot.ts'], outdir: 'dist' });",
                "export {};",
                "",
            ].join("\n"),
            "packages/agent/src/boot.ts": "export {};\n",
            "vite.visual.config.ts": [
                "declare function resolve(...parts: string[]): string;",
                "declare const __dirname: string;",
                "export const alias = resolve(__dirname, 'visual/stubs.tsx');",
                "export const notAModule = resolve(__dirname, 'visual/logo.svg');",
                "",
            ].join("\n"),
            "visual/stubs.tsx": "export {};\n",
            "src/main.ts": [
                "declare const navigator: { serviceWorker: { register(url: string): void } };",
                "navigator.serviceWorker.register('/sw.js');",
                "export {};",
                "",
            ].join("\n"),
            "public/sw.js": "self.addEventListener('install', () => {});\n",
        });
        const contributions = [];
        new TypeScriptScanner().scan(
            {
                root,
                files: [
                    "packages/agent/build.ts",
                    "vite.visual.config.ts",
                    "src/main.ts",
                ],
            },
            (c) => contributions.push(c),
        );
        const imports = contributions
            .flatMap((c) => c.edges)
            .filter((e) => e.kind === "imports" && e.attributes?.speculative)
            .map((e) => e.target);

        expect(imports).toContain("ts:module:packages/agent/src/boot.ts");
        expect(imports).toContain("ts:module:visual/stubs.tsx");
        expect(imports).toContain("ts:module:public/sw.js");
        expect(imports.some((t) => t.endsWith(".svg"))).toBe(false);
    });
});
