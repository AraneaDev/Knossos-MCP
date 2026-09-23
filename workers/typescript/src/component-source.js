import ts from "typescript";

/**
 * Position-preserving virtual TypeScript for single-file components.
 *
 * The compiler cannot read a `.vue`, `.svelte` or `.astro` file, so the
 * worker offers it this text instead: the same length, with every line break
 * at the same offset, the script content byte for byte, template expressions
 * and component tags written back where they stand, and everything else
 * blank. Every position the checker reports is therefore a position in the
 * real component, and the facts need no mapping back.
 */

const DIALECTS = new Map([
    [".vue", "vue"],
    [".svelte", "svelte"],
    [".astro", "astro"],
]);

/** Why a component could not be turned into virtual source. */
export class ComponentParseError extends Error {}

/** The component dialect a file name's extension names, or null. */
export function componentDialect(fileName) {
    const match = /\.[^./]+$/.exec(fileName);
    return match === null
        ? null
        : (DIALECTS.get(match[0].toLowerCase()) ?? null);
}

/** The extension a component is offered to the compiler under. */
export function componentAliasSuffix(dialect) {
    return dialect === "astro" ? ".tsx" : ".ts";
}

/** Every UTF-16 code unit but a line break replaced by a space. */
export function blankSource(text) {
    return text.replace(/[^\n\r]/g, " ");
}

/**
 * The virtual source of a component.
 *
 * `scriptRanges` are the offsets kept byte for byte, `templateRanges` the
 * markup expressions were read from, and `typed` whether the script is
 * TypeScript (always for Astro).
 */
export function toVirtualSource(text, dialect) {
    const out = blankSource(text).split("");
    const blocks = scanBlocks(text, dialect);
    for (const [from, to] of blocks.scripts) {
        for (let i = from; i < to; i++) out[i] = text[i];
    }
    const writer = {
        text,
        out,
        kind: dialect === "astro" ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
    };
    for (const [from, to] of blocks.markup) {
        if (dialect === "vue") vueMarkup(writer, from, to);
        else braceMarkup(writer, from, to, dialect);
    }
    return {
        text: out.join(""),
        scriptRanges: blocks.scripts,
        templateRanges: blocks.markup,
        typed: blocks.typed,
    };
}

/** Write `value` over `out` from `offset`, one code unit per slot. */
function write(out, offset, value) {
    for (let i = 0; i < value.length; i++) out[offset + i] = value[i];
}

/**
 * The script blocks and markup ranges of a component.
 *
 * Vue's markup is its top-level `<template>`; Svelte's and Astro's is
 * everything that is not a script, a style or Astro's frontmatter.
 */
function scanBlocks(text, dialect) {
    const scripts = [];
    const markup = [];
    let typed = dialect === "astro";
    let position = 0;
    let markupStart = 0;
    if (dialect === "astro") {
        const fence = /^\s*---[ \t]*\r?\n/.exec(text);
        if (fence !== null) {
            const closing = /^---[ \t]*\r?$/gm;
            closing.lastIndex = fence[0].length;
            const match = closing.exec(text);
            if (match === null)
                throw new ComponentParseError("unclosed frontmatter");
            scripts.push([fence[0].length, match.index]);
            position = markupStart = match.index + 3;
        }
    }
    const flush = (end) => {
        if (dialect !== "vue" && end > markupStart)
            markup.push([markupStart, end]);
    };
    for (;;) {
        const lt = text.indexOf("<", position);
        if (lt < 0) break;
        if (text.startsWith("<!--", lt)) {
            const close = text.indexOf("-->", lt + 4);
            position = close < 0 ? text.length : close + 3;
            continue;
        }
        const open = /^<([A-Za-z][\w:-]*)/.exec(text.slice(lt, lt + 64));
        if (open === null) {
            position = lt + 1;
            continue;
        }
        const name = open[1].toLowerCase();
        const block =
            name === "script" ||
            name === "style" ||
            (dialect === "vue" && name !== "template")
                ? "raw"
                : dialect === "vue"
                  ? "template"
                  : null;
        const tagClose = tagEnd(text, lt + open[0].length, dialect !== "vue");
        if (tagClose < 0)
            throw new ComponentParseError(`unclosed <${open[1]}> tag`);
        if (block === null) {
            position = tagClose + 1;
            continue;
        }
        const contentStart = tagClose + 1;
        const closeTag =
            block === "template"
                ? templateEnd(text, contentStart)
                : closingTag(text, name, contentStart);
        if (closeTag < 0)
            throw new ComponentParseError(`unclosed <${open[1]}>`);
        flush(lt);
        if (name === "script") {
            const attributes = parseAttributes(
                text.slice(lt + open[0].length, tagClose),
            );
            const lang = String(attributes.get("lang") ?? "").toLowerCase();
            if (lang === "tsx" || lang === "jsx")
                throw new ComponentParseError(
                    `script lang="${lang}" is not supported`,
                );
            if (lang === "ts") typed = true;
            if (executableScript(attributes))
                scripts.push([contentStart, closeTag]);
        } else if (block === "template") {
            markup.push([contentStart, closeTag]);
        }
        position = markupStart = afterTag(text, closeTag);
    }
    flush(text.length);
    return { scripts, markup, typed };
}

/** A script the bundler runs: not `is:inline`, and JavaScript or TypeScript. */
function executableScript(attributes) {
    if (attributes.has("is:inline")) return false;
    const type = attributes.get("type");
    return (
        type === undefined ||
        /^(?:module|text\/javascript|application\/javascript|text\/typescript)$/i.test(
            String(type),
        )
    );
}

/**
 * The offset of the `>` that ends a start tag, skipping quoted values, and
 * with `braces` also `{…}` expressions, or -1.
 */
function tagEnd(text, from, braces) {
    let quote = null;
    let depth = 0;
    for (let i = from; i < text.length; i++) {
        const c = text[i];
        if (quote !== null) {
            if (c === quote) quote = null;
        } else if (c === '"' || c === "'") quote = c;
        else if (braces && c === "{") depth++;
        else if (braces && c === "}") depth = Math.max(0, depth - 1);
        else if (c === ">" && depth === 0) return i;
    }
    return -1;
}

/** The offset of the first `</name` at or after `from`, or -1. */
function closingTag(text, name, from) {
    const pattern = new RegExp(`</${name}\\b`, "gi");
    pattern.lastIndex = from;
    const match = pattern.exec(text);
    return match === null ? -1 : match.index;
}

/** The offset of the `</template` closing the one opened before `from`, or -1. */
function templateEnd(text, from) {
    const tags = /<(\/?)template\b/gi;
    tags.lastIndex = from;
    let depth = 1;
    let match;
    while ((match = tags.exec(text)) !== null) {
        if (match[1] === "/") {
            if (--depth === 0) return match.index;
        } else {
            const end = tagEnd(text, tags.lastIndex, false);
            if (end > 0 && text[end - 1] !== "/") depth++;
        }
    }
    return -1;
}

/** The offset just past the `>` of the closing tag starting at `closeTag`. */
function afterTag(text, closeTag) {
    const end = text.indexOf(">", closeTag);
    return end < 0 ? text.length : end + 1;
}

/** A start tag's attributes, lower-cased names to values (true when bare). */
function parseAttributes(source) {
    const attributes = new Map();
    const pattern =
        /([^\s=>/"']+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>"']+)))?/g;
    for (const match of source.matchAll(pattern))
        attributes.set(
            match[1].toLowerCase(),
            match[2] ?? match[3] ?? match[4] ?? true,
        );
    return attributes;
}

/** Vue markup; implemented in Task 2. */
function vueMarkup() {}

/** Svelte and Astro markup; implemented in Task 3. */
function braceMarkup() {}
