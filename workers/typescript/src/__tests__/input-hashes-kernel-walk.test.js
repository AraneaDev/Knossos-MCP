import { describe, it, expect, afterEach, vi } from "vitest";
import fs, {
    mkdtempSync,
    mkdirSync,
    writeFileSync,
    symlinkSync,
} from "node:fs";
import { createHash } from "node:crypto";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { TypeScriptScanner } from "../scanner.js";

// Every layout here is reached through `/// <reference path>`, which hands the
// host the linked name as written: TypeScript neither probes it with
// fileExists nor resolves it to a real path first, so the host's own walk of
// the name is what decides the key.

const created = [];

function fixture(files = {}) {
    const root = fs.realpathSync(
        mkdtempSync(join(tmpdir(), "knossos-ts-walk-")),
    );
    created.push(root);
    for (const [relative, contents] of Object.entries(files)) {
        const absolute = join(root, relative);
        mkdirSync(dirname(absolute), { recursive: true });
        writeFileSync(absolute, contents);
    }
    return root;
}

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

const sha256 = (text) => createHash("sha256").update(text).digest("hex");

const referrer = (name) =>
    `/// <reference path="./${name}" />\nexport const a = 1;\n`;
const REFERRER = referrer("lnk.ts");
const C = "export const c = 1;\n";
const DECOY = "export const c = 2;\n";
const PADDING = "// padding\n".repeat(40);

// Scan src/a.ts. `onRead`, when given, runs once, the first time the host is
// about to resolve a read of src/lnk.ts.
function scan(root, { onRead, maxFileBytes } = {}) {
    let pending = onRead;
    const scanner = new TypeScriptScanner({
        observeHostPath: (stage, absolute) => {
            if (stage !== "read" || !pending) return;
            if (!absolute.endsWith("/src/lnk.ts")) return;
            const change = pending;
            pending = undefined;
            change();
        },
    });
    const limits =
        maxFileBytes === undefined ? {} : { max_file_bytes: maxFileBytes };
    return scanner.scan({ root, files: ["src/a.ts"], limits }, () => {})
        .input_hashes;
}

describe("input_hashes: a link chain that leaves the root and comes back", () => {
    // src/lnk.ts -> OUT/u/f.ts -> ROOT/deep/c.ts. The kernel follows both
    // links, so a read of src/lnk.ts opens deep/c.ts, a discovered file.
    function outAndBack(contents = C) {
        const root = fixture({
            "src/a.ts": REFERRER,
            "src/c.ts": DECOY,
            "deep/c.ts": contents,
        });
        const outside = fixture();
        mkdirSync(join(outside, "u"));
        symlinkSync(join(root, "deep/c.ts"), join(outside, "u/f.ts"));
        symlinkSync(join(outside, "u/f.ts"), join(root, "src/lnk.ts"));
        return root;
    }

    it("keys a successful read by the in-root file the chain ends at", () => {
        expect(scan(outAndBack())).toEqual({
            "src/a.ts": sha256(REFERRER),
            "deep/c.ts": sha256(C),
        });
    });

    it("keys a read of a target removed before the read by that target", () => {
        const root = outAndBack();

        const hashes = scan(root, {
            onRead: () => fs.unlinkSync(join(root, "deep/c.ts")),
        });

        expect(hashes).toEqual({
            "src/a.ts": sha256(REFERRER),
            "deep/c.ts": null,
        });
    });

    it("keys a stable over-cap target by that target", () => {
        const hashes = scan(outAndBack(C + PADDING), { maxFileBytes: 300 });

        expect(hashes).toEqual({
            "src/a.ts": sha256(REFERRER),
            "deep/c.ts": null,
        });
    });
});

describe("input_hashes: `..` the kernel cannot apply", () => {
    // Each link target reaches `..` through a component that does not resolve,
    // so the kernel fails the read with ENOENT or ELOOP, while a textual
    // collapse names the discovered, unchanged src/c.ts.

    it("keys `..` after a missing component by the link, not the textual collapse", () => {
        const root = fixture({ "src/a.ts": REFERRER, "src/c.ts": DECOY });
        symlinkSync("nope/../c.ts", join(root, "src/lnk.ts"));

        expect(scan(root)).toEqual({
            "src/a.ts": sha256(REFERRER),
            "src/lnk.ts": null,
        });
    });

    it("keys `..` after a directory link to itself by that link", () => {
        const root = fixture({ "src/a.ts": REFERRER, "src/c.ts": DECOY });
        symlinkSync("ld", join(root, "src/ld"));
        symlinkSync("ld/../c.ts", join(root, "src/lnk.ts"));

        expect(scan(root)).toEqual({
            "src/a.ts": sha256(REFERRER),
            "src/ld": null,
        });
    });

    it("keys `..` through a dangling directory link by that link", () => {
        const root = fixture({ "src/a.ts": REFERRER, "src/c.ts": DECOY });
        symlinkSync("../gone/dir", join(root, "src/d"));
        symlinkSync("d/../c.ts", join(root, "src/lnk.ts"));

        expect(scan(root)).toEqual({
            "src/a.ts": sha256(REFERRER),
            "src/d": null,
        });
    });

    it("keys a read through that directory link, once it resolves, by the file opened", () => {
        const root = fixture({
            "src/a.ts": REFERRER,
            "src/c.ts": DECOY,
            "gone/c.ts": C,
            "gone/dir/.keep": "",
        });
        symlinkSync("../gone/dir", join(root, "src/d"));
        symlinkSync("d/../c.ts", join(root, "src/lnk.ts"));

        expect(scan(root)).toEqual({
            "src/a.ts": sha256(REFERRER),
            "gone/c.ts": sha256(C),
        });
    });
});

describe("input_hashes: a directory changed under the read", () => {
    // Discovery hashed src/sub/c.ts. The directory goes, or becomes a file,
    // before the read; the kernel fails the read at src/sub, yet the path it
    // was to open is fully known, and it is the discovered one.
    const importer = 'import { c } from "./sub/c";\nexport const a = c;\n';

    function underDirectory(change) {
        const root = fixture({ "src/a.ts": importer, "src/sub/c.ts": C });
        let pending = true;
        const scanner = new TypeScriptScanner({
            observeHostPath: (stage, absolute) => {
                if (stage !== "read" || !pending) return;
                if (!absolute.endsWith("/src/sub/c.ts")) return;
                pending = false;
                change(root);
            },
        });
        return scanner.scan({ root, files: ["src/a.ts"] }, () => {})
            .input_hashes;
    }

    it("keys a file under a directory removed before the read where it was", () => {
        const hashes = underDirectory((root) =>
            fs.rmSync(join(root, "src/sub"), { recursive: true }),
        );

        expect(hashes).toEqual({
            "src/a.ts": sha256(importer),
            "src/sub/c.ts": null,
        });
    });

    it("keys a file under a directory replaced by a file where it was", () => {
        const hashes = underDirectory((root) => {
            fs.rmSync(join(root, "src/sub"), { recursive: true });
            writeFileSync(join(root, "src/sub"), C);
        });

        expect(hashes).toEqual({
            "src/a.ts": sha256(importer),
            "src/sub/c.ts": null,
        });
    });
});

describe("input_hashes: an absolute link target inside the root", () => {
    function absoluteLink(contents = C) {
        const root = fixture({
            "src/a.ts": REFERRER,
            "src/c.ts": DECOY,
            "lib/b.ts": contents,
        });
        symlinkSync(join(root, "lib/b.ts"), join(root, "src/lnk.ts"));
        return root;
    }

    it("keys a successful read by the target", () => {
        expect(scan(absoluteLink())).toEqual({
            "src/a.ts": sha256(REFERRER),
            "lib/b.ts": sha256(C),
        });
    });

    it("keys a read of a target removed before the read by the target", () => {
        const root = absoluteLink();

        const hashes = scan(root, {
            onRead: () => fs.unlinkSync(join(root, "lib/b.ts")),
        });

        expect(hashes).toEqual({
            "src/a.ts": sha256(REFERRER),
            "lib/b.ts": null,
        });
    });

    it("keys a stable over-cap target by the target", () => {
        const hashes = scan(absoluteLink(C + PADDING), { maxFileBytes: 300 });

        expect(hashes).toEqual({
            "src/a.ts": sha256(REFERRER),
            "lib/b.ts": null,
        });
    });
});

describe("input_hashes: the kernel's symlink hop limit", () => {
    // src/l<n>.ts -> l<n+1>.ts ... -> real.ts. Linux follows at most 40 links in
    // one lookup; the 41st fails it with ELOOP.
    function chain(links) {
        const root = fixture({
            "src/a.ts": referrer("l0.ts"),
            "src/real.ts": C,
        });
        for (let index = 0; index < links; ++index) {
            const target = index === links - 1 ? "real.ts" : `l${index + 1}.ts`;
            symlinkSync(target, join(root, `src/l${index}.ts`));
        }
        return root;
    }

    it("follows a chain of 40 links to its file", () => {
        expect(scan(chain(40))).toEqual({
            "src/a.ts": sha256(referrer("l0.ts")),
            "src/real.ts": sha256(C),
        });
    });

    it("stops a chain of 41 links and keys it by the last link followed", () => {
        expect(scan(chain(41))).toEqual({
            "src/a.ts": sha256(referrer("l0.ts")),
            "src/l39.ts": null,
        });
    });
});

describe("the compiler host's containment", () => {
    it("reads nothing outside the root through a linked package directory", () => {
        // node_modules/dep links out of the root. Module resolution asks the
        // host for the package's manifest and sources; none may be read.
        const root = fixture({
            "src/a.ts": 'import { dep } from "dep";\nexport const a = dep;\n',
        });
        const outside = fixture({
            "dep/package.json": '{"name":"dep","types":"index.ts"}\n',
            "dep/index.ts": "export const dep = 1;\n",
        });
        mkdirSync(join(root, "node_modules"));
        symlinkSync(join(outside, "dep"), join(root, "node_modules/dep"));
        const readFileSync = fs.readFileSync;
        const readsMade = [];
        const spy = vi
            .spyOn(fs, "readFileSync")
            .mockImplementation((file, ...rest) => {
                readsMade.push(String(file));
                return readFileSync(file, ...rest);
            });
        let hashes;
        try {
            hashes = scan(root);
        } finally {
            spy.mockRestore();
        }

        // Read through the link's name, the manifest's bytes would still come
        // from outside the root.
        const throughLink = (file) =>
            file.startsWith(outside) || file.includes("/node_modules/dep/");
        expect(readsMade.filter(throughLink)).toEqual([]);
        expect(hashes).toEqual({
            "src/a.ts": sha256(
                'import { dep } from "dep";\nexport const a = dep;\n',
            ),
        });
    });
});

describe("input_hashes: a file replaced by a directory", () => {
    // A read of a directory fails (EISDIR). Discovery never reports a
    // directory, so keying where it stands is safe on a stable tree, and a
    // discovered file swapped for one mid-scan fails verification.
    const direct = '/// <reference path="./c.ts" />\nexport const a = 1;\n';

    function swapToDirectory(root, file) {
        fs.unlinkSync(file);
        mkdirSync(file);
        writeFileSync(join(file, "keep.txt"), "");
        return root;
    }

    for (const stage of ["load", "read"]) {
        it(`keys a file replaced by a directory at ${stage} where it was`, () => {
            const root = fixture({ "src/a.ts": direct, "src/c.ts": C });
            let pending = true;
            const scanner = new TypeScriptScanner({
                observeHostPath: (at, absolute) => {
                    if (at !== stage || !pending) return;
                    if (!absolute.endsWith("/src/c.ts")) return;
                    pending = false;
                    swapToDirectory(root, join(root, "src/c.ts"));
                },
            });

            const hashes = scanner.scan(
                { root, files: ["src/a.ts"] },
                () => {},
            ).input_hashes;

            expect(hashes).toEqual({
                "src/a.ts": sha256(direct),
                "src/c.ts": null,
            });
        });
    }

    it("keys a link target replaced by a directory by that target", () => {
        const root = fixture({ "src/a.ts": REFERRER, "deep/c.ts": C });
        symlinkSync(join(root, "deep/c.ts"), join(root, "src/lnk.ts"));

        const hashes = scan(root, {
            onRead: () => swapToDirectory(root, join(root, "deep/c.ts")),
        });

        expect(hashes).toEqual({
            "src/a.ts": sha256(REFERRER),
            "deep/c.ts": null,
        });
    });
});

describe("input_hashes: an absolute link target with a doubled leading slash", () => {
    it("keys a read through `//ROOT/...` by the file it opens", () => {
        const root = fixture({ "src/a.ts": REFERRER, "q/c.ts": C });
        symlinkSync(`/${join(root, "q/c.ts")}`, join(root, "src/lnk.ts"));

        expect(scan(root)).toEqual({
            "src/a.ts": sha256(REFERRER),
            "q/c.ts": sha256(C),
        });
    });
});
