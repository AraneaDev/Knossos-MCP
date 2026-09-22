import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

// A file was emitted by the first program that reached it, whichever config
// that program came from. A server config importing one shared React module
// reached the whole front end through it, and every `.tsx` file was then
// checked under the server's options, which have no `jsx`: hundreds of
// "Cannot use JSX" errors the project's own build never reports. The config
// whose own file list includes a file is the one that describes it.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function fixture(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-ownership-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    return root;
}

describe("a file is checked under the config that includes it", () => {
    it("not under a solution config that reaches it through a reference", () => {
        const root = fixture({
            // A solution config: no files of its own, only references. Sorts
            // before the project that owns src/, and its program pulls the
            // referenced sources in under its own options, which set no jsx.
            "tsconfig.json":
                '{"files":[],"references":[{"path":"./tsconfig.web.json"}]}',
            "tsconfig.web.json":
                '{"compilerOptions":{"composite":true,"noEmit":true,"jsx":"react-jsx"},"include":["src"]}',
            "src/App.tsx":
                "export function App() {\n    return <div>app</div>;\n}\n",
            "node_modules/react/package.json":
                '{"name":"react","types":"index.d.ts"}',
            "node_modules/react/index.d.ts": "export {};\n",
            "node_modules/react/jsx-runtime.d.ts":
                "export declare function jsx(...a: unknown[]): unknown;\nexport declare namespace JSX { interface IntrinsicElements { [name: string]: unknown } }\n",
        });
        const contributions = [];
        new TypeScriptScanner().scan(
            {
                root,
                files: ["src/App.tsx"],
                config_files: ["tsconfig.json", "tsconfig.web.json"],
            },
            (c) => contributions.push(c),
        );

        const app = contributions.find(
            (c) => c.owner_key === "knossos.typescript:file:src/App.tsx",
        );
        const codes = app.diagnostics.map((d) => d.code);
        expect(codes).not.toContain("TS17004");
        expect(codes).not.toContain("TS6142");
        expect(app.nodes.map((n) => n.canonical_name)).toContain(
            "src/App.tsx#App",
        );
    });

    it("resolves a referenced project's build output to its source, unbuilt", () => {
        // `import from "../lib/dist/index.js"` (or a package.json `imports`
        // alias onto it) names what `tsc -b` would emit from lib/src. The
        // output does not exist in a checkout that was never built, and build
        // output is excluded anyway, so every such import was unresolved.
        const root = fixture({
            "lib/tsconfig.json":
                '{"compilerOptions":{"composite":true,"rootDir":"src","outDir":"dist","declaration":true},"include":["src"]}',
            "lib/src/index.ts": "export function shared(): number { return 1; }\n",
            "app/tsconfig.json":
                '{"compilerOptions":{"noEmit":true,"module":"nodenext","moduleResolution":"nodenext"},"include":["src"],"references":[{"path":"../lib"}]}',
            "app/src/main.ts":
                'import { shared } from "../../lib/dist/index.js";\nexport const value = shared();\n',
        });
        const contributions = [];
        new TypeScriptScanner().scan(
            {
                root,
                files: ["app/src/main.ts", "lib/src/index.ts"],
                config_files: ["app/tsconfig.json", "lib/tsconfig.json"],
            },
            (c) => contributions.push(c),
        );

        const main = contributions.find(
            (c) => c.owner_key === "knossos.typescript:file:app/src/main.ts",
        );
        expect(main.diagnostics.map((d) => d.code)).not.toContain("TS2307");
        expect(main.edges.map((e) => e.target)).toContain(
            "ts:function:lib/src/index.ts#shared",
        );
    });
});
