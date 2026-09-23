import { createHash } from "node:crypto";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { afterEach, describe, expect, it } from "vitest";

import { TypeScriptScanner } from "../scanner.js";

// Components are offered to the compiler as position-preserving virtual
// TypeScript under an alias the scanner strips again, so their facts and
// every key they are reported under belong to the real file.

const created = [];

afterEach(() => {
    while (created.length > 0)
        fs.rmSync(created.pop(), { recursive: true, force: true });
});

function scan(files, requested = Object.keys(files), configFiles) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-components-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    const contributions = [];
    const result = new TypeScriptScanner().scan(
        {
            root,
            files: requested,
            ...(configFiles ? { config_files: configFiles } : {}),
        },
        (c) => contributions.push(c),
    );
    return { root, result, contributions };
}

const edges = (contributions, kind) =>
    contributions.flatMap((c) => c.edges).filter((e) => e.kind === kind);

describe("a Vue component", () => {
    it("imports what its script imports, from its own module node", () => {
        const { contributions } = scan({
            "src/util.ts":
                "export function formatDate(): string { return ''; }\n",
            "src/App.vue":
                '<template><p>{{ formatDate() }}</p></template>\n<script setup lang="ts">\nimport { formatDate } from "./util";\n</script>\n',
        });
        const app = contributions.find(
            (c) => c.owner_key === "knossos.typescript:file:src/App.vue",
        );

        expect(app.nodes.find((n) => n.kind === "module").canonical_name).toBe(
            "src/App.vue",
        );
        const calls = edges(contributions, "calls");
        expect(
            calls.some((e) => e.target.includes("src/util.ts#formatDate")),
        ).toBe(true);
        expect(
            calls.find((e) => e.target.includes("formatDate")).evidence.path,
        ).toBe("src/App.vue");
    });

    it("is reached by an import of the component through tsconfig paths", () => {
        const { contributions } = scan(
            {
                "tsconfig.json":
                    '{"compilerOptions":{"baseUrl":".","paths":{"@/*":["src/*"]},"noEmit":true},"include":["src/**/*.ts","src/**/*.vue"]}',
                "src/main.ts":
                    'import App from "@/App.vue";\nexport default App;\n',
                "src/App.vue":
                    "<template><p/></template>\n<script>\nexport default {};\n</script>\n",
            },
            ["src/main.ts", "src/App.vue"],
            ["tsconfig.json"],
        );

        expect(
            edges(contributions, "imports").some(
                (e) =>
                    e.source.includes("src/main.ts") &&
                    e.target.includes("src/App.vue"),
            ),
        ).toBe(true);
    });

    it("records the hash of the real bytes", () => {
        const source =
            "<template><p/></template>\n<script>\nexport default {};\n</script>\n";
        const { contributions } = scan({ "src/App.vue": source });
        const app = contributions.find(
            (c) => c.owner_key === "knossos.typescript:file:src/App.vue",
        );

        // A requested file's hash travels on its contribution; the core checks
        // it against discovery's hash of the same bytes.
        expect(app.content_hash).toBe(
            createHash("sha256").update(source).digest("hex"),
        );
    });

    it("keeps a real X.vue.ts beside X.vue as its own file", () => {
        const { contributions } = scan({
            "src/Card.vue":
                "<template><p/></template>\n<script>\nexport function fromVue() {}\n</script>\n",
            "src/Card.vue.ts": "export function fromTs() {}\n",
        });
        const names = contributions
            .flatMap((c) => c.nodes)
            .map((n) => n.canonical_name);

        expect(names).toContain("src/Card.vue#fromVue");
        expect(names).toContain("src/Card.vue.ts#fromTs");
    });
});

describe("component diagnostics", () => {
    it("reports a component it cannot parse, with its module node", () => {
        const { contributions } = scan({
            "src/Broken.vue": "<script>\nlet a;\n",
        });
        const broken = contributions
            .flatMap((c) => c.diagnostics)
            .filter((d) => d.code === "COMPONENT_UNPARSED");

        expect(broken).toHaveLength(1);
        expect(broken[0].message).toContain("src/Broken.vue");
        expect(
            contributions
                .flatMap((c) => c.nodes)
                .some(
                    (n) =>
                        n.kind === "module" &&
                        n.canonical_name === "src/Broken.vue",
                ),
        ).toBe(true);
    });

    it("scans a template-only component without diagnostics", () => {
        const { contributions } = scan({
            "src/Static.vue": "<template><p>hi</p></template>\n",
        });

        expect(contributions.flatMap((c) => c.diagnostics)).toEqual([]);
    });

    it("drops template and framework-global diagnostics, keeps script ones", () => {
        const { contributions } = scan({
            "src/Form.vue":
                '<template><p>{{ notDeclared }}</p></template>\n<script setup lang="ts">\nconst props = defineProps<{ a: string }>();\nconst n: number = "x";\n</script>\n',
        });
        const codes = contributions
            .flatMap((c) => c.diagnostics)
            .map((d) => d.code);

        expect(codes).toEqual(["TS2322"]);
    });
});
