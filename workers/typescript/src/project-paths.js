/**
 * Paths inside the project: validation, the project layout and the walk.
 *
 * Every path the worker reads is checked here first: that it stays inside the
 * root, that the project layout does not exclude it, and which real source a
 * compiler-facing alias (a shebang script, a component, a case-folded name)
 * stands for. `walkPath` follows a path one link at a time so each hop is
 * checked against the same rules.
 */

import fs from "node:fs";
import path from "node:path";
import ts from "typescript";
import { componentAliasSuffix, componentDialect } from "./component-source.js";
import { rethrowStackOverflow } from "./errors.js";
import { excludedFromDiscovery } from "./exclusions.js";

export const SOURCE_EXTENSIONS = new Set([
    ".ts",
    ".tsx",
    ".mts",
    ".cts",
    ".js",
    ".jsx",
    ".mjs",
    ".cjs",
]);

// TypeScript silently drops a root file whose name carries no recognised
// extension, so an extensionless shebang script is offered to the program under
// a synthetic name ending in `.js` and mapped back to its real path on the way
// out. The suffix is deliberately unusual: a real file that collided with it
// would be reported under the wrong path.
export const SHEBANG_ALIAS_SUFFIX = ".knossos-shebang.js";

// TypeScript recognises a source extension only in lower case, so a file such as
// `FOO.TS`, which discovery classifies as TypeScript, would be in no program and
// lose its facts on every scan. It is offered under its name plus this mark and
// the lower-cased extension, and mapped back the same way.
const CASE_ALIAS_MARK = ".knossos-alias";

// A component (`.vue`, `.svelte`, `.astro`) is offered to the compiler as
// `X.vue.ts` (`X.astro.tsx`), so module resolution finds it through relative
// paths, `paths` and `baseUrl` without a resolver of its own. When a real
// `X.vue.ts` exists it wins, and the component is offered under this mark.
export const COMPONENT_ALIAS_MARK = ".knossos-component";

export const COMPONENT_ALIAS =
    /\.(vue|svelte|astro)(\.knossos-component)?(\.tsx?)$/i;

// Dependency trees may be read for module resolution even though discovery
// does not scan them as project-owned source. Generated and tool-owned trees
// remain blocked at this boundary.
const RESOLUTION_ALLOWED_EXCLUDED = new Set(["node_modules", "vendor"]);

// Linux's MAXSYMLINKS: one lookup follows at most this many links, and the
// next one fails it with ELOOP.
const MAX_SYMLINK_HOPS = 40;

/**
 * Resolve an absolute path the way the kernel's path lookup does, one
 * component at a time, so the result names the file a read of it opens.
 *
 * `current` is always a real directory. A `..` steps to its parent, which is
 * where the kernel's `..` goes once the links before it have been followed. A
 * symlink's target is put in front of the components still to walk, raw, so
 * its own `..` is applied the same way; nothing is ever collapsed as text.
 *
 * - "file": the walk reached a non-directory as its last component.
 * - "directory": the walk ended at a directory.
 * - "missing": a component does not exist, or is a non-directory with more to
 *   walk (ENOENT, ENOTDIR), and `location` is the path the read was to open
 *   (see absentBelow).
 * - "unresolvable": no such path can be named: a `..` left to apply below a
 *   missing component, another lookup error, or more than MAX_SYMLINK_HOPS
 *   links (ELOOP).
 *
 * `links` lists every symlink followed, in order, with the components that
 * were still to walk after it at that moment.
 *
 * @param {string} absolute
 * @returns {{kind: "file"|"missing"|"directory"|"unresolvable", location?: string, links: {location: string, rest: string[]}[]}}
 */
export function walkPath(absolute) {
    const top = normalize(path.parse(absolute).root);
    const remaining = normalize(absolute).slice(top.length).split("/");
    const links = [];
    let current = top;
    let hops = 0;
    while (remaining.length > 0) {
        const name = remaining.shift();
        if (name === "" || name === ".") continue;
        if (name === "..") {
            current = parentDirectory(current, top);
            continue;
        }
        const candidate = current.endsWith("/")
            ? `${current}${name}`
            : `${current}/${name}`;
        let stat;
        try {
            stat = fs.lstatSync(candidate);
        } catch (error) {
            rethrowStackOverflow(error);
            return error?.code === "ENOENT"
                ? absentBelow(candidate, remaining, links)
                : { kind: "unresolvable", links };
        }
        if (stat.isSymbolicLink()) {
            if (++hops > MAX_SYMLINK_HOPS)
                return { kind: "unresolvable", links };
            links.push({ location: candidate, rest: [...remaining] });
            let target;
            try {
                target = normalize(fs.readlinkSync(candidate));
            } catch (error) {
                rethrowStackOverflow(error);
                return { kind: "unresolvable", links };
            }
            if (path.isAbsolute(target)) {
                current = normalize(path.parse(target).root);
                target = target.slice(current.length);
            }
            remaining.unshift(...target.split("/"));
        } else if (stat.isDirectory()) {
            current = candidate;
        } else if (remaining.length > 0) {
            return absentBelow(candidate, remaining, links);
        } else {
            return { kind: "file", location: candidate, links };
        }
    }
    return { kind: "directory", location: current, links };
}

/**
 * The walk's result when the lookup fails at `candidate` because it does not
 * exist, or is not a directory while more remains to walk. Nothing exists below
 * it, so nothing below it can be a link, and without a `..` still to apply the
 * remaining components name exactly the file the read was to open: a "missing"
 * location. A `..` still to apply could only be resolved against a directory
 * that is not there, so the path is unresolvable.
 */
function absentBelow(candidate, remaining, links) {
    const rest = remaining.filter((name) => name !== "" && name !== ".");
    if (rest.includes("..")) return { kind: "unresolvable", links };
    return { kind: "missing", location: [candidate, ...rest].join("/"), links };
}

/**
 * The kernel's resolution of an existing path, for the root and requested files,
 * which must exist. Node's JavaScript fs.realpathSync collapses `..` in a link
 * target as text first; the native one does not.
 */
export function realpathNative(candidate) {
    return normalize(fs.realpathSync.native(candidate));
}

/** The parent of a real directory; a filesystem root is its own parent. */
function parentDirectory(directory, top) {
    if (directory === top) return top;
    const parent = directory.slice(0, directory.lastIndexOf("/"));
    return parent.length < top.length ? top : parent;
}

export function defaultLibDirectory() {
    return normalize(path.dirname(ts.getDefaultLibFilePath({})));
}

export function validateRoot(input) {
    if (typeof input !== "string" || input.length === 0)
        throw new Error("A project root is required.");
    const root = realpathNative(input);
    if (!fs.statSync(root).isDirectory())
        throw new Error("Project root is not a directory.");
    return root;
}

// Kept separate from reading the file: a path of the wrong shape cannot be
// attributed to any file, so it fails the request, while a well-formed path that
// simply cannot be scanned costs only that file.
export function assertScannablePath(relative) {
    if (
        typeof relative !== "string" ||
        relative.length === 0 ||
        path.isAbsolute(relative) ||
        relative.includes("\0")
    ) {
        throw new Error("Project-relative path is invalid.");
    }
    const segments = normalize(relative).split("/");
    if (
        segments.some(
            (segment) => segment === "" || segment === "." || segment === "..",
        )
    )
        throw new Error("Project-relative path is invalid.");
}

/**
 * The well-formed project-relative paths of a request's `source_files`: every
 * file of the language the core discovered, so the fallback program and the
 * unattributed reads can tell a file the core holds a contribution for from
 * one it never saw.
 */
export function sourceFilesFrom(input) {
    if (!Array.isArray(input)) return [];
    return input.filter((relative) => {
        try {
            assertScannablePath(relative);
        } catch {
            return false;
        }
        return true;
    });
}

export function validatedInside(root, relative) {
    assertScannablePath(relative);
    const real = realpathNative(path.join(root, relative));
    if (!contains(root, real))
        throw new Error("Project-relative path escapes the root.");
    return real;
}

// Whether the compiler may touch a path: the default library, or a path inside
// the root that the kernel's lookup (walkPath) keeps inside it. A path that does
// not resolve at all is let through, so its read fails and is recorded by
// readHashedSourceFile under the same walk's key.
export function allowedCompilerPath(root, candidate) {
    const normalized = realSourcePath(normalize(path.resolve(candidate)));
    if (contains(defaultLibDirectory(), normalized)) return true;
    if (!contains(root, normalized)) return false;
    const relative = normalize(path.relative(root, normalized));
    if (relative !== "" && excludedByProjectLayout(relative)) return false;
    const walked = walkPath(normalized);
    return walked.location === undefined || contains(root, walked.location);
}

/** Whether an in-root absolute path lies where the project's exclusions refuse it. */
export function excludedByProjectLayoutPath(root, absolute) {
    if (!contains(root, absolute)) return false;
    const relative = normalize(path.relative(root, absolute));
    return relative !== "" && excludedByProjectLayout(relative);
}

/**
 * Whether the exclusions discovery applies refuse a project-relative path.
 *
 * The exclusions name directories a project builds into or vendors under, and
 * whatever the project's own ignores add, so they describe the project's
 * layout. Inside a dependency tree they describe
 * nothing: a package ships its declarations wherever its own package.json
 * points, and `dist` is the most common answer of all. Applying the project's
 * rules there refused `node_modules/@eslint/core/dist/cjs/types.d.cts`, the one
 * file that package's types live in, which cost twice over: the refusal is
 * recorded as a failed read, and a failed read of a file that reads perfectly
 * well is what UndiscoveredInputVerifier fails the whole scan on at commit;
 * and until it does, a symbol the refused declaration names resolves to
 * `unknown`, so `class A extends Dep` is recorded as extending nothing anyone
 * can name.
 *
 * So the check stops at the first resolution-allowed segment: above it the
 * project's layout governs, below it the dependency's does.
 */
export function excludedByProjectLayout(relative) {
    const segments = relative.split("/");
    const dependencyRoot = segments.findIndex((segment) =>
        RESOLUTION_ALLOWED_EXCLUDED.has(segment),
    );
    const governed =
        dependencyRoot === -1 ? segments : segments.slice(0, dependencyRoot);
    return governed.length > 0 && excludedFromDiscovery(governed.join("/"));
}

/**
 * Whether the core scans a project-relative path as one of this worker's own
 * sources, so that file's contribution reports what it read. A dependency's
 * file, one the project's layout excludes, and a file of another kind (JSON)
 * report nothing of their own. A program holds an extensionless file only as a
 * requested shebang script.
 */
export function isProjectSource(relative) {
    if (excludedFromDiscovery(relative)) return false;
    const extension = path.extname(relative);
    return (
        extension === "" ||
        SOURCE_EXTENSIONS.has(extension.toLowerCase()) ||
        componentDialect(relative) !== null
    );
}

/**
 * Whether a project-relative path lies below a node_modules directory, at the
 * top level of the project or nested. A relative path has no leading slash, so
 * "/node_modules/" alone would miss the project's own node_modules.
 */
export function belowNodeModules(relative) {
    return (
        relative === "node_modules" ||
        relative.startsWith("node_modules/") ||
        relative.includes("/node_modules/")
    );
}

export function relativeInside(root, candidate) {
    const normalized = realSourcePath(normalize(path.resolve(candidate)));
    if (!contains(root, normalized)) return null;
    return normalize(path.relative(root, normalized));
}

// The single chokepoint every relative path passes through, so un-aliasing here
// keeps canonical names, evidence paths, and the emitted owner keys pointed at
// the file that actually exists on disk.
export function realSourcePath(candidate) {
    if (candidate.endsWith(SHEBANG_ALIAS_SUFFIX))
        return candidate.slice(0, -SHEBANG_ALIAS_SUFFIX.length);
    const component = COMPONENT_ALIAS.exec(candidate);
    if (component !== null) {
        const original = candidate.slice(
            0,
            component.index + 1 + component[1].length,
        );
        if (
            component[3].toLowerCase() ===
                componentAliasSuffix(componentDialect(original)) &&
            (component[2] !== undefined || !isRegularFile(candidate)) &&
            isRegularFile(original)
        )
            return original;
    }
    const caseAlias = /\.knossos-alias(\.[a-z]+)$/.exec(candidate);
    if (caseAlias !== null && SOURCE_EXTENSIONS.has(caseAlias[1])) {
        const original = candidate.slice(0, caseAlias.index);
        const originalExtension = path.extname(original);
        // offeredPath only ever appends this mark to a name whose own
        // extension is a supported one spelled with some upper case, the
        // exact case it lower-cases below the mark. A real file that happens
        // to be named e.g. `x.knossos-alias.ts` already has a lower-case
        // extension, so stripping the mark here would rename it to `x` and
        // lose its facts under the wrong key; only strip when undoing that
        // exact case fold would restore it.
        if (
            originalExtension !== "" &&
            originalExtension !== originalExtension.toLowerCase() &&
            originalExtension.toLowerCase() === caseAlias[1]
        )
            return original;
    }
    return candidate;
}

// The name a requested file is offered to the program under: an extensionless
// script as a `.js` alias, a file whose extension is not in lower case under its
// lower-cased extension, and anything else as itself.
export function offeredPath(absolute) {
    const dialect = componentDialect(absolute);
    if (dialect !== null) {
        const suffix = componentAliasSuffix(dialect);
        return isRegularFile(`${absolute}${suffix}`)
            ? `${absolute}${COMPONENT_ALIAS_MARK}${suffix}`
            : `${absolute}${suffix}`;
    }
    const extension = path.extname(absolute);
    if (extension === "") return `${absolute}${SHEBANG_ALIAS_SUFFIX}`;
    if (extension !== extension.toLowerCase())
        return `${absolute}${CASE_ALIAS_MARK}${extension.toLowerCase()}`;
    return absolute;
}

/** A component's alias (see offeredPath); any other file as itself. */
export function offeredComponentPath(file) {
    return componentDialect(file) === null ? file : offeredPath(file);
}

/** Whether a path is a regular file, following links as the compiler does. */
export function isRegularFile(candidate) {
    return fs.statSync(candidate, { throwIfNoEntry: false })?.isFile() ?? false;
}

export function contains(root, candidate) {
    const base = normalize(root).replace(/\/$/, "");
    const value = normalize(candidate);
    return value === base || value.startsWith(`${base}/`);
}

export function normalize(value) {
    return value.replaceAll("\\", "/");
}

export function walk(root, directory, onFile) {
    let entries;
    try {
        entries = fs.readdirSync(directory, { withFileTypes: true });
    } catch {
        // An unreadable directory (EACCES/EPERM) must not fail the whole
        // tsconfig walk; skip it and continue, matching Python's os.walk.
        return;
    }
    for (const entry of entries) {
        const absolute = path.join(directory, entry.name);
        const relative = normalize(path.relative(root, absolute));
        if (excludedFromDiscovery(relative)) continue;
        if (entry.isSymbolicLink()) continue;
        if (entry.isDirectory()) walk(root, absolute, onFile);
        else if (entry.isFile()) onFile(absolute, relative);
    }
}
