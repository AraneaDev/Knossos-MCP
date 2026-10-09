import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner, excludedBy } from "../scanner.js";

const created = [];

function fixture(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-exclusions-")),
    );
    created.push(root);
    for (const [relative, contents] of Object.entries(files)) {
        const absolute = join(root, relative);
        fs.mkdirSync(dirname(absolute), { recursive: true });
        fs.writeFileSync(absolute, contents);
    }
    return root;
}

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

// The rules the core sends, as IgnoreMatcher::workerRules() exports them.
const CORE_RULES = {
    segments: [
        ".git",
        ".idea",
        ".knossos",
        "vendor",
        "node_modules",
        ".next",
        ".nuxt",
        ".venv",
        "venv",
        "__pycache__",
        ".tox",
        ".mypy_cache",
        ".pytest_cache",
        ".worktrees",
        ".stryker-tmp",
        ".pnpm-store",
        ".yarn",
    ],
    anchored_segments: ["build", "coverage", "dist", "site"],
    anchor_roots: [""],
    prefixes: [".knossos-", "_ide_helper"],
    sequences: [
        [".vitepress", "cache"],
        [".vitepress", "dist"],
    ],
    suffixes: [".min.js", ".min.mjs", ".min.cjs"],
    path_prefixes: [
        "public/build",
        "storage/framework",
        "storage/attachments",
        "storage/debugbar",
        "storage/logs",
    ],
    patterns: [],
};

// The shared case list; the source of truth is
// IgnoreMatcherTest::workerRuleCases in the core, with the answers its
// IgnoreMatcher gives. Each entry: patterns as workerRules() compiles them,
// the path, the anchor roots, and whether discovery leaves the path out.
const SHARED_PATTERNS = [
    { regex: "legacy", anchored: true, negated: false },
    { regex: "[^/]*\\.gen\\.ts", anchored: false, negated: false },
    { regex: "keep\\.gen\\.ts", anchored: false, negated: true },
    { regex: "docs/[a-zA-Z][^/]*\\.md", anchored: true, negated: false },
    { regex: "tmp[^/]", anchored: false, negated: false },
    { regex: "a/(?:.*/)?b", anchored: true, negated: false },
    { regex: "rooted", anchored: true, negated: false },
];
const NEGATED_DIST = [
    { regex: "packages/a/dist", anchored: true, negated: true },
];
const ROOTS = ["", "packages/a"];
const SHARED_CASES = [
    [SHARED_PATTERNS, "src/a.ts", ROOTS, false],
    [SHARED_PATTERNS, "venv/lib.js", ROOTS, true],
    [SHARED_PATTERNS, "pkg/venv", ROOTS, true],
    [SHARED_PATTERNS, "coverage/x.py", ROOTS, true],
    [SHARED_PATTERNS, ".knossos-ci/x.ts", ROOTS, true],
    [SHARED_PATTERNS, "public/build/app.js", ROOTS, true],
    [SHARED_PATTERNS, "public/buildings/app.js", ROOTS, false],
    [SHARED_PATTERNS, "lib/x.min.js", ROOTS, true],
    [SHARED_PATTERNS, "site/.vitepress/cache/x.js", ROOTS, true],
    [SHARED_PATTERNS, ".vitepress/cache", ROOTS, true],
    [SHARED_PATTERNS, "legacy", ROOTS, true],
    [SHARED_PATTERNS, "legacy/old.ts", ROOTS, true],
    [SHARED_PATTERNS, "src/legacy/old.ts", ROOTS, false],
    [SHARED_PATTERNS, "src/x.gen.ts", ROOTS, true],
    [SHARED_PATTERNS, "src/keep.gen.ts", ROOTS, false],
    [SHARED_PATTERNS, "docs/readme.md", ROOTS, true],
    [SHARED_PATTERNS, "docs/1.md", ROOTS, false],
    [SHARED_PATTERNS, "tmp1", ROOTS, true],
    [SHARED_PATTERNS, "src/tmp2/x.ts", ROOTS, true],
    [SHARED_PATTERNS, "tmp12", ROOTS, false],
    [SHARED_PATTERNS, "a/b", ROOTS, true],
    [SHARED_PATTERNS, "a/x/y/b", ROOTS, true],
    [SHARED_PATTERNS, "rooted", ROOTS, true],
    [SHARED_PATTERNS, "src/rooted", ROOTS, false],
    [SHARED_PATTERNS, "_ide_helper.php", ROOTS, true],
    [SHARED_PATTERNS, "dist/a.js", ROOTS, true],
    [SHARED_PATTERNS, "src/dist/a.js", ROOTS, false],
    [SHARED_PATTERNS, "packages/a/dist/a.js", ROOTS, true],
    [SHARED_PATTERNS, "packages/b/dist/a.js", ROOTS, false],
    [SHARED_PATTERNS, "packages/a/src/build/x.ts", ROOTS, false],
    [SHARED_PATTERNS, "packages/a/coverage", ROOTS, true],
    [SHARED_PATTERNS, "apps/site/c.ts", ROOTS, false],
    [NEGATED_DIST, "dist/a.js", ROOTS, true],
    [NEGATED_DIST, "packages/a/dist/a.js", ROOTS, false],
    [NEGATED_DIST, "packages/a/build/a.js", ROOTS, true],
    [[], "packages/a/dist/a.js", [""], false],
];

const IMPORTER = [
    'import { fromVenv } from "../venv/lib";',
    'import { minified } from "../vendor-lib.min";',
    'import { built } from "../public/build/app";',
    'import { legacy } from "../legacy/old";',
    "export const all = [fromVenv, minified, built, legacy];",
    "",
].join("\n");

const LAYOUT = {
    "src/a.ts": IMPORTER,
    "venv/lib.js": "export const fromVenv = 1;\n",
    "vendor-lib.min.js": "export const minified = 1;\n",
    "public/build/app.js": "export const built = 1;\n",
    "legacy/old.ts": "export const legacy = 1;\n",
};

function scan(root, exclusions) {
    const contributions = [];
    const result = new TypeScriptScanner().scan(
        {
            root,
            files: ["src/a.ts"],
            ...(exclusions === undefined ? {} : { exclusions }),
        },
        (contribution) => contributions.push(contribution),
    );
    return { result, contribution: contributions[0] };
}

const facts = (contribution) =>
    JSON.stringify([contribution.nodes, contribution.edges]);

describe("exclusions from the request", () => {
    it("reads nothing discovery leaves out, and draws no fact from it", () => {
        const root = fixture(LAYOUT);
        const rules = {
            ...CORE_RULES,
            patterns: [{ regex: "legacy", anchored: true, negated: false }],
        };

        const { result, contribution } = scan(root, rules);

        const read = Object.keys(result.input_hashes);
        // Below an excluded directory nothing is read or probed; beside the
        // excluded bundle, other names an import could mean still are.
        for (const excluded of ["venv/", "public/build/", "legacy/"]) {
            expect(read.filter((key) => key.startsWith(excluded))).toEqual([]);
        }
        expect(read).not.toContain("vendor-lib.min.js");
        for (const excluded of [
            "venv/lib.js",
            "vendor-lib.min.js",
            "public/build/app.js",
            "legacy/old.ts",
        ]) {
            expect(Object.keys(contribution.reads)).not.toContain(excluded);
            expect(facts(contribution)).not.toContain(excluded);
        }
    });

    it("re-includes a file a later negated pattern takes back", () => {
        const root = fixture({
            "src/a.ts":
                'import { x } from "./x.gen";\nimport { keep } from "./keep.gen";\nexport const both = [x, keep];\n',
            "src/x.gen.ts": "export const x = 1;\n",
            "src/keep.gen.ts": "export const keep = 1;\n",
        });
        const rules = {
            ...CORE_RULES,
            patterns: [
                { regex: "[^/]*\\.gen\\.ts", anchored: false, negated: false },
                { regex: "keep\\.gen\\.ts", anchored: false, negated: true },
            ],
        };

        const { result } = scan(root, rules);

        expect(Object.keys(result.input_hashes)).toContain("src/keep.gen.ts");
        expect(Object.keys(result.input_hashes)).not.toContain("src/x.gen.ts");
    });

    it("keeps a directory a pattern names out along with everything below it", () => {
        const root = fixture(LAYOUT);
        const rules = {
            ...CORE_RULES,
            patterns: [
                { regex: "legacy", anchored: false, negated: false },
                { regex: "old\\.ts", anchored: false, negated: true },
            ],
        };

        const { result } = scan(root, rules);

        // Discovery never enters legacy/, so the re-include cannot reach it.
        expect(Object.keys(result.input_hashes)).not.toContain("legacy/old.ts");
    });

    it("falls back to its own list when the request carries none", () => {
        const root = fixture({
            ...LAYOUT,
            "src/b.ts":
                'import { out } from "../dist/out";\nexport const b = out;\n',
            "dist/out.js": "export const out = 1;\n",
        });
        const contributions = [];

        const result = new TypeScriptScanner().scan(
            { root, files: ["src/b.ts"] },
            (contribution) => contributions.push(contribution),
        );

        expect(Object.keys(result.input_hashes)).not.toContain("dist/out.js");
    });

    it("refuses exclusions that are not the rules object", () => {
        const root = fixture(LAYOUT);

        expect(() => scan(root, { segments: "venv" })).toThrow(/exclusions/);
    });
});

describe("the shared case list", () => {
    it.each(SHARED_CASES)(
        "%#: %s answers as the core's IgnoreMatcher does",
        (patterns, relative, roots, excluded) => {
            expect(
                excludedBy(
                    { ...CORE_RULES, anchor_roots: roots, patterns },
                    relative,
                ),
            ).toBe(excluded);
        },
    );

    it("refuses anchored fields that are not lists of strings", () => {
        expect(() =>
            excludedBy({ ...CORE_RULES, anchor_roots: [1] }, "a.ts"),
        ).toThrow(/exclusions/);
        expect(() =>
            excludedBy({ ...CORE_RULES, anchored_segments: "dist" }, "a.ts"),
        ).toThrow(/exclusions/);
    });
});

describe("build output", () => {
    it("reads a source directory named build below a directory with no manifest", () => {
        const root = fixture({
            "src/a.ts":
                'import { x } from "./build/x";\nimport { d } from "../packages/a/dist/d";\nexport const all = [x, d];\n',
            "src/build/x.ts": "export const x = 1;\n",
            "packages/a/package.json": "{}\n",
            "packages/a/dist/d.ts": "export const d = 1;\n",
        });

        const { result } = scan(root, {
            ...CORE_RULES,
            anchor_roots: ["", "packages/a"],
        });

        const read = Object.keys(result.input_hashes);
        expect(read).toContain("src/build/x.ts");
        expect(
            read.filter((key) => key.startsWith("packages/a/dist/")),
        ).toEqual([]);
    });

    it("anchors its fallback list at the project root only", () => {
        const root = fixture({
            "src/b.ts":
                'import { x } from "./build/x";\nimport { out } from "../dist/out";\nexport const b = [x, out];\n',
            "src/build/x.ts": "export const x = 1;\n",
            "dist/out.ts": "export const out = 1;\n",
        });
        const contributions = [];

        const result = new TypeScriptScanner().scan(
            { root, files: ["src/b.ts"] },
            (contribution) => contributions.push(contribution),
        );

        const read = Object.keys(result.input_hashes);
        expect(read).toContain("src/build/x.ts");
        expect(read).not.toContain("dist/out.ts");
    });
});
