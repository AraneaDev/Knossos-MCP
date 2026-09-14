import { afterEach, describe, expect, it, vi } from "vitest";
import fs from "node:fs";
import { createHash } from "node:crypto";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

// A discovered file that is absent, or has become a link, while a request
// resolves or reads it, and is restored before the scan re-checks the tree. The
// graph must not come out fresh: some key must name the discovered path with a
// value other than discovery's hash (a different hash, or null).

const created = [];

function fixture(files = {}) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-swap-")),
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

const sha256 = (text) => createHash("sha256").update(text).digest("hex");

function scan(root, files, extra = {}) {
    const contributions = [];
    const result = new TypeScriptScanner().scan(
        { root, files, ...extra },
        (contribution) => contributions.push(contribution),
    );
    return { result, contributions };
}

// Change the tree for the duration of one scan, then restore it.
function during(change, restore, run) {
    change();
    try {
        return run();
    } finally {
        restore();
    }
}

/**
 * What discovery would hash: every regular file below the root reached without
 * following a link, skipping node_modules, as ProjectDiscoverer walks.
 */
function discovered(root) {
    const files = {};
    const visit = (directory) => {
        for (const entry of fs.readdirSync(directory, {
            withFileTypes: true,
        })) {
            if (entry.name === "node_modules" || entry.isSymbolicLink())
                continue;
            const absolute = join(directory, entry.name);
            if (entry.isDirectory()) visit(absolute);
            else if (entry.isFile())
                files[absolute.slice(root.length + 1)] = sha256(
                    fs.readFileSync(absolute),
                );
        }
    };
    visit(root);
    return files;
}

// The entries a core check would fail on: a discovered path whose value is not
// discovery's hash.
function disagreements(inputHashes, discovery) {
    return Object.entries(inputHashes)
        .filter(([key, value]) => key in discovery && value !== discovery[key])
        .map(([key]) => key);
}

const A = 'import { B } from "./b";\nexport class A extends B {}\n';
const B = "export class B {}\n";
const C = "export class B { other = 1; }\n";

describe("input_hashes: a discovered file absent while a request resolves it", () => {
    it("reports null for an imported file deleted for the request", () => {
        // Reproduction: a.ts lost its edge (TS2307) and the map named only a.ts.
        const root = fixture({ "src/a.ts": A, "src/b.ts": B });
        const discovery = discovered(root);
        const b = join(root, "src/b.ts");

        const { result } = during(
            () => fs.unlinkSync(b),
            () => fs.writeFileSync(b, B),
            () => scan(root, ["src/a.ts"]),
        );

        expect(result.input_hashes["src/b.ts"]).toBeNull();
        expect(disagreements(result.input_hashes, discovery)).toEqual([
            "src/b.ts",
        ]);
    });
});

describe("input_hashes: a discovered file swapped for a link", () => {
    it("keys an imported file swapped for a link to another discovered file", () => {
        // Reproduction: the read went to c.ts and was keyed only there, where
        // it matched discovery.
        const root = fixture({ "src/a.ts": A, "src/b.ts": B, "src/c.ts": C });
        const discovery = discovered(root);
        const b = join(root, "src/b.ts");

        const { result } = during(
            () => {
                fs.unlinkSync(b);
                fs.symlinkSync("c.ts", b);
            },
            () => {
                fs.unlinkSync(b);
                fs.writeFileSync(b, B);
            },
            () => scan(root, ["src/a.ts"]),
        );

        expect(result.input_hashes["src/c.ts"]).toBe(sha256(C));
        expect(disagreements(result.input_hashes, discovery)).toEqual([
            "src/b.ts",
        ]);
    });

    it("keys a referenced file swapped for a link, which the host reads without a probe", () => {
        // `/// <reference path>` hands the host the name as written, with no
        // resolution probe first, so only the read's own walk can key b.ts.
        const referrer =
            '/// <reference path="./b.ts" />\nexport const a = 1;\n';
        const root = fixture({
            "src/a.ts": referrer,
            "src/b.ts": B,
            "src/c.ts": C,
        });
        const discovery = discovered(root);
        const b = join(root, "src/b.ts");

        const { result } = during(
            () => {
                fs.unlinkSync(b);
                fs.symlinkSync("c.ts", b);
            },
            () => {
                fs.unlinkSync(b);
                fs.writeFileSync(b, B);
            },
            () => scan(root, ["src/a.ts"]),
        );

        expect(result.input_hashes["src/b.ts"]).toBe(sha256(C));
        expect(disagreements(result.input_hashes, discovery)).toEqual([
            "src/b.ts",
        ]);
    });
});

describe("input_hashes: a discovered directory or package file swapped for a link", () => {
    it("keys a file under a directory swapped for a link to another discovered directory", () => {
        const root = fixture({
            "src/a.ts": 'import { B } from "./sub/b";\nexport const a = B;\n',
            "src/sub/b.ts": B,
            "other/b.ts": C,
        });
        const discovery = discovered(root);
        const sub = join(root, "src/sub");

        const { result } = during(
            () => {
                fs.rmSync(sub, { recursive: true });
                fs.symlinkSync("../other", sub);
            },
            () => {
                fs.unlinkSync(sub);
                fs.mkdirSync(sub);
                fs.writeFileSync(join(sub, "b.ts"), B);
            },
            () => scan(root, ["src/a.ts"]),
        );

        expect(result.input_hashes["other/b.ts"]).toBe(sha256(C));
        expect(disagreements(result.input_hashes, discovery)).toEqual([
            "src/sub/b.ts",
        ]);
    });

    it("keys a workspace package file swapped for a link, though resolution realpaths the package", () => {
        // Reproduction: node_modules/lib links to packages/lib, and resolution
        // hands the host only the real path, so the host loaded
        // packages/lib/other.ts and the map never named index.ts.
        const root = fixture({
            "src/a.ts": 'import { lib } from "lib";\nexport const a = lib;\n',
            "packages/lib/package.json": '{"name":"lib","types":"index.ts"}\n',
            "packages/lib/index.ts": "export const lib = 1;\n",
            "packages/lib/other.ts": "export const lib = 2;\n",
        });
        fs.mkdirSync(join(root, "node_modules"));
        fs.symlinkSync("../packages/lib", join(root, "node_modules/lib"));
        const discovery = discovered(root);
        const index = join(root, "packages/lib/index.ts");

        const stable = scan(root, ["src/a.ts"]);
        const swapped = during(
            () => {
                fs.unlinkSync(index);
                fs.symlinkSync("other.ts", index);
            },
            () => {
                fs.unlinkSync(index);
                fs.writeFileSync(index, "export const lib = 1;\n");
            },
            () => scan(root, ["src/a.ts"]),
        );

        expect(disagreements(stable.result.input_hashes, discovery)).toEqual(
            [],
        );
        expect(stable.result.input_hashes["packages/lib/index.ts"]).toBe(
            sha256("export const lib = 1;\n"),
        );
        expect(disagreements(swapped.result.input_hashes, discovery)).toEqual([
            "packages/lib/index.ts",
        ]);
    });
});

describe("input_hashes: a package file that changes between resolution's probe and its realpath", () => {
    it("reports null where the realpath and the walk after it disagree", () => {
        // fileExists sees packages/lib/index.ts as a real file. Before resolution
        // realpaths it, index.ts becomes a link to other.ts, and it is restored
        // as soon as the realpath returns: the host then loads other.ts, which
        // matches discovery, and nothing else would name index.ts.
        const root = fixture({
            "src/a.ts": 'import { lib } from "lib";\nexport const a = lib;\n',
            "packages/lib/package.json": '{"name":"lib","types":"index.ts"}\n',
            "packages/lib/index.ts": "export const lib = 1;\n",
            "packages/lib/other.ts": "export const lib = 2;\n",
        });
        fs.mkdirSync(join(root, "node_modules"));
        fs.symlinkSync("../packages/lib", join(root, "node_modules/lib"));
        const discovery = discovered(root);
        const index = join(root, "packages/lib/index.ts");
        const native = fs.realpathSync.native;
        let swapped = false;
        const spy = vi
            .spyOn(fs.realpathSync, "native")
            .mockImplementation((file, ...rest) => {
                if (
                    swapped ||
                    !String(file).endsWith("node_modules/lib/index.ts")
                )
                    return native(file, ...rest);
                swapped = true;
                fs.unlinkSync(index);
                fs.symlinkSync("other.ts", index);
                try {
                    return native(file, ...rest);
                } finally {
                    fs.unlinkSync(index);
                    fs.writeFileSync(index, "export const lib = 1;\n");
                }
            });
        let result;
        try {
            ({ result } = scan(root, ["src/a.ts"]));
        } finally {
            spy.mockRestore();
        }

        expect(swapped).toBe(true);
        expect(result.input_hashes["packages/lib/other.ts"]).toBe(
            sha256("export const lib = 2;\n"),
        );
        expect(disagreements(result.input_hashes, discovery)).toEqual([
            "packages/lib/index.ts",
        ]);
    });
});

describe("input_hashes: a stable tree", () => {
    it("never disagrees with discovery through probes, links, or extension candidates", () => {
        const root = fixture({
            "tsconfig.json": '{"include":["src","lib"]}\n',
            "src/a.ts": [
                'import { R } from "./alias";',
                'import { C } from "./linkdir/c";',
                'import { lib } from "lib";',
                'import { M } from "./missing";',
                'import { U } from "./up";',
                'import { D } from "./lnk2";',
                "export const a = [R, C, lib, M, U, D];",
                "",
            ].join("\n"),
            "src/real.ts": "export const R = 1;\n",
            "real/c.ts": "export const C = 1;\n",
            "deep/up.ts": "export const U = 1;\n",
            // src/lnk2.ts -> d/../c2.ts opens deep/c2.ts; collapsing the `..`
            // as text would name the discovered decoy src/c2.ts.
            "deep/c2.ts": "export const D = 1;\n",
            "src/c2.ts": "export const D = 2;\n",
            "deep/dir/.keep": "",
            "lib/index.ts": "export const L = 1;\n",
            "packages/lib/package.json": '{"name":"lib","types":"index.ts"}\n',
            "packages/lib/index.ts": "export const lib = 1;\n",
        });
        fs.symlinkSync("real.ts", join(root, "src/alias.ts"));
        fs.symlinkSync("../real", join(root, "src/linkdir"));
        fs.symlinkSync("../deep/dir", join(root, "src/d"));
        fs.symlinkSync("d/../up.ts", join(root, "src/up.ts"));
        fs.symlinkSync("d/../c2.ts", join(root, "src/lnk2.ts"));
        fs.symlinkSync(join(root, "lib"), join(root, "src/abs"));
        fs.mkdirSync(join(root, "node_modules"));
        fs.symlinkSync("../packages/lib", join(root, "node_modules/lib"));
        const discovery = discovered(root);

        const { result } = scan(
            root,
            Object.keys(discovery).filter((file) => file.endsWith(".ts")),
            {
                config_files: ["tsconfig.json"],
            },
        );

        expect(disagreements(result.input_hashes, discovery)).toEqual([]);
        expect(Object.values(result.input_hashes)).toContain(null);
    });
});
