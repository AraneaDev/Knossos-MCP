import { describe, it, expect, afterEach, vi } from "vitest";
import fs, {
    mkdtempSync,
    mkdirSync,
    writeFileSync,
    rmSync,
    symlinkSync,
} from "node:fs";
import { createHash } from "node:crypto";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { TypeScriptScanner, discoverConfigFiles } from "../scanner.js";

const created = [];

/** Materialize a { relativePath: contents } map into a fresh temp project root. */
function fixture(files) {
    const root = mkdtempSync(join(tmpdir(), "knossos-ts-"));
    created.push(root);
    for (const [rel, contents] of Object.entries(files)) {
        const abs = join(root, rel);
        mkdirSync(dirname(abs), { recursive: true });
        writeFileSync(abs, contents);
    }
    return root;
}

afterEach(() => {
    while (created.length > 0) {
        rmSync(created.pop(), { recursive: true, force: true });
    }
});

describe("discoverConfigFiles", () => {
    it("discovers tsconfig files below the root when none are supplied", () => {
        const root = fixture({
            "tsconfig.json": "{}\n",
            "packages/app/tsconfig.build.json": "{}\n",
            "node_modules/dep/tsconfig.json": "{}\n",
            "a.ts": "export const a = 1;\n",
        });

        // Not re-sorted here: sorting the result before comparing it made the
        // function's own configs.sort() untestable — a `configs.sort().reverse()`
        // mutant produced the same assertion.
        expect(discoverConfigFiles(root)).toEqual([
            "packages/app/tsconfig.build.json",
            "tsconfig.json",
        ]);
    });

    // A CI job that checks this analyzer out beside the project it scans names
    // that directory in our own namespace, and only the exact ".knossos"
    // segment was excluded. Its configs were then discovered as the project's
    // own, which is how a scan ends up describing the analyzer.
    it("skips directories in the .knossos namespace, not just .knossos itself", () => {
        const root = fixture({
            "tsconfig.json": "{}\n",
            ".knossos-src/tsconfig.json": "{}\n",
            ".knossos-ci/tsconfig.json": "{}\n",
        });

        expect(discoverConfigFiles(root)).toEqual(["tsconfig.json"]);
    });
});

describe("TypeScriptScanner.scan", () => {
    it("emits module/class/method nodes and a calls edge for a method call", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/math.ts": [
                "export class Calc {",
                "  add(a: number, b: number): number {",
                "    return this.sum(a, b);",
                "  }",
                "  sum(a: number, b: number): number {",
                "    return a + b;",
                "  }",
                "}",
                "",
            ].join("\n"),
        });

        const contributions = [];
        const summary = new TypeScriptScanner().scan(
            { root, files: ["src/math.ts"] },
            (c) => contributions.push(c),
        );

        expect(summary.files_scanned).toBe(1);
        const nodes = contributions.flatMap((c) => c.nodes);
        const edges = contributions.flatMap((c) => c.edges);

        const moduleNode = nodes.find((n) => n.kind === "module");
        expect(moduleNode?.canonical_name).toBe("src/math.ts");
        expect(
            nodes.find((n) => n.kind === "class" && n.display_name === "Calc"),
        ).toBeDefined();

        const add = nodes.find(
            (n) => n.kind === "method" && n.display_name === "add",
        );
        const sum = nodes.find(
            (n) => n.kind === "method" && n.display_name === "sum",
        );
        expect(add).toBeDefined();
        expect(sum).toBeDefined();

        // The class contains its members, and add() calls sum().
        expect(edges.some((e) => e.kind === "contains")).toBe(true);
        expect(
            edges.some(
                (e) =>
                    e.kind === "calls" &&
                    e.source === add.local_id &&
                    e.target === sum.local_id,
            ),
        ).toBe(true);
    });

    it("joins a call through an object literal to the emitted method node", () => {
        // Regression guard for the declaration/reference canonicalization split:
        // a method reached through a named `const` + nested object literal was
        // declared as `src/x.ts::run` but call edges targeted
        // `src/x.ts#api.handlers::run`, so the edge dangled. Both paths must now
        // build the same id.
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/x.ts": [
                "const api = { handlers: { run() { return 1; } } };",
                "export function go(): number {",
                "  return api.handlers.run();",
                "}",
                "",
            ].join("\n"),
        });

        const contributions = [];
        new TypeScriptScanner().scan({ root, files: ["src/x.ts"] }, (c) =>
            contributions.push(c),
        );
        const nodes = contributions.flatMap((c) => c.nodes);
        const edges = contributions.flatMap((c) => c.edges);

        const run = nodes.find(
            (n) => n.kind === "method" && n.display_name === "run",
        );
        expect(run).toBeDefined();
        // The calls edge from go() resolves to the declared run method node.
        expect(
            edges.some((e) => e.kind === "calls" && e.target === run.local_id),
        ).toBe(true);
    });
});

// A `references` edge is what keeps a function that is used as a VALUE —
// dispatch tables, registries, callbacks — from being read as dead code. The
// cases below fix both directions: emitted where a use exists, and withheld
// where the identifier is a declaration or is already covered by a calls edge.
// A React component is only ever used as `<Component />`, a position no other
// handler covers: it is not a call, not a `new`, not a type annotation. Without
// a JSX clause in valueReferencePosition every component in a project has an
// in-degree of zero — a scan of a 588-file React app reported fourteen live
// components as unreferenced, all of them rendered and none of them called.
describe("TypeScriptScanner.scan JSX references", () => {
    it("emits a references edge for a component rendered as a JSX element", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false,"jsx":"react-jsx"},"include":["src"]}',
            "src/page.tsx": [
                "export function Panel() { return <span>panel</span>; }",
                "function Footer() { return <i>footer</i>; }",
                "function Unrendered() { return <b>no</b>; }",
                "export function Page() {",
                "  return <div><Panel /><Footer>x</Footer></div>;",
                "}",
                "",
            ].join("\n"),
        });

        const contributions = [];
        new TypeScriptScanner().scan({ root, files: ["src/page.tsx"] }, (c) =>
            contributions.push(c),
        );
        const nodes = contributions.flatMap((c) => c.nodes);
        const edges = contributions.flatMap((c) => c.edges);

        const idOf = (name) =>
            nodes.find((n) => n.kind === "function" && n.display_name === name)
                ?.local_id;
        const referencesTo = (name) =>
            edges.filter(
                (e) => e.kind === "references" && e.target === idOf(name),
            );

        // Self-closing and paired tags are both uses.
        expect(referencesTo("Panel").length).toBe(1);
        expect(referencesTo("Footer").length).toBe(1);
        // A component nobody renders keeps its zero, so the dead-code signal is
        // not blanket-suppressed by the new clause.
        expect(referencesTo("Unrendered")).toEqual([]);
        // `<div>` resolves to a property signature in the DOM library, which is
        // not a referenceable declaration — no edge, and no external node for
        // every intrinsic tag in the codebase.
        expect(
            edges.some(
                (e) =>
                    e.kind === "references" && String(e.target).includes("div"),
            ),
        ).toBe(false);
    });

    it("counts a paired tag once rather than once per opening and closing tag", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false,"jsx":"react-jsx"},"include":["src"]}',
            "src/twice.tsx": [
                "function Card() { return <em>c</em>; }",
                "export function List() {",
                "  return <><Card>one</Card><Card>two</Card></>;",
                "}",
                "",
            ].join("\n"),
        });

        const contributions = [];
        new TypeScriptScanner().scan({ root, files: ["src/twice.tsx"] }, (c) =>
            contributions.push(c),
        );
        const nodes = contributions.flatMap((c) => c.nodes);
        const edges = contributions.flatMap((c) => c.edges);
        const card = nodes.find(
            (n) => n.kind === "function" && n.display_name === "Card",
        );

        // Two renders of the same component collapse into one edge, and the
        // closing tags contribute nothing: the accumulator keys on
        // kind/source/target, so an edge per tag would be invisible here — the
        // guard is that the closing tag never reaches the checker at all.
        expect(
            edges.filter(
                (e) => e.kind === "references" && e.target === card.local_id,
            ).length,
        ).toBe(1);
    });
});

// A code-split route hands the module object to React and never names the
// component: `lazy(() => import('./pages/Admin'))`. The module gets its edge,
// the component inside it gets nothing.
describe("TypeScriptScanner.scan dynamic default imports", () => {
    it("reaches the default export of a dynamically imported module", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false,"module":"esnext","moduleResolution":"bundler"},"include":["src"]}',
            "src/pages/Admin.ts": [
                "export default function Admin(): string { return 'admin'; }",
                "export function helper(): string { return 'h'; }",
                "",
            ].join("\n"),
            "src/routes.ts": [
                "export const routes = [() => import('./pages/Admin')];",
                "",
            ].join("\n"),
        });

        const contributions = [];
        new TypeScriptScanner().scan(
            { root, files: ["src/pages/Admin.ts", "src/routes.ts"] },
            (c) => contributions.push(c),
        );
        const edges = contributions.flatMap((c) => c.edges);
        const targets = edges
            .filter((e) => e.kind === "references")
            .map((e) => String(e.target));

        const admin = contributions
            .flatMap((c) => c.nodes)
            .find((n) => n.display_name === "Admin");
        expect(targets).toContain(admin.local_id);
        // A named export the caller never destructures stays unreached: the
        // resolution is for `default` only, so real dead code is not marked live.
        const helper = contributions
            .flatMap((c) => c.nodes)
            .find((n) => n.display_name === "helper");
        expect(targets).not.toContain(helper.local_id);
    });

    it("emits nothing for a dynamic import of a module with no default export", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false,"module":"esnext","moduleResolution":"bundler"},"include":["src"]}',
            "src/named.ts": "export function only(): number { return 1; }\n",
            "src/load.ts": "export const load = () => import('./named');\n",
        });

        const contributions = [];
        new TypeScriptScanner().scan(
            { root, files: ["src/named.ts", "src/load.ts"] },
            (c) => contributions.push(c),
        );
        const only = contributions
            .flatMap((c) => c.nodes)
            .find((n) => n.display_name === "only");

        expect(
            contributions
                .flatMap((c) => c.edges)
                .some(
                    (e) =>
                        e.kind === "references" && e.target === only.local_id,
                ),
        ).toBe(false);
    });

    // A resolvable external package (one whose own type declarations TypeScript
    // can find, e.g. bundled `types` under node_modules) is not a module inside
    // this project — resolving its default export minted an `external_function`
    // node and a `references` edge for every dynamic npm import that happened
    // to have one.
    it("emits no references edge or external node for a dynamic import of an external package", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false,"module":"esnext","moduleResolution":"bundler"},"include":["src"]}',
            "src/node_modules/some-external-package/package.json":
                '{"name":"some-external-package","types":"index.d.ts"}',
            "src/node_modules/some-external-package/index.d.ts":
                "export default function widget(): void;\n",
            "src/load.ts":
                "export const load = () => import('some-external-package');\n",
        });

        const contributions = [];
        new TypeScriptScanner().scan({ root, files: ["src/load.ts"] }, (c) =>
            contributions.push(c),
        );
        const edges = contributions.flatMap((c) => c.edges);
        const nodes = contributions.flatMap((c) => c.nodes);

        expect(edges.some((e) => e.kind === "references")).toBe(false);
        expect(nodes.some((n) => n.kind === "external_function")).toBe(false);
        // The module-level dynamic import edge to the package is unaffected.
        expect(
            edges.some(
                (e) =>
                    e.kind === "imports" &&
                    e.attributes.dynamic === true &&
                    e.target === "ts:package:some-external-package",
            ),
        ).toBe(true);
    });
});

// A facade republishes imported functions as its own members. Every screen
// reaches them through it, and nothing names them directly, so before this they
// were reachable from nothing but their own tests.
describe("TypeScriptScanner.scan facade re-exports", () => {
    it("reaches a function republished through a property access", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/customerApi.ts": [
                "export function getCustomers(): string { return 'c'; }",
                "export function neverRepublished(): string { return 'n'; }",
                "",
            ].join("\n"),
            "src/apiClient.ts": [
                "import * as customer from './customerApi';",
                "export class ApiClient {",
                "  getCustomers = customer.getCustomers;",
                "}",
                "export const alias = customer.getCustomers;",
                "export const table = { fetch: customer.getCustomers };",
                "",
            ].join("\n"),
        });

        const contributions = [];
        new TypeScriptScanner().scan(
            { root, files: ["src/customerApi.ts", "src/apiClient.ts"] },
            (c) => contributions.push(c),
        );
        const nodes = contributions.flatMap((c) => c.nodes);
        const targets = contributions
            .flatMap((c) => c.edges)
            .filter((e) => e.kind === "references")
            .map((e) => String(e.target));
        const idOf = (name) =>
            nodes.find((n) => n.display_name === name)?.local_id;

        // The class field, the const, and the object-literal value all reach it.
        expect(targets).toContain(idOf("getCustomers"));
        // A sibling the facade does not republish stays unreached, so the
        // clause has not turned every property read into an edge.
        expect(targets).not.toContain(idOf("neverRepublished"));
    });

    it("does not emit a second reference for a function the facade calls", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/dep.ts": "export function run(): number { return 1; }\n",
            "src/caller.ts": [
                "import * as mod from './dep';",
                "export function go(): number { return mod.run(); }",
                "",
            ].join("\n"),
        });

        const contributions = [];
        new TypeScriptScanner().scan(
            { root, files: ["src/dep.ts", "src/caller.ts"] },
            (c) => contributions.push(c),
        );
        const edges = contributions.flatMap((c) => c.edges);
        const run = contributions
            .flatMap((c) => c.nodes)
            .find((n) => n.display_name === "run");

        // `mod.run()` is a call site, and the callee is not an argument, so the
        // access sits in no allowed position. It stays a `calls` edge alone.
        expect(
            edges.some((e) => e.kind === "calls" && e.target === run.local_id),
        ).toBe(true);
        expect(
            edges.some(
                (e) => e.kind === "references" && e.target === run.local_id,
            ),
        ).toBe(false);
    });
});

// `import type` is erased before anything runs, so consumers need to be able to
// tell such an import from a value one. The attribute has to be PRESENT on
// every import for that question to have an answer.
describe("TypeScriptScanner.scan import type marking", () => {
    it("marks each import form with whether it survives compilation", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/dep.ts": [
                "export type Shape = { a: number };",
                "export default function run(): number { return 1; }",
                "export function helper(): number { return 2; }",
                "",
            ].join("\n"),
            "src/whole.ts":
                "import type { Shape } from './dep';\nexport type A = Shape;\n",
            "src/specifier.ts":
                "import { type Shape } from './dep';\nexport type B = Shape;\n",
            "src/mixed.ts":
                "import run, { type Shape } from './dep';\nexport const c: Shape = { a: run() };\n",
            "src/default.ts":
                "import run from './dep';\nexport const d = run();\n",
        });

        const contributions = [];
        new TypeScriptScanner().scan(
            {
                root,
                files: [
                    "src/dep.ts",
                    "src/whole.ts",
                    "src/specifier.ts",
                    "src/mixed.ts",
                    "src/default.ts",
                ],
            },
            (c) => contributions.push(c),
        );
        const imports = Object.fromEntries(
            contributions
                .flatMap((c) => c.edges)
                .filter((e) => e.kind === "imports")
                .map((e) => [e.evidence.path, e.attributes.type_only]),
        );

        expect(imports["src/whole.ts"]).toBe(true);
        expect(imports["src/specifier.ts"]).toBe(true);
        // A default binding beside named type specifiers is a value import: the
        // default survives compilation.
        expect(imports["src/mixed.ts"]).toBe(false);
        // Regression guard: this used to be `undefined`, which is dropped on the
        // way into the graph, so a default-only import carried no marking at all.
        expect(imports["src/default.ts"]).toBe(false);
    });
});

// Import edges are keyed by (kind, source, target): a static import and a
// module-scope dynamic import of the same module collapse into ONE edge.
// Neither the dynamic branch nor a bare `type_only: true` merge is allowed to
// erase the fact that a value import is also present, in EITHER parse order,
// or `dependency_cycles` would silently drop a real runtime dependency.
describe("TypeScriptScanner.scan merges a type-only import with a dynamic import of the same module", () => {
    it.each([
        [
            "type-only import first",
            "src/type-then-dynamic.ts",
            [
                "import type { Foo } from './heavy';",
                "export const load = () => import('./heavy');",
                "export type UseFoo = Foo;",
                "",
            ].join("\n"),
        ],
        [
            "dynamic import first",
            "src/dynamic-then-type.ts",
            [
                "export const load = () => import('./heavy');",
                "import type { Foo } from './heavy';",
                "export type UseFoo = Foo;",
                "",
            ].join("\n"),
        ],
    ])("keeps the merged edge value-reachable (%s)", (_label, path, source) => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false,"module":"esnext","moduleResolution":"bundler"},"include":["src"]}',
            "src/heavy.ts": [
                "export type Foo = { a: number };",
                "export function heavy(): number { return 1; }",
                "",
            ].join("\n"),
            [path]: source,
        });

        const contributions = [];
        new TypeScriptScanner().scan(
            { root, files: ["src/heavy.ts", path] },
            (c) => contributions.push(c),
        );
        const importEdges = contributions
            .flatMap((c) => c.edges)
            .filter((e) => e.kind === "imports" && e.evidence.path === path);

        expect(importEdges).toHaveLength(1);
        expect(importEdges[0].attributes.type_only_variants).toContain(false);
    });
});

// A `.d.ts` describes an implementation rather than being one. Its symbols have
// an in-degree of zero by construction — call sites resolve to the .mjs behind
// it — so they need to be distinguishable from code nothing uses.
describe("TypeScriptScanner.scan declaration files", () => {
    it("marks symbols declared in a .d.mts as belonging to a declaration file", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false,"allowJs":true},"include":["scripts"]}',
            "scripts/color-debt.d.mts":
                "export declare function measureTree(node: string): number;\n",
            "scripts/tokens.ts":
                "export function afstand(): number { return 1; }\n",
        });

        const contributions = [];
        new TypeScriptScanner().scan(
            { root, files: ["scripts/color-debt.d.mts", "scripts/tokens.ts"] },
            (c) => contributions.push(c),
        );
        const nodes = contributions.flatMap((c) => c.nodes);
        const attributesOf = (name) =>
            nodes.find((n) => n.display_name === name)?.attributes ?? {};

        expect(attributesOf("measureTree").declaration_file).toBe(true);
        // Carried from the module node down onto its declarations, and only
        // there: an ordinary source file marks neither.
        expect(attributesOf("color-debt.d.mts").declaration_file).toBe(true);
        expect(attributesOf("afstand").declaration_file).toBeUndefined();
        expect(attributesOf("tokens.ts").declaration_file).toBe(false);
    });
});

describe("TypeScriptScanner.scan value references", () => {
    it("emits a references edge for a function used as a value in a registry array", () => {
        // Regression guard: functions that are never *called* at their use site —
        // dispatch tables, validator registries, callbacks — used to produce no
        // inbound edge at all, so architecture_health reported every one of them
        // as a probable dead-code candidate. Found by scanning a real project
        // whose `TOOL_ARG_VALIDATORS` array is exactly this shape.
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/registry.ts": [
                "function validateA(): string { return 'a'; }",
                "function validateB(): string { return 'b'; }",
                "function unused(): string { return 'u'; }",
                "export const VALIDATORS = [validateA, validateB];",
                "export const BY_NAME = { b: validateB };",
                "",
            ].join("\n"),
        });

        const contributions = [];
        new TypeScriptScanner().scan(
            { root, files: ["src/registry.ts"] },
            (c) => contributions.push(c),
        );
        const nodes = contributions.flatMap((c) => c.nodes);
        const edges = contributions.flatMap((c) => c.edges);

        const idOf = (name) =>
            nodes.find((n) => n.kind === "function" && n.display_name === name)
                ?.local_id;
        const referencesTo = (name) =>
            edges.filter(
                (e) => e.kind === "references" && e.target === idOf(name),
            );

        expect(idOf("validateA")).toBeDefined();
        // Both the array-literal element and the object-literal value count.
        expect(referencesTo("validateA").length).toBeGreaterThan(0);
        expect(referencesTo("validateB").length).toBeGreaterThan(0);
        // A genuinely unreferenced function still gets no inbound reference, so
        // the dead-code signal is not blanket-suppressed.
        expect(referencesTo("unused")).toEqual([]);
    });

    it("does not emit a value reference for a declaration's own name", () => {
        // The identifier in `function f()` is the definition, not a use of it;
        // counting it would make every declared function self-referencing and
        // permanently non-dead.
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/lonely.ts": [
                "function orphan(): number { return 1; }",
                "export const value = 1;",
                "",
            ].join("\n"),
        });

        const contributions = [];
        new TypeScriptScanner().scan({ root, files: ["src/lonely.ts"] }, (c) =>
            contributions.push(c),
        );
        const nodes = contributions.flatMap((c) => c.nodes);
        const edges = contributions.flatMap((c) => c.edges);

        const orphan = nodes.find(
            (n) => n.kind === "function" && n.display_name === "orphan",
        );
        expect(orphan).toBeDefined();
        expect(
            edges.filter(
                (e) => e.kind === "references" && e.target === orphan.local_id,
            ),
        ).toEqual([]);
    });

    it("does not double-count a called function as a value reference", () => {
        // The callee position already produces a `calls` edge; adding a
        // `references` edge for the same identifier would inflate in-degree and
        // distort hub/hotspot ranking.
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/direct.ts": [
                "function helper(): number { return 1; }",
                "export function caller(): number { return helper(); }",
                "",
            ].join("\n"),
        });

        const contributions = [];
        new TypeScriptScanner().scan({ root, files: ["src/direct.ts"] }, (c) =>
            contributions.push(c),
        );
        const nodes = contributions.flatMap((c) => c.nodes);
        const edges = contributions.flatMap((c) => c.edges);

        const helper = nodes.find(
            (n) => n.kind === "function" && n.display_name === "helper",
        );
        expect(
            edges.some(
                (e) => e.kind === "calls" && e.target === helper.local_id,
            ),
        ).toBe(true);
        expect(
            edges.filter(
                (e) => e.kind === "references" && e.target === helper.local_id,
            ),
        ).toEqual([]);
    });
});

// Each test below builds one or more full ts.Program instances in-process
// (~947-964ms measured on a quiet run). Vitest's 5000ms default leaves no
// headroom under the quality gate's parallel load (concurrent docker build +
// PHP suite); 30s gives real headroom without masking an actual hang.
const PROGRAM_BUILD_TIMEOUT_MS = 30000;

describe("TypeScriptScanner.scan program cache", () => {
    it(
        "bounds the retained program cache when a project has many tsconfigs",
        () => {
            // Regression guard for the OOM fix: one full ts.Program per tsconfig,
            // all retained at once, exhausted the worker heap. The cache is LRU-capped.
            const root = fixture({
                "package.json": "{}",
                "a/tsconfig.json": '{"include":["x.ts"]}',
                "a/x.ts": "export const a = 1;\n",
                "b/tsconfig.json": '{"include":["y.ts"]}',
                "b/y.ts": "export const b = 2;\n",
                "c/tsconfig.json": '{"include":["z.ts"]}',
                "c/z.ts": "export const c = 3;\n",
            });

            const scanner = new TypeScriptScanner();
            const emitted = [];
            const summary = scanner.scan(
                { root, files: ["a/x.ts", "b/y.ts", "c/z.ts"] },
                (c) => emitted.push(c),
            );

            expect(summary.files_scanned).toBe(3);
            expect(new Set(emitted.map((c) => c.owner_key)).size).toBe(3);
            // Never retains more than the documented bound, however many configs exist.
            expect(scanner.programCache.size).toBeLessThanOrEqual(2);
        },
        PROGRAM_BUILD_TIMEOUT_MS,
    );

    it(
        "frees a cache slot before building the next program",
        () => {
            // Retaining the cap and *then* evicting put cap + 1 programs in memory
            // at the peak, because the new program is constructed while the cache
            // is still full — the overshoot the cap exists to prevent. A real
            // 111-file project with three tsconfigs died on it: the worker's
            // 512 MB heap held two programs and OOMed building the third, so the
            // scan failed outright while the retained-size assertion above passed.
            const root = fixture({
                "package.json": "{}",
                "a/tsconfig.json": '{"include":["x.ts"]}',
                "a/x.ts": "export const a = 1;\n",
                "b/tsconfig.json": '{"include":["y.ts"]}',
                "b/y.ts": "export const b = 2;\n",
                "c/tsconfig.json": '{"include":["z.ts"]}',
                "c/z.ts": "export const c = 3;\n",
            });

            const scanner = new TypeScriptScanner();
            // The cache is read immediately before each program is built, so its
            // size at that moment is the number of programs already resident.
            const residentAtBuild = [];
            const cache = scanner.programCache;
            const realGet = cache.get.bind(cache);
            cache.get = (key) => {
                residentAtBuild.push(cache.size);
                return realGet(key);
            };

            scanner.scan(
                { root, files: ["a/x.ts", "b/y.ts", "c/z.ts"] },
                () => {},
            );

            expect(residentAtBuild.length).toBeGreaterThanOrEqual(3);
            expect(Math.max(...residentAtBuild)).toBeLessThanOrEqual(1);
        },
        PROGRAM_BUILD_TIMEOUT_MS,
    );
});

describe("TypeScriptScanner.scan backstop", () => {
    it("emits a contribution for a requested file that no program included", () => {
        // validateRequestedFiles stats the file, then createRestrictedProgram stats
        // it again via exceedsByteCap. A file that grows in between passes the first
        // check and is dropped from the program by the second — so it is requested,
        // accepted, and never emitted. The PHP side requires exactly one
        // contribution per requested file, so that gap must not be silent.
        const root = fixture({ "a.ts": "export const a = 1;\n" });
        const real = fs.statSync.bind(fs);
        let stats = 0;
        const spy = vi
            .spyOn(fs, "statSync")
            .mockImplementation((target, options) => {
                const result = real(target, options);
                if (String(target).endsWith("a.ts") && ++stats > 1) {
                    return { ...result, isFile: () => true, size: 10_000_000 };
                }
                return result;
            });
        try {
            const emitted = [];
            new TypeScriptScanner().scan(
                {
                    root,
                    files: ["a.ts"],
                    limits: { max_files: 10, max_file_bytes: 2_000_000 },
                },
                (contribution) => emitted.push(contribution),
            );
            expect(emitted).toHaveLength(1);
            expect(emitted[0].owner_key).toBe("knossos.typescript:file:a.ts");
            expect(emitted[0].diagnostics[0].code).toBe("TS_UNSCANNABLE_FILE");
        } finally {
            spy.mockRestore();
        }
    });

    it("emits exactly one contribution per accepted file", () => {
        // The invariant the PHP side depends on, asserted directly.
        const files = {
            "a.ts": "export const a = 1;\n",
            "b.ts": "export const b = 2;\n",
            "c.js": "module.exports = 3;\n",
        };
        const root = fixture(files);
        const emitted = [];
        const summary = new TypeScriptScanner().scan(
            {
                root,
                files: Object.keys(files),
                limits: { max_files: 10, max_file_bytes: 2_000_000 },
            },
            (contribution) => emitted.push(contribution),
        );
        expect(emitted).toHaveLength(Object.keys(files).length);
        expect(summary.files_scanned).toBe(Object.keys(files).length);
        // The keys, not just the count: emitting one owner_key twice and
        // another not at all keeps both counts correct while breaking the
        // one-contribution-per-file invariant the PHP side relies on.
        expect(
            emitted.map((contribution) => contribution.owner_key).sort(),
        ).toEqual(
            Object.keys(files)
                .map((file) => `knossos.typescript:file:${file}`)
                .sort(),
        );
    });
});

const sha256 = (bytes) => createHash("sha256").update(bytes).digest("hex");

function scanOnce(scanner, root, files) {
    const contributions = [];
    scanner.scan({ root, files }, (contribution) =>
        contributions.push(contribution),
    );
    return Object.fromEntries(contributions.map((c) => [c.owner_key, c]));
}

// Run `callback` with every fs.readFileSync of a path ending in `suffix`
// failing as unreadable, the way a permission change after discovery would.
function withUnreadable(suffix, callback) {
    const readFileSync = fs.readFileSync;
    const spy = vi
        .spyOn(fs, "readFileSync")
        .mockImplementation((file, ...rest) => {
            if (String(file).endsWith(suffix)) {
                throw Object.assign(new Error("EACCES: permission denied"), {
                    code: "EACCES",
                });
            }
            return readFileSync(file, ...rest);
        });
    try {
        return callback();
    } finally {
        spy.mockRestore();
    }
}

describe("content_hash", () => {
    it("hashes raw bytes, so a byte-order mark is part of the hash", () => {
        const bom = Buffer.concat([
            Buffer.from([0xef, 0xbb, 0xbf]),
            Buffer.from("export class Bom {}\n"),
        ]);
        const utf16le = Buffer.concat([
            Buffer.from([0xff, 0xfe]),
            Buffer.from("export class Wide {}\n", "utf16le"),
        ]);
        const utf16beText = Buffer.from("export class Big {}\n", "utf16le");
        utf16beText.swap16();
        const utf16be = Buffer.concat([Buffer.from([0xfe, 0xff]), utf16beText]);
        const root = fixture({ "src/plain.ts": "export class Plain {}\n" });
        writeFileSync(join(root, "src/bom.ts"), bom);
        writeFileSync(join(root, "src/wide.ts"), utf16le);
        writeFileSync(join(root, "src/big.ts"), utf16be);

        const byOwner = scanOnce(new TypeScriptScanner(), root, [
            "src/plain.ts",
            "src/bom.ts",
            "src/wide.ts",
            "src/big.ts",
        ]);

        const names = (file) =>
            byOwner[`knossos.typescript:file:${file}`].nodes.map(
                (n) => n.display_name,
            );
        expect(
            byOwner["knossos.typescript:file:src/plain.ts"].content_hash,
        ).toBe(sha256(Buffer.from("export class Plain {}\n")));
        expect(byOwner["knossos.typescript:file:src/bom.ts"].content_hash).toBe(
            sha256(bom),
        );
        expect(
            byOwner["knossos.typescript:file:src/wide.ts"].content_hash,
        ).toBe(sha256(utf16le));
        expect(byOwner["knossos.typescript:file:src/big.ts"].content_hash).toBe(
            sha256(utf16be),
        );
        // Decoding still matches what the compiler would have read: the class
        // behind the BOM and both UTF-16 files are found under their own names.
        expect(names("src/plain.ts")).toContain("Plain");
        expect(names("src/bom.ts")).toContain("Bom");
        expect(names("src/wide.ts")).toContain("Wide");
        expect(names("src/big.ts")).toContain("Big");
        expect(
            byOwner["knossos.typescript:file:src/bom.ts"].diagnostics,
        ).toEqual([]);
    });

    it("binds each request's hash to the program that parsed it, across cached programs", () => {
        const root = fixture({ "src/a.ts": "export class First {}\n" });
        const scanner = new TypeScriptScanner();

        const first = scanOnce(scanner, root, ["src/a.ts"]);
        writeFileSync(join(root, "src/a.ts"), "export class Second {}\n");
        const second = scanOnce(scanner, root, ["src/a.ts"]);

        const owner = "knossos.typescript:file:src/a.ts";
        expect(first[owner].content_hash).toBe(
            sha256(Buffer.from("export class First {}\n")),
        );
        expect(second[owner].content_hash).toBe(
            sha256(Buffer.from("export class Second {}\n")),
        );
        expect(second[owner].nodes.map((n) => n.display_name)).toContain(
            "Second",
        );
    });

    it("hashes a file that does not parse cleanly", () => {
        const root = fixture({ "src/broken.ts": "export class {\n" });

        const byOwner = scanOnce(new TypeScriptScanner(), root, [
            "src/broken.ts",
        ]);

        expect(
            byOwner["knossos.typescript:file:src/broken.ts"].content_hash,
        ).toBe(sha256(Buffer.from("export class {\n")));
    });

    it("hashes an extensionless shebang script's own bytes", () => {
        const script =
            "#!/usr/bin/env node\nexport function run() {\n    return 1;\n}\n";
        const root = fixture({ "bin/cli": script });

        const byOwner = scanOnce(new TypeScriptScanner(), root, ["bin/cli"]);

        const contribution = byOwner["knossos.typescript:file:bin/cli"];
        expect(contribution.nodes.length).toBeGreaterThan(0);
        expect(contribution.content_hash).toBe(sha256(Buffer.from(script)));
    });

    it("sends no hash for a file that passed validation but could not be read", () => {
        // Validation only stats the file; the read that would hash and parse it
        // fails. No bytes were read, so no hash, while the file beside it has one.
        const root = fixture({
            "src/gone.ts": "export class Gone {}\n",
            "src/kept.ts": "export class Kept {}\n",
        });
        const byOwner = withUnreadable(join("src", "gone.ts"), () =>
            scanOnce(new TypeScriptScanner(), root, [
                "src/gone.ts",
                "src/kept.ts",
            ]),
        );

        const { content_hash: kept } =
            byOwner["knossos.typescript:file:src/kept.ts"];
        const gone = byOwner["knossos.typescript:file:src/gone.ts"];
        expect(gone).not.toHaveProperty("content_hash");
        expect([gone.nodes, gone.diagnostics.length]).toEqual([[], 1]);
        expect(kept).toBe(sha256(Buffer.from("export class Kept {}\n")));
    });
});

// Run one scan and keep its result as well as its contributions.
function scanWithResult(scanner, root, files, extra = {}) {
    const contributions = [];
    const result = scanner.scan({ root, files, ...extra }, (contribution) =>
        contributions.push(contribution),
    );
    return {
        result,
        byOwner: Object.fromEntries(contributions.map((c) => [c.owner_key, c])),
    };
}

// Answer the reads of a path ending in `suffix` from `outcomes`, one per read in
// order: a string is returned as the file's bytes, `null` fails the read. Reads
// beyond the list, and of every other path, reach the disk.
function withReads(suffix, outcomes, callback) {
    const readFileSync = fs.readFileSync;
    const remaining = [...outcomes];
    const spy = vi
        .spyOn(fs, "readFileSync")
        .mockImplementation((file, ...rest) => {
            if (String(file).endsWith(suffix) && remaining.length > 0) {
                const outcome = remaining.shift();
                if (outcome === null) {
                    throw Object.assign(
                        new Error("EACCES: permission denied"),
                        {
                            code: "EACCES",
                        },
                    );
                }
                return Buffer.from(outcome);
            }
            return readFileSync(file, ...rest);
        });
    try {
        return callback();
    } finally {
        spy.mockRestore();
    }
}

// The importer and the file its checker reads to resolve it.
const A = 'import { B } from "./b";\nexport class A extends B {}\n';
const B = "export class B {}\n";

describe("input_hashes: the reads a request reports", () => {
    it("reports a file the checker read to resolve a requested one", () => {
        const root = fixture({ "src/a.ts": A, "src/b.ts": B });

        const { result } = scanWithResult(new TypeScriptScanner(), root, [
            "src/a.ts",
        ]);

        expect(result.input_hashes).toEqual({
            "src/a.ts": sha256(Buffer.from(A)),
            "src/b.ts": sha256(Buffer.from(B)),
        });
    });

    it("carries an empty map when nothing was read", () => {
        const root = fixture({ "src/a.ts": A });

        const { result } = scanWithResult(new TypeScriptScanner(), root, []);

        expect(result.input_hashes).toEqual({});
    });

    it("reports null for a file whose read failed", () => {
        const root = fixture({ "src/a.ts": A, "src/b.ts": B });

        const { result } = withUnreadable(join("src", "b.ts"), () =>
            scanWithResult(new TypeScriptScanner(), root, ["src/a.ts"]),
        );

        expect(result.input_hashes).toEqual({
            "src/a.ts": sha256(Buffer.from(A)),
            "src/b.ts": null,
        });
    });
});

describe("input_hashes: programs reused across requests", () => {
    it("reports this request's read of a file in a program reused from an earlier request", () => {
        const root = fixture({ "src/a.ts": A, "src/b.ts": B });
        const scanner = new TypeScriptScanner();
        const changed = "export class B { changed = 1; }\n";

        scanWithResult(scanner, root, ["src/a.ts"]);
        writeFileSync(join(root, "src/b.ts"), changed);
        const { result } = scanWithResult(scanner, root, ["src/a.ts"]);

        expect(result.programs_reused).toBe(1);
        expect(result.input_hashes["src/b.ts"]).toBe(
            sha256(Buffer.from(changed)),
        );
    });

    it("reads every file again when TypeScript reuses an unchanged program's structure", () => {
        // Pins TypeScript's behaviour rather than the worker's: structure reuse
        // asks the host for every file of the old program, so a reused program
        // holds only SourceFiles this request created from its own reads.
        const root = fixture({ "src/a.ts": A, "src/b.ts": B });
        const scanner = new TypeScriptScanner();
        scanWithResult(scanner, root, ["src/a.ts"]);

        const readFileSync = fs.readFileSync;
        const reads = [];
        const spy = vi
            .spyOn(fs, "readFileSync")
            .mockImplementation((file, ...rest) => {
                reads.push(String(file));
                return readFileSync(file, ...rest);
            });
        let second;
        try {
            second = scanWithResult(scanner, root, ["src/a.ts"]);
        } finally {
            spy.mockRestore();
        }

        expect(second.result.programs_reused).toBe(1);
        expect(reads.filter((file) => file.endsWith("/src/b.ts"))).toHaveLength(
            1,
        );
        expect(second.result.input_hashes["src/b.ts"]).toBe(
            sha256(Buffer.from(B)),
        );
    });
});

describe("input_hashes: the key each read goes under", () => {
    it("leaves out a module resolution only probed for", () => {
        const root = fixture({
            "src/a.ts": 'import { M } from "./missing";\nexport const a = M;\n',
        });

        const { result } = scanWithResult(new TypeScriptScanner(), root, [
            "src/a.ts",
        ]);

        expect(Object.keys(result.input_hashes)).toEqual(["src/a.ts"]);
    });

    it("leaves out files under node_modules", () => {
        const root = fixture({
            "src/a.ts": 'import { dep } from "dep";\nexport const a = dep;\n',
            "node_modules/dep/package.json":
                '{"name":"dep","types":"index.d.ts"}\n',
            "node_modules/dep/index.d.ts":
                "export declare const dep: number;\n",
        });

        const { result } = scanWithResult(new TypeScriptScanner(), root, [
            "src/a.ts",
        ]);

        expect(Object.keys(result.input_hashes)).toEqual(["src/a.ts"]);
    });

    it("keys an extensionless shebang script by its real path", () => {
        const script = "#!/usr/bin/env node\nexport function run() {}\n";
        const root = fixture({ "bin/cli": script });

        const { result } = scanWithResult(new TypeScriptScanner(), root, [
            "bin/cli",
        ]);

        expect(result.input_hashes).toEqual({
            "bin/cli": sha256(Buffer.from(script)),
        });
    });

    it("keys a file read through a symlink by where its bytes live", () => {
        // Discovery skips symlinks, so the linked name is not a path the core
        // tracks; the target is, and it is the file whose bytes were read.
        const root = fixture({
            "tsconfig.json": '{"include":["src"]}\n',
            "src/a.ts": A,
            "lib/b.ts": B,
        });
        symlinkSync(join(root, "lib/b.ts"), join(root, "src/b.ts"));

        const { result } = scanWithResult(new TypeScriptScanner(), root, [
            "src/a.ts",
        ]);

        expect(result.input_hashes).toEqual({
            "src/a.ts": sha256(Buffer.from(A)),
            "lib/b.ts": sha256(Buffer.from(B)),
        });
    });
});

// allowedCompilerPath resolves a path first; the read resolves it again.
// `second` answers the read's own resolution of src/b.ts, standing in for a
// link retargeted or removed in between. Picked by caller name: the checks
// before it resolve the same path a varying number of times.
function withSecondResolution(second, callback) {
    const realpathSync = fs.realpathSync;
    const readFileSync = fs.readFileSync;
    const readsMade = [];
    const resolve = vi
        .spyOn(fs, "realpathSync")
        .mockImplementation((file, ...rest) => {
            if (
                String(file).endsWith("/src/b.ts") &&
                new Error().stack.includes("readHashedSourceFile")
            ) {
                return second(realpathSync(file, ...rest));
            }
            return realpathSync(file, ...rest);
        });
    const read = vi
        .spyOn(fs, "readFileSync")
        .mockImplementation((file, ...rest) => {
            readsMade.push(String(file));
            return readFileSync(file, ...rest);
        });
    try {
        return { ...callback(), readsMade };
    } finally {
        resolve.mockRestore();
        read.mockRestore();
    }
}

describe("input_hashes: a path that changes under the read", () => {
    it("reports null, without reading, for a file whose target has left the root since it was allowed", () => {
        // The importer's facts are computed as if b.ts did not exist, so its
        // own path must fail verification should discovery have hashed it.
        const root = fixture({ "src/a.ts": A, "src/b.ts": B });
        const outside = join(fs.realpathSync(fixture({ "b.ts": B })), "b.ts");

        const { result, readsMade } = withSecondResolution(
            () => outside,
            () => scanWithResult(new TypeScriptScanner(), root, ["src/a.ts"]),
        );

        expect(readsMade).not.toContain(outside);
        expect(result.input_hashes).toEqual({
            "src/a.ts": sha256(Buffer.from(A)),
            "src/b.ts": null,
        });
    });

    it("reports null for an in-root path the host refuses because it now links out of the root", () => {
        // Module resolution saw b.ts inside the root; by the time the program
        // asks for it, allowedCompilerPath resolves it outside, so the host
        // refuses it and a.ts's facts are computed without it.
        const root = fixture({ "src/a.ts": A, "src/b.ts": B });
        const outside = join(fs.realpathSync(fixture({ "b.ts": B })), "b.ts");
        const realpathSync = fs.realpathSync;
        const spy = vi
            .spyOn(fs, "realpathSync")
            .mockImplementation((file, ...rest) => {
                const frames = new Error().stack.split("\n");
                const checker = frames.findIndex((frame) =>
                    frame.includes("allowedCompilerPath"),
                );
                if (
                    String(file).endsWith("/src/b.ts") &&
                    checker !== -1 &&
                    frames[checker + 1].includes("getSourceFile")
                ) {
                    return outside;
                }
                return realpathSync(file, ...rest);
            });
        let result;
        try {
            ({ result } = scanWithResult(new TypeScriptScanner(), root, [
                "src/a.ts",
            ]));
        } finally {
            spy.mockRestore();
        }

        expect(result.input_hashes).toEqual({
            "src/a.ts": sha256(Buffer.from(A)),
            "src/b.ts": null,
        });
    });

    it("reports null for an imported file the host refuses as over the byte cap", () => {
        const big = `export class B {}\n${"// padding\n".repeat(40)}`;
        const root = fixture({ "src/a.ts": A, "src/b.ts": big });

        const { result } = scanWithResult(
            new TypeScriptScanner(),
            root,
            ["src/a.ts"],
            { limits: { max_file_bytes: 200 } },
        );

        expect(result.input_hashes).toEqual({
            "src/a.ts": sha256(Buffer.from(A)),
            "src/b.ts": null,
        });
    });

    it("reports null for a file whose path no longer resolves", () => {
        const root = fixture({ "src/a.ts": A, "src/b.ts": B });

        const { result } = withSecondResolution(
            () => {
                throw Object.assign(new Error("ENOENT: no such file"), {
                    code: "ENOENT",
                });
            },
            () => scanWithResult(new TypeScriptScanner(), root, ["src/a.ts"]),
        );

        expect(result.input_hashes).toEqual({
            "src/a.ts": sha256(Buffer.from(A)),
            "src/b.ts": null,
        });
    });
});

describe("input_hashes: a path read by more than one program in a request", () => {
    // Each tsconfig builds its own program and each reads src/b.ts.
    const files = {
        "tsconfig.one.json": '{"files":["src/a.ts"]}\n',
        "tsconfig.two.json": '{"files":["src/c.ts"]}\n',
        "src/a.ts": A,
        "src/c.ts": 'import { B } from "./b";\nexport class C extends B {}\n',
        "src/b.ts": B,
    };
    const other = "export class B { other = 2; }\n";
    const scan = (outcomes) => {
        const root = fixture(files);
        return withReads(join("src", "b.ts"), outcomes, () =>
            scanWithResult(
                new TypeScriptScanner(),
                root,
                ["src/a.ts", "src/c.ts"],
                { config_files: ["tsconfig.one.json", "tsconfig.two.json"] },
            ),
        ).result;
    };

    it("keeps a hash both reads agree on", () => {
        const result = scan([B, B]);
        expect(result.programs).toBe(2);
        expect(result.input_hashes["src/b.ts"]).toBe(sha256(Buffer.from(B)));
    });

    it("reports null when the second read hashes differently", () => {
        const result = scan([B, other]);
        expect(result.programs).toBe(2);
        expect(result.input_hashes["src/b.ts"]).toBeNull();
    });

    it("reports null when a successful read is followed by a failed one", () => {
        expect(scan([B, null]).input_hashes["src/b.ts"]).toBeNull();
    });

    it("reports null when a failed read is followed by a successful one", () => {
        expect(scan([null, B]).input_hashes["src/b.ts"]).toBeNull();
    });
});

describe("content_hash for a duplicate package copy", () => {
    // Two in-root copies of dep@1.0.0, each linked from its own node_modules.
    // TypeScript loads the first and makes the second a redirect to it, so the
    // second path's facts come from the first copy's bytes.
    function duplicatePackages(secondCopy, around = (scan) => scan()) {
        const pkg = '{"name":"dep","version":"1.0.0","types":"index.ts"}\n';
        const importer = (name) =>
            `import { Dep } from "dep";\nexport class ${name} extends Dep {}\n`;
        const root = fixture({
            "copies/one/package.json": pkg,
            "copies/one/index.ts": "export class Dep {}\n",
            "copies/two/package.json": pkg,
            "copies/two/index.ts": secondCopy,
            "app1/main.ts": importer("One"),
            "app2/main.ts": importer("Two"),
        });
        for (const [app, copy] of [
            ["app1", "one"],
            ["app2", "two"],
        ]) {
            mkdirSync(join(root, app, "node_modules"));
            symlinkSync(
                join(root, "copies", copy),
                join(root, app, "node_modules/dep"),
            );
        }
        const { result, byOwner } = around(() =>
            scanWithResult(new TypeScriptScanner(), root, [
                "app1/main.ts",
                "app2/main.ts",
                "copies/two/index.ts",
            ]),
        );
        return Object.assign(
            byOwner["knossos.typescript:file:copies/two/index.ts"],
            { input_hashes: result.input_hashes },
        );
    }

    it("hashes the duplicate's own bytes when both copies are identical", () => {
        const contribution = duplicatePackages("export class Dep {}\n");

        expect(contribution.content_hash).toBe(
            sha256(Buffer.from("export class Dep {}\n")),
        );
        expect(contribution.nodes.length).toBeGreaterThan(0);
    });

    it("emits no facts for a duplicate whose bytes differ from the copy it redirects to", () => {
        const contribution = duplicatePackages(
            "export class Dep { differs = 1; }\n",
        );

        expect(contribution).not.toHaveProperty("content_hash");
        expect([contribution.nodes, contribution.edges]).toEqual([[], []]);
        expect(contribution.diagnostics.map((d) => d.code)).toEqual([
            "TS_REDIRECTED_SOURCE_UNVERIFIED",
        ]);
        expect(contribution.diagnostics[0].evidence.path).toBe(
            "copies/two/index.ts",
        );
    });

    it("emits no facts for a duplicate whose own read failed", () => {
        const contribution = duplicatePackages(
            "export class Dep {}\n",
            (scan) => withUnreadable(join("two", "index.ts"), scan),
        );

        expect(contribution).not.toHaveProperty("content_hash");
        expect([contribution.nodes, contribution.edges]).toEqual([[], []]);
        expect(contribution.diagnostics.map((d) => d.code)).toEqual([
            "TS_REDIRECTED_SOURCE_UNVERIFIED",
        ]);
        expect(contribution.input_hashes["copies/two/index.ts"]).toBeNull();
    });
});

// src/a.ts reaches src/real.ts through the linked file name src/alias.ts,
// and real/c.ts through the linked directory src/linkdir. Discovery never
// follows a link, so a refusal only fails verification when it is keyed by
// the real in-root path the bytes would have come from.
const IMPORTER =
    'import { R } from "./alias";\nimport { C } from "./linkdir/c";\nexport class A extends R {}\nexport const c = C;\n';
const REAL = "export class R {}\n";
const C = "export const C = 1;\n";

function linkedLayout(real = REAL, c = C) {
    const root = fixture({
        "src/a.ts": IMPORTER,
        "src/real.ts": real,
        "real/c.ts": c,
    });
    symlinkSync(join(root, "src/real.ts"), join(root, "src/alias.ts"));
    symlinkSync(join(root, "real"), join(root, "src/linkdir"));
    return root;
}

const refusedOnRealKeys = {
    "src/a.ts": sha256(Buffer.from(IMPORTER)),
    "src/real.ts": null,
    "real/c.ts": null,
};

// Run a scan on a linked layout, turning each real file into what `swap`
// makes of it the first time `calledFrom` resolves the linked name.
function scanSwapping(calledFrom, swap) {
    const root = linkedLayout();
    const outside = fs.realpathSync(fixture({ "x.ts": REAL }));
    const targets = {
        "/src/alias.ts": join(root, "src/real.ts"),
        "/src/linkdir/c.ts": join(root, "real/c.ts"),
    };
    const swapped = new Set();
    const realpathSync = fs.realpathSync;
    const spy = vi
        .spyOn(fs, "realpathSync")
        .mockImplementation((file, ...rest) => {
            const name = Object.keys(targets).find((suffix) =>
                String(file).endsWith(suffix),
            );
            if (
                name !== undefined &&
                !swapped.has(name) &&
                calledFrom(new Error().stack.split("\n"))
            ) {
                swapped.add(name);
                swap(targets[name], join(outside, "x.ts"));
            }
            return realpathSync(file, ...rest);
        });
    try {
        return scanWithResult(new TypeScriptScanner(), root, ["src/a.ts"])
            .result;
    } finally {
        spy.mockRestore();
    }
}

const escape = (target, outside) => {
    fs.unlinkSync(target);
    symlinkSync(outside, target);
};
const remove = (target) => fs.unlinkSync(target);
const fromGetSourceFileCheck = (frames) => {
    const checker = frames.findIndex((frame) =>
        frame.includes("allowedCompilerPath"),
    );
    return checker !== -1 && frames[checker + 1].includes("getSourceFile");
};
const fromRead = (frames) =>
    frames.some((frame) => frame.includes("readHashedSourceFile"));

describe("input_hashes: a refused path reached through a symlink", () => {
    it("keys a file refused over the byte cap by its real path", () => {
        const padding = "// padding\n".repeat(40);
        const root = linkedLayout(REAL + padding, C + padding);

        const { result } = scanWithResult(
            new TypeScriptScanner(),
            root,
            ["src/a.ts"],
            { limits: { max_file_bytes: 300 } },
        );

        expect(result.input_hashes).toEqual(refusedOnRealKeys);
    });

    it("keys a file refused for now linking out of the root by the in-root link", () => {
        const result = scanSwapping(fromGetSourceFileCheck, escape);

        expect(result.input_hashes).toEqual(refusedOnRealKeys);
    });

    it("keys a file whose target left the root before the read by the in-root link", () => {
        const result = scanSwapping(fromRead, escape);

        expect(result.input_hashes).toEqual(refusedOnRealKeys);
    });

    it("keys a file removed before the read by where it was", () => {
        const result = scanSwapping(fromRead, remove);

        expect(result.input_hashes).toEqual(refusedOnRealKeys);
    });
});

describe("input_hashes: a refused path under a directory swapped for a link", () => {
    it("keys a file under a directory swapped for a link out of the root by where it was", () => {
        // Discovery saw src/sub/c.ts; the directory becomes a link out of the
        // root before the read, so no component past src resolves inside it.
        const importer = 'import { C } from "./sub/c";\nexport const c = C;\n';
        const root = fixture({ "src/a.ts": importer, "src/sub/c.ts": C });
        const outside = fs.realpathSync(fixture({ "c.ts": C }));
        const realpathSync = fs.realpathSync;
        let swapped = false;
        const spy = vi
            .spyOn(fs, "realpathSync")
            .mockImplementation((file, ...rest) => {
                if (
                    !swapped &&
                    String(file).endsWith("/src/sub/c.ts") &&
                    fromRead(new Error().stack.split("\n"))
                ) {
                    swapped = true;
                    rmSync(join(root, "src/sub"), { recursive: true });
                    symlinkSync(outside, join(root, "src/sub"));
                }
                return realpathSync(file, ...rest);
            });
        let result;
        try {
            ({ result } = scanWithResult(new TypeScriptScanner(), root, [
                "src/a.ts",
            ]));
        } finally {
            spy.mockRestore();
        }

        expect(result.input_hashes).toEqual({
            "src/a.ts": sha256(Buffer.from(importer)),
            "src/sub/c.ts": null,
        });
    });
});
