#!/usr/bin/env node
// Lints the repository's Markdown with the markdownlint core library.
//
// This stands in for markdownlint-cli2, whose glob chain (globby, fast-glob,
// micromatch, braces) carries a high-severity advisory with no patched
// release. Enumeration uses node:fs/promises, so no glob dependency is
// needed. The rules and the ignore list come from .markdownlint-cli2.jsonc,
// the file the previous linter read, so the two stay comparable.
//
// Files git ignores are skipped as well (the config's `gitignore: true`):
// local agent scratch directories never reach CI, so linting them would fail
// a developer's run over files that are not part of the repository.
import { execFileSync, spawnSync } from "node:child_process";
import { glob, readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";
import { lint } from "markdownlint/promise";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const configFile = path.join(root, ".markdownlint-cli2.jsonc");

// Directories never worth descending into, whatever their depth.
const pruned = new Set(["node_modules", "vendor", ".git"]);
// The patterns the old `lint:md` script negated on its command line.
const excludedPatterns = [
    "vendor/**",
    "node_modules/**",
    "workers/**/vendor/**",
    "workers/**/node_modules/**",
];

/**
 * Parse JSONC: drop comments and trailing commas outside of strings.
 *
 * @param {string} text
 * @returns {unknown}
 */
export function parseJsonc(text) {
    let out = "";
    let i = 0;
    while (i < text.length) {
        const c = text[i];
        if (c === '"') {
            let j = i + 1;
            while (j < text.length && text[j] !== '"')
                j += text[j] === "\\" ? 2 : 1;
            out += text.slice(i, j + 1);
            i = j + 1;
        } else if (c === "/" && text[i + 1] === "/") {
            while (i < text.length && text[i] !== "\n") i++;
        } else if (c === "/" && text[i + 1] === "*") {
            const end = text.indexOf("*/", i + 2);
            i = end === -1 ? text.length : end + 2;
        } else {
            out += c;
            i++;
        }
    }
    return JSON.parse(out.replace(/,(\s*[}\]])/g, "$1"));
}

/**
 * Whether git is usable here. The quality image carries no .git, and the
 * previous linter then fell back to reading .gitignore files itself.
 *
 * @returns {boolean}
 */
function insideGitCheckout() {
    try {
        execFileSync(
            "git",
            ["-C", root, "rev-parse", "--is-inside-work-tree"],
            { stdio: "ignore" },
        );
        return true;
    } catch {
        return false;
    }
}

/**
 * Drop the files git ignores, asking git itself.
 *
 * @param {string[]} files
 * @returns {string[]}
 */
function withoutGitIgnored(files) {
    if (files.length === 0 || !insideGitCheckout()) return files;
    const result = spawnSync(
        "git",
        ["-C", root, "check-ignore", "--stdin", "-z"],
        {
            input: files.join("\0"),
            encoding: "utf8",
        },
    );
    // Exit 1 means nothing was ignored; anything above that is a real error.
    if (result.status !== 0 && result.status !== 1) {
        throw new Error(`git check-ignore failed: ${result.stderr}`);
    }
    const ignored = new Set(result.stdout.split("\0").filter(Boolean));
    return files.filter((file) => !ignored.has(file));
}

/**
 * Whether a root-relative path matches one of the ignore patterns. The
 * patterns in use are exact paths and `**` wildcards, so a path match is a
 * regular expression where `**` spans directories and nothing else is magic.
 *
 * @param {string} file
 * @param {string[]} patterns
 * @returns {boolean}
 */
function matchesAny(file, patterns) {
    return patterns.some((pattern) => {
        const body = pattern
            .split("**")
            .map((part) => part.replace(/[.+^${}()|[\]\\?*]/g, "\\$&"))
            .join(".*");
        return new RegExp(`^${body}$`).test(file);
    });
}

/** Lint every Markdown file and report. */
async function main() {
    const config = parseJsonc(await readFile(configFile, "utf8"));
    const ignores = [...excludedPatterns, ...(config.ignores ?? [])];
    const found = [];
    for await (const entry of glob("**/*.md", {
        cwd: root,
        exclude: (dirent) => pruned.has(dirent.name),
    })) {
        const file = entry.split(path.sep).join("/");
        if (!matchesAny(file, ignores)) found.push(file);
    }
    const files = (config.gitignore ? withoutGitIgnored(found) : found).sort();

    const results = await lint({
        files: files.map((f) => path.join(root, f)),
        config: config.config,
    });
    let errors = 0;
    for (const [file, issues] of Object.entries(results)) {
        for (const issue of [...issues].sort(
            (x, y) => x.lineNumber - y.lineNumber,
        )) {
            errors++;
            const detail = issue.errorDetail ? ` [${issue.errorDetail}]` : "";
            console.log(
                `${path.relative(root, file)}:${issue.lineNumber} ${issue.ruleNames.join("/")} ${issue.ruleDescription}${detail}`,
            );
        }
    }
    console.log(`Linting: ${files.length} files`);
    console.log(`Summary: ${errors} issues`);
    process.exit(errors === 0 ? 0 : 1);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) await main();
