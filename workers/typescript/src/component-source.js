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
        if (!blocks.typed && dialect !== "astro")
            commonJsRequires(text, out, from, to);
    }
    const writer = {
        text,
        out,
        kind: dialect === "astro" ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
        blocked: commentedTails(
            text,
            blocks.scripts,
            dialect === "astro" ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
        ),
    };
    for (const [from, to] of blocks.markup) {
        if (dialect === "vue") vueMarkup(writer, from, to);
        else braceMarkup(writer, from, to, dialect);
    }
    return {
        text:
            out.join("") +
            (dialect === "astro" ? astroGlobal(text, blocks.scripts) : ""),
        scriptRanges: blocks.scripts,
        templateRanges: blocks.markup,
        typed: blocks.typed,
    };
}

/**
 * The `Astro` global as Astro's own tooling gives it to a component: its
 * `AstroGlobal`, with `props` typed by the component's `Props` when its
 * frontmatter declares one. The shared `astro/client` declaration leaves
 * `props` a loose record, so a field read from it took the type of its
 * destructuring default (`params = {}` became `{}`) and code Astro accepts
 * reported type errors. Appended after the component's own text, which it
 * therefore moves nowhere, and module-scoped, so it shadows that global.
 * Where Astro is not installed the import resolves to nothing and `Astro` is
 * untyped, as it was; what the appended text reports lies outside every
 * script range, so it is never kept.
 */
function astroGlobal(text, scripts) {
    const declaresProps = scripts.some(([from, to]) =>
        /\b(?:interface\s+Props\s*(?:extends\b|\{)|type\s+Props\s*=)/.test(
            text.slice(from, to),
        ),
    );
    const global = declaresProps
        ? 'import("astro").AstroGlobal<Props>'
        : 'import("astro").AstroGlobal';
    return `\nexport {};\ndeclare const Astro: ${global};\n`;
}

/**
 * A plain JavaScript script is offered to the compiler as TypeScript, where
 * `require('./x')` imports nothing. Each call on a literal is rewritten in
 * place as the dynamic `import ('./x')`, the same length, so what it loads is
 * imported as it would be from a `.js` file.
 */
function commonJsRequires(text, out, from, to) {
    const call = /(?<![\w$.])require(?=\s*\(\s*['"`])/g;
    call.lastIndex = from;
    let match;
    while ((match = call.exec(text)) !== null && match.index < to)
        write(out, match.index, "import ");
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
        const skipped = skipBeforeTag(text, position, lt, dialect);
        if (skipped >= 0) {
            position = skipped;
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
        // `<script … />` has no content and no closing tag.
        if (block === null || text[tagClose - 1] === "/") {
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
            if (
                lang === "ts" ||
                lang === "typescript" ||
                /^text\/typescript$/i.test(String(attributes.get("type") ?? ""))
            )
                typed = true;
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

/**
 * Where the block scan resumes when what lies ahead of the `<` at `lt` is not
 * a tag, or -1 when it is one to read. Svelte and Astro text holds `{…}`
 * expressions, where `a<b` is a comparison, so an expression that opens
 * before the `<` is skipped whole; and a comment is skipped to its end.
 */
function skipBeforeTag(text, position, lt, dialect) {
    const brace = dialect === "vue" ? -1 : text.indexOf("{", position);
    if (brace >= 0 && brace < lt) {
        const close = matchingBrace(text, brace, text.length);
        return close < 0 ? brace + 1 : close + 1;
    }
    if (text.startsWith("<!--", lt)) {
        const close = text.indexOf("-->", lt + 4);
        return close < 0 ? text.length : close + 3;
    }
    return -1;
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
        const comment = depth > 0 && quote === null ? commentEnd(text, i) : -1;
        if (comment >= 0) i = comment;
        else if (quote !== null) {
            if (c === quote) quote = null;
        } else if (c === '"' || c === "'" || (depth > 0 && c === "`"))
            quote = c;
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

/**
 * The spans a script's trailing line comment reaches: from the end of a
 * script that ends inside a `//` comment to the end of that line. The
 * comment runs on in the virtual text, so anything written there would be
 * swallowed, and an expression continuing onto the next line would leave
 * half of itself behind.
 */
function commentedTails(text, scripts, kind) {
    const tails = [];
    for (const [from, to] of scripts) {
        // Parsed, not just scanned: only the parser knows that the `//` in
        // `/[//]/` belongs to a regular expression.
        const script = text.slice(from, to);
        const file = ts.createSourceFile(
            kind === ts.ScriptKind.TSX ? "script.tsx" : "script.ts",
            script,
            ts.ScriptTarget.Latest,
            false,
            kind,
        );
        // A comment on the last statement's line is a trailing one; one on
        // a line of its own, a leading one of the end-of-file token.
        const end = file.endOfFileToken.pos;
        const trailing = [
            ...(ts.getTrailingCommentRanges(script, end) ?? []),
            ...(ts.getLeadingCommentRanges(script, end) ?? []),
        ].at(-1);
        if (
            trailing?.kind !== ts.SyntaxKind.SingleLineCommentTrivia ||
            trailing.end !== script.length
        )
            continue;
        const newline = text.slice(to).search(/[\r\n]/);
        tails.push([to, newline < 0 ? text.length : to + newline]);
    }
    return tails;
}

/** Whether a write at `offset` falls where a script's trailing comment reaches. */
function isBlocked(writer, offset) {
    return writer.blocked.some(([from, to]) => offset >= from && offset < to);
}

/**
 * Write the expression at [exprStart, exprEnd) back in place, framed by
 * `open` at `openAt` and `close` at `closeAt`, when the framed text parses on
 * its own. A fragment that does not parse stays blank, so no template
 * content can turn the script around it into a syntax error.
 */
function placeFramed(
    writer,
    exprStart,
    exprEnd,
    openAt,
    open,
    closeAt,
    close,
    rewrite = (value) => value,
) {
    const { text, out } = writer;
    if (isBlocked(writer, openAt)) return false;
    const expression = rewrite(text.slice(exprStart, exprEnd));
    if (expression.trim() === "") return false;
    const frame =
        text.slice(openAt, openAt + open.length) +
        text.slice(closeAt, closeAt + close.length);
    if (/[\r\n]/.test(frame)) return false;
    if (!parsesCleanly(open + expression + close, writer.kind)) return false;
    write(out, openAt, open);
    write(out, exprStart, expression);
    write(out, closeAt, close);
    return true;
}

/** Whether a fragment parses without syntax errors. */
function parsesCleanly(source, kind) {
    const probe = ts.createSourceFile(
        kind === ts.ScriptKind.TSX ? "probe.tsx" : "probe.ts",
        source,
        ts.ScriptTarget.Latest,
        false,
        kind,
    );
    return probe.parseDiagnostics.length === 0;
}

/**
 * Write a component tag back as `;Name` at its `<`: PascalCase as it is,
 * kebab-case as PascalCase padded with spaces. Plain HTML elements and
 * namespaced tags stay blank.
 */
function writeTagReference(writer, lt, name) {
    if (name.includes(":") || isBlocked(writer, lt)) return;
    let identifier;
    if (/^[A-Z]/.test(name)) identifier = name;
    else if (name.includes("-"))
        identifier = name
            .split("-")
            .filter((part) => part !== "")
            .map((part) => part[0].toUpperCase() + part.slice(1))
            .join("");
    else return;
    if (!/^[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*$/.test(identifier)) return;
    write(writer.out, lt, `;${identifier}`);
    // Ended with `;` when the next slot is blank markup on the same line, so
    // an attribute expression after it (`<Card {x}>`) starts a new statement.
    const after = lt + 1 + identifier.length;
    if (
        writer.out[after] === " " &&
        !/[\r\n{]/.test(writer.text[after] ?? "\n")
    )
        writer.out[after] = ";";
}

/** Vue template markup: interpolations, directive values and component tags. */
function vueMarkup(writer, start, end) {
    const { text } = writer;
    let i = start;
    while (i < end) {
        if (text.startsWith("<!--", i)) {
            const close = text.indexOf("-->", i + 4);
            i = close < 0 || close >= end ? end : close + 3;
        } else if (text.startsWith("{{", i)) {
            const close = text.indexOf("}}", i + 2);
            if (close < 0 || close >= end) {
                i += 2;
                continue;
            }
            placeFramed(writer, i + 2, close, i, ";(", close, ")");
            i = close + 2;
        } else if (text[i] === "<" && /[A-Za-z]/.test(text[i + 1] ?? "")) {
            i = vueElement(writer, i, end);
        } else {
            i++;
        }
    }
}

/** One Vue start tag: its name, then each quoted attribute value. */
function vueElement(writer, lt, end) {
    const { text } = writer;
    const name = /^<([A-Za-z][\w.:-]*)/.exec(text.slice(lt, lt + 128));
    writeTagReference(writer, lt, name[1]);
    const close = tagEnd(text, lt + name[0].length, false);
    const stop = close < 0 || close >= end ? end : close;
    const attributeName = /[^\s=>/"']+/y;
    const assignment = /\s*=\s*(["'])/y;
    let i = lt + name[0].length;
    while (i < stop) {
        attributeName.lastIndex = i;
        const attribute = attributeName.exec(text);
        if (attribute === null) {
            i++;
            continue;
        }
        assignment.lastIndex = attributeName.lastIndex;
        const value = assignment.exec(text);
        if (value === null) {
            i = attributeName.lastIndex;
            continue;
        }
        const valueStart = assignment.lastIndex;
        const valueEnd = text.indexOf(value[1], valueStart);
        if (valueEnd < 0 || valueEnd > stop) break;
        vueDirective(writer, attribute[0], valueStart, valueEnd);
        i = valueEnd + 1;
    }
    return close < 0 || close >= end ? end : close + 1;
}

/**
 * A directive's value written back as `;(expression)`, or as `;{handler}`
 * for an event, framed by the two characters before it (`="`) and its closing
 * quote. Slot bindings declare names rather than use them, and a `v-for`
 * keeps only its iterable.
 */
function vueDirective(writer, name, valueStart, valueEnd) {
    if (name.startsWith("#") || /^v-slot(?::|$)/.test(name)) return;
    if (!/^(?:v-|:|@)/.test(name)) return;
    let exprStart = valueStart;
    if (name === "v-for") {
        const head = vForHeadLength(writer.text.slice(valueStart, valueEnd));
        if (head < 0) return;
        exprStart = valueStart + head;
    }
    // An event handler is a statement list (`a(); b()`), which parentheses
    // cannot hold; every other directive is one expression.
    const handler = /^(?:@|v-on:)/.test(name);
    placeFramed(
        writer,
        exprStart,
        valueEnd,
        exprStart - 2,
        handler ? ";{" : ";(",
        valueEnd,
        handler ? "}" : ")",
    );
}

/**
 * The length of a `v-for` value's head up to its iterable, or -1: the
 * binding (`item`, `(item, i)`, `{ id, name }`, `([a, b], i)`), then `in` or
 * `of`. A bracketed binding is matched to its closing bracket, so a
 * destructuring pattern with commas or nested brackets is one head.
 */
function vForHeadLength(value) {
    const start = value.length - value.trimStart().length;
    let end = start;
    const open = value[start];
    if (open === "(" || open === "{" || open === "[") {
        const pairs = { "(": ")", "{": "}", "[": "]" };
        const stack = [];
        for (end = start; end < value.length; end++) {
            if (value[end] in pairs) stack.push(pairs[value[end]]);
            else if (
                value[end] === stack.at(-1) &&
                stack.pop() &&
                stack.length === 0
            )
                break;
        }
        end++;
    } else {
        while (end < value.length && !/\s/.test(value[end])) end++;
    }
    const keyword = /\s+(?:in|of)\s+/y;
    keyword.lastIndex = end;
    return end > start && keyword.test(value) ? keyword.lastIndex : -1;
}

/**
 * Svelte block tags: the prefix blanked before the expression, and what ends
 * the expression when more follows it. A tag not listed (`{:else}`, `{/if}`,
 * `{:then v}`, `{#snippet …}`) declares or closes, and stays blank.
 */
const SVELTE_BLOCKS = [
    [/^\s*#(?:if|key)\s+/, null],
    [/^\s*:else\s+if\s+/, null],
    [/^\s*@(?:html|render|debug)\s+/, null],
    [/^\s*#each\s+/, /\s+as\b/],
    [/^\s*#await\s+/, /\s+(?:then|catch)\b/],
    [/^\s*@const\s+[^=]*=\s*/, null],
];

/**
 * Svelte and Astro markup: every `{…}` expression, and component tags.
 *
 * Quotes only count inside a tag, since text is full of apostrophes. Svelte
 * reads `{…}` inside a quoted attribute too; Astro treats quoted values as
 * plain strings.
 */
function braceMarkup(writer, start, end, dialect) {
    const { text } = writer;
    let inTag = false;
    let quote = null;
    let i = start;
    while (i < end) {
        const c = text[i];
        if (!inTag && text.startsWith("<!--", i)) {
            const close = text.indexOf("-->", i + 4);
            i = close < 0 || close >= end ? end : close + 3;
            continue;
        }
        if (c === "{" && (quote === null || dialect === "svelte")) {
            const close = matchingBrace(text, i, end);
            if (close > 0) {
                braceExpression(writer, i, close, dialect);
                i = close + 1;
                continue;
            }
        } else if (inTag) {
            if (quote !== null) {
                if (c === quote) quote = null;
            } else if (c === '"' || c === "'") quote = c;
            else if (c === ">") inTag = false;
        } else if (c === "<" && /[A-Za-z]/.test(text[i + 1] ?? "")) {
            const name = /^<([A-Za-z][\w.:-]*)/.exec(text.slice(i, i + 128));
            writeTagReference(writer, i, name[1]);
            inTag = true;
            i += name[0].length;
            continue;
        }
        i++;
    }
}

/** One `{…}` written back as a block statement holding its expression. */
function braceExpression(writer, open, close, dialect) {
    const inner = writer.text.slice(open + 1, close);
    let from = 0;
    let to = inner.length;
    if (dialect === "svelte" && /^\s*(?:[#:@]|\/(?![/*]))/.test(inner)) {
        const rule = SVELTE_BLOCKS.find(([head]) => head.test(inner));
        if (rule === undefined) return;
        from = rule[0].exec(inner)[0].length;
        const stop = rule[1]?.exec(inner.slice(from));
        if (stop) to = from + stop.index;
    } else {
        const spread = /^\s*\.\.\./.exec(inner);
        if (spread !== null) from = spread[0].length;
    }
    placeFramed(
        writer,
        open + 1 + from,
        open + 1 + to,
        open,
        "{",
        close,
        "}",
        dialect === "svelte" ? storeReads : undefined,
    );
}

/** Svelte's `$store` auto-subscription read as the store binding itself. */
function storeReads(expression) {
    return expression.replace(/(^|[^\w$])\$(?=[A-Za-z_])/g, "$1 ");
}

/** The offset of the `}` closing the `{` at `open`, skipping strings, or -1. */
function matchingBrace(text, open, end) {
    let depth = 0;
    for (let i = open; i < end; i++) {
        const c = text[i];
        const comment = commentEnd(text, i);
        if (comment >= 0) i = comment;
        else if (c === '"' || c === "'" || c === "`") {
            const close = closingQuote(text, i, end);
            if (close < 0) return -1;
            i = close;
        } else if (c === "{") depth++;
        else if (c === "}" && --depth === 0) return i;
    }
    return -1;
}

/**
 * The offset of the last character of a JavaScript comment starting at `i`,
 * or -1 when none starts there. Inside an expression an apostrophe in a
 * comment (`// the header's band`) is not a string.
 */
function commentEnd(text, i) {
    if (text[i] !== "/") return -1;
    if (text[i + 1] === "/") {
        const newline = text.indexOf("\n", i);
        return newline < 0 ? text.length - 1 : newline - 1;
    }
    if (text[i + 1] === "*") {
        const close = text.indexOf("*/", i + 2);
        return close < 0 ? text.length - 1 : close + 1;
    }
    return -1;
}

/** The offset of the unescaped quote closing the one at `open`, or -1. */
function closingQuote(text, open, end) {
    for (let i = open + 1; i < end; i++) {
        if (text[i] === "\\") i++;
        else if (text[i] === text[open]) return i;
    }
    return -1;
}
/** Names each framework's compiler provides to a component's script. */
const FRAMEWORK_GLOBALS = {
    vue: new Set([
        "defineProps",
        "defineEmits",
        "withDefaults",
        "defineExpose",
        "defineModel",
        "defineOptions",
        "defineSlots",
    ]),
    svelte: new Set([
        "$state",
        "$derived",
        "$effect",
        "$props",
        "$bindable",
        "$inspect",
        "$host",
    ]),
    astro: new Set(["Astro"]),
};

/**
 * Diagnostics Astro's own compiler makes moot: `Astro` used as a value where
 * only its types are installed (2708), a frontmatter `return` (1108), names
 * declared in both the frontmatter and a client script, which Astro compiles
 * as separate modules (2300, 2451), and parameters left implicitly `any`
 * because `Astro.props` is untyped here (7006, 7031).
 */
const ASTRO_ARTEFACTS = new Set([2708, 1108, 2300, 2451, 7006, 7031]);

/**
 * Whether a compiler diagnostic on a component describes its own code.
 *
 * Template regions are generated, a missing framework global is provided by
 * the framework's compiler, and a plain-JavaScript script is not type-checked
 * (as with `checkJs: false`), so only its syntax errors (below 2000) count.
 */
export function componentDiagnosticKept(component, diagnostic) {
    const start = diagnostic.start ?? 0;
    if (
        !component.scriptRanges.some(
            ([from, to]) => start >= from && start < to,
        )
    )
        return false;
    if (!component.typed && diagnostic.code >= 2000) return false;
    if (component.dialect === "astro" && ASTRO_ARTEFACTS.has(diagnostic.code))
        return false;
    if (diagnostic.code !== 2304) return true;
    const name = diagnostic.file.text.slice(
        start,
        start + (diagnostic.length ?? 0),
    );
    return !FRAMEWORK_GLOBALS[component.dialect].has(name);
}
