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

function scan(files, requested = Object.keys(files), configFiles, extra = {}) {
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
            ...extra,
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

// An edge from `file` to a target containing `name`, other than the
// declaring module's own `contains` edge.
const reaches = (contributions, file, name) =>
    contributions
        .flatMap((c) => c.edges)
        .some(
            (e) =>
                e.kind !== "contains" &&
                e.source.includes(file) &&
                e.target.includes(name),
        );

describe("template usages", () => {
    it("reach a setup handler and an imported helper from the template", () => {
        const { contributions } = scan({
            "src/util.ts":
                "export function formatDate(): string { return ''; }\n",
            "src/App.vue":
                '<template>\n  <button @click="onSave">{{ formatDate() }}</button>\n</template>\n<script setup lang="ts">\nimport { formatDate } from "./util";\nfunction onSave(): void {}\n</script>\n',
        });
        expect(
            reaches(contributions, "src/App.vue", "src/App.vue#onSave"),
        ).toBe(true);
        expect(
            reaches(contributions, "src/App.vue", "src/util.ts#formatDate"),
        ).toBe(true);
    });

    it("reach a component named by a kebab-case tag", () => {
        const { contributions } = scan({
            "src/cards.ts": "export class UserCard {}\n",
            "src/List.vue":
                '<template><user-card /></template>\n<script setup lang="ts">\nimport { UserCard } from "./cards";\n</script>\n',
        });

        expect(
            reaches(contributions, "src/List.vue", "src/cards.ts#UserCard"),
        ).toBe(true);
    });

    it("reach Svelte handlers and Astro frontmatter imports", () => {
        const { contributions } = scan({
            "src/lib/counter.ts": "export function increment(): void {}\n",
            "src/lib/site.ts":
                "export function title(): string { return ''; }\n",
            "src/lib/Counter.svelte":
                '<script lang="ts">\nimport { increment } from "./counter";\n</script>\n<button on:click={increment}>+</button>\n',
            "src/pages/index.astro":
                "---\nimport { title } from '../lib/site';\n---\n<h1>{title()}</h1>\n",
        });
        expect(
            reaches(
                contributions,
                "src/lib/Counter.svelte",
                "src/lib/counter.ts#increment",
            ),
        ).toBe(true);
        expect(
            reaches(
                contributions,
                "src/pages/index.astro",
                "src/lib/site.ts#title",
            ),
        ).toBe(true);
    });

    it("list names the template uses but nothing declares", () => {
        const { contributions } = scan({
            "src/Legacy.vue":
                '<template><button @click="save">{{ label }}</button></template>\n<script>\nexport default { methods: { save() {} }, computed: { label() { return ""; } } };\n</script>\n',
        });
        const module = contributions
            .flatMap((c) => c.nodes)
            .find((n) => n.kind === "module");

        expect(module.attributes.unresolved_member_calls).toEqual([
            "label",
            "save",
        ]);
    });

    it("count a bare identifier statement as a value use in plain TypeScript", () => {
        const { contributions } = scan({
            "src/a.ts": "export function handler(): void {}\n",
            "src/b.ts":
                'import { handler } from "./a";\nhandler;\n(handler);\n',
        });

        expect(reaches(contributions, "src/b.ts", "src/a.ts#handler")).toBe(
            true,
        );
    });
});

describe("a component as a module", () => {
    it("is importable with nothing to import or export itself", () => {
        const { contributions } = scan({
            "src/App.vue":
                '<template><user-card /></template>\n<script setup lang="ts">\nimport UserCard from "./UserCard.vue";\n</script>\n',
            "src/UserCard.vue":
                '<template><p>card</p></template>\n<script setup lang="ts"></script>\n',
            "src/main.ts":
                'import App from "./App.vue";\nexport default App;\n',
        });
        const imports = edges(contributions, "imports").map(
            (e) => `${e.source} -> ${e.target}`,
        );

        expect(imports).toContain(
            "ts:module:src/App.vue -> ts:module:src/UserCard.vue",
        );
        // A component's default export is its compiled component, which the
        // virtual source does not spell out.
        expect(
            contributions.flatMap((c) => c.diagnostics).map((d) => d.code),
        ).toEqual([]);
    });
});

describe("a component a dependency publishes", () => {
    it("is recorded with its real hash when resolution realpaths it", () => {
        // A package whose `main` is a `.vue` file: resolution asks for the
        // realpath of the alias, which exists nowhere on disk.
        const vue =
            "<template><p/></template>\n<script>\nexport default { name: 'x' };\n</script>\n";
        const { result } = scan(
            {
                "node_modules/typeahead/package.json":
                    '{"name":"typeahead","main":"src/Typeahead.vue"}',
                "node_modules/typeahead/src/Typeahead.vue": vue,
                "src/Page.vue":
                    "<template><p/></template>\n<script>\nimport Typeahead from 'typeahead';\nexport default { components: { Typeahead } };\n</script>\n",
            },
            ["src/Page.vue"],
        );

        expect(
            result.input_hashes["node_modules/typeahead/src/Typeahead.vue"],
        ).toBe(createHash("sha256").update(vue).digest("hex"));
    });
});

describe("an Astro component's Props", () => {
    it("is read by Astro, so the component references it", () => {
        const { contributions } = scan({
            "src/Card.astro":
                "---\ninterface Props {\n    title: string;\n}\nconst { title } = Astro.props;\n---\n<h2>{title}</h2>\n",
            "src/Tag.astro":
                "---\ntype Props = { name: string };\n---\n<span />\n",
        });

        expect(
            reaches(contributions, "src/Card.astro", "src/Card.astro#Props"),
        ).toBe(true);
        expect(
            reaches(contributions, "src/Tag.astro", "src/Tag.astro#Props"),
        ).toBe(true);
    });
});

describe("a SvelteKit app without its generated tsconfig", () => {
    it("resolves $lib and the aliases its config declares", () => {
        // `.svelte-kit/tsconfig.json`, which holds these paths, is generated
        // and ignored, so a checkout never has it.
        const { contributions } = scan(
            {
                "apps/web/svelte.config.js":
                    "const config = { kit: { alias: { $components: 'src/components', '$icons/*': './src/icons/*' } } };\nexport default config;\n",
                "apps/web/tsconfig.json":
                    '{"extends":"./.svelte-kit/tsconfig.json","compilerOptions":{"strict":true}}',
                "apps/web/src/routes/+page.svelte":
                    "<script lang=\"ts\">\nimport Button from '$components/Button.svelte';\nimport Star from '$icons/Star.svelte';\nimport { format } from '$lib/format';\n</script>\n<Button>{format()}</Button><Star />\n",
                "apps/web/src/components/Button.svelte":
                    "<button><slot /></button>\n",
                "apps/web/src/icons/Star.svelte": "<svg />\n",
                "apps/web/src/lib/format.ts":
                    "export function format(): string { return ''; }\n",
            },
            [
                "apps/web/src/routes/+page.svelte",
                "apps/web/src/components/Button.svelte",
                "apps/web/src/icons/Star.svelte",
                "apps/web/src/lib/format.ts",
            ],
            ["apps/web/tsconfig.json"],
        );
        const imports = edges(contributions, "imports").map((e) => e.target);

        expect(imports).toContain(
            "ts:module:apps/web/src/components/Button.svelte",
        );
        expect(imports).toContain("ts:module:apps/web/src/icons/Star.svelte");
        expect(imports).toContain("ts:module:apps/web/src/lib/format.ts");
    });
});

describe("an extensionless import of a Vue component", () => {
    const files = {
        "src/components/Card.vue":
            "<template><div/></template>\n<script>\nexport default { name: 'Card' };\n</script>\n",
        "src/main.js":
            "import Card from './components/Card';\nexport default [Card];\n",
        "src/other.js":
            "import Missing from './components/Nothing';\nexport default Missing;\n",
    };

    it("follows the manifest, not which files a request holds", () => {
        // An incremental scan sends only what changed: the component is not in
        // the request, but the package still depends on Vue.
        const alone = scan(files, ["src/main.js"], undefined, {
            vue_projects: [""],
        });
        expect(
            edges(alone.contributions, "imports").map((e) => e.target),
        ).toContain("ts:module:src/components/Card.vue");

        // No manifest declares Vue: a `.vue` file in the request changes nothing.
        const unrelated = scan(files);
        expect(
            edges(unrelated.contributions, "imports").map((e) => e.target),
        ).not.toContain("ts:module:src/components/Card.vue");
    });

    it("resolves as webpack's resolve.extensions does", () => {
        const { contributions } = scan(files, Object.keys(files), undefined, {
            vue_projects: [""],
        });
        const imports = edges(contributions, "imports").map(
            (e) => `${e.source} -> ${e.target}`,
        );

        expect(imports).toContain(
            "ts:module:src/main.js -> ts:module:src/components/Card.vue",
        );
        expect(imports.some((i) => i.includes("Nothing.vue"))).toBe(false);
    });
});

describe("a Vue Options API component", () => {
    const source = `<template>
  <button @click="save">{{ label }}</button>
</template>
<script>
export default {
    data() { return { n: 0 }; },
    mounted() { this.helper(); },
    metaInfo() { return {}; },
    watch: { "$route.query.id"() {}, n(value) {} },
    computed: { label() { return ""; } },
    methods: {
        save() {},
        helper() {},
        unused() {},
    },
};
</script>
`;

    it("marks what Vue calls itself, and references what the component uses", () => {
        const { contributions } = scan({ "src/Form.vue": source });
        const nodes = contributions.flatMap((c) => c.nodes);
        const invoked = nodes
            .filter((n) => n.attributes.runtime_invoked === true)
            .map((n) => n.display_name)
            .sort();
        const referenced = contributions
            .flatMap((c) => c.edges)
            .filter(
                (e) =>
                    e.kind === "references" &&
                    e.source === "ts:module:src/Form.vue",
            )
            .map((e) => e.target.split("::").at(-1))
            .sort();

        expect(invoked).toEqual([
            "$route.query.id",
            "data",
            "metaInfo",
            "mounted",
            "n",
        ]);
        expect(referenced).toEqual(["helper", "label", "save"]);
    });
});

describe("a bundler's module aliases", () => {
    it("resolve imports when no tsconfig maps them", () => {
        const { contributions } = scan(
            {
                "webpack.mix.js":
                    "const path = require('path');\nmix.webpackConfig({ resolve: { alias: { '~': path.join(__dirname, './resources/js'), vue$: 'vue/dist/vue.esm.js' } } });\n",
                "resources/js/app.js":
                    "import App from '~/components/App';\nexport default App;\n",
                "resources/js/components/App.vue":
                    "<template><div/></template>\n<script>\nexport default { name: 'App' };\n</script>\n",
            },
            undefined,
            undefined,
            { vue_projects: [""] },
        );

        expect(edges(contributions, "imports").map((e) => e.target)).toContain(
            "ts:module:resources/js/components/App.vue",
        );
    });

    it("read Vite's URL form", () => {
        const { contributions } = scan({
            "vite.config.ts":
                "import { fileURLToPath, URL } from 'node:url';\nexport default { resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } } };\n",
            "src/main.ts": "import { util } from '@/util';\nutil();\n",
            "src/util.ts": "export function util(): void {}\n",
        });

        expect(edges(contributions, "imports").map((e) => e.target)).toContain(
            "ts:module:src/util.ts",
        );
    });
});

describe("a Vue prop's factories", () => {
    it("are called by Vue", () => {
        const { contributions } = scan({
            "src/List.vue":
                "<template><ul/></template>\n<script>\nexport default { props: { items: { type: Array, default() { return []; }, validator(v) { return true; } } } };\n</script>\n",
        });
        const invoked = contributions
            .flatMap((c) => c.nodes)
            .filter((n) => n.attributes.runtime_invoked === true)
            .map((n) => n.display_name)
            .sort();

        expect(invoked).toEqual(["default", "validator"]);
    });
});

describe("webpack's require.context", () => {
    it("names the directory, recursion and pattern for the core to expand", () => {
        // Which files it loads is the whole graph's business: a request holds
        // only the files that changed.
        const { contributions } = scan({
            "webpack.mix.js":
                "const path = require('path');\nmix.webpackConfig({ resolve: { alias: { '~': path.join(__dirname, './js') } } });\n",
            "js/store/index.js":
                "const modules = require.context('./modules', false, /.*\\.js$/g);\nexport default modules;\n",
            "js/App.vue":
                "<template><div/></template>\n<script>\nconst layouts = require.context('~/layouts');\nexport default { layouts };\n</script>\n",
        });
        const contexts = edges(contributions, "imports")
            .filter((e) => e.target.startsWith("ts:module_context:"))
            .map((e) => [
                e.source,
                JSON.parse(e.target.slice("ts:module_context:".length)),
            ])
            .sort((a, b) => a[0].localeCompare(b[0]));

        expect(contexts).toEqual([
            [
                "ts:module:js/App.vue",
                {
                    directory: "js/layouts",
                    recursive: true,
                    pattern: "^\\.\\/.*$",
                    flags: "",
                },
            ],
            [
                "ts:module:js/store/index.js",
                // The stateful `g` flag is dropped.
                {
                    directory: "js/store/modules",
                    recursive: false,
                    pattern: ".*\\.js$",
                    flags: "",
                },
            ],
        ]);
    });
});

describe("require() in a plain JavaScript component script", () => {
    it("imports what it names, as it does in a .js file", () => {
        const { contributions } = scan({
            "src/util.js": "export function util() {}\n",
            "src/Foo.vue": "<template><div/></template>\n",
            "src/App.vue":
                "<template><div/></template>\n<script>\nconst util = require('./util');\nexport default { components: { Foo: require('./Foo.vue').default }, x: obj.require('./not') };\n</script>\n",
        });
        const imports = edges(contributions, "imports").map((e) => e.target);

        expect(imports).toContain("ts:module:src/util.js");
        expect(imports).toContain("ts:module:src/Foo.vue");
        expect(imports.some((t) => t.includes("not"))).toBe(false);
    });
});

describe("Vite's alias forms", () => {
    it("reads root-relative replacements and the array form", () => {
        const { contributions } = scan({
            "vite.config.ts":
                "export default { resolve: { alias: [{ find: '~', replacement: '/src' }, { find: /^re$/, replacement: '/src' }] } };\n",
            "src/main.ts": "import { util } from '~/util';\nutil();\n",
            "src/util.ts": "export function util(): void {}\n",
        });
        const imports = edges(contributions, "imports").map((e) => e.target);

        expect(imports).toContain("ts:module:src/util.ts");
    });
});

describe("require.context through tsconfig paths", () => {
    it("takes the longest matching prefix, as the compiler does", () => {
        const { contributions } = scan(
            {
                "tsconfig.json":
                    '{"compilerOptions":{"allowJs":true,"baseUrl":".","paths":{"*":["vendor/*"],"~/*":["src/*"]}},"include":["src"]}',
                "src/store.js":
                    "const layouts = require.context('~/layouts');\nexport default layouts;\n",
            },
            ["src/store.js"],
            ["tsconfig.json"],
        );
        const directories = edges(contributions, "imports")
            .filter((e) => e.target.startsWith("ts:module_context:"))
            .map(
                (e) =>
                    JSON.parse(e.target.slice("ts:module_context:".length))
                        .directory,
            );

        expect(directories).toEqual(["src/layouts"]);
    });
});

describe("an import that names a component", () => {
    it("means the component even with a same-named .ts file beside it", () => {
        const { contributions } = scan({
            "src/Card.vue":
                "<template><div/></template>\n<script>\nexport default {};\n</script>\n",
            "src/Card.vue.ts": "export const shim = 1;\n",
            "src/Page.vue":
                "<template><Card /></template>\n<script>\nimport Card from './Card.vue';\nexport default { components: { Card } };\n</script>\n",
        });
        const imports = edges(contributions, "imports")
            .filter((e) => e.source === "ts:module:src/Page.vue")
            .map((e) => e.target);

        expect(imports).toEqual(["ts:module:src/Card.vue"]);
    });
});
