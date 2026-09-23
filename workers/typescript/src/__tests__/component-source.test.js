import ts from "typescript";
import { describe, expect, it } from "vitest";

import {
    ComponentParseError,
    blankSource,
    componentAliasSuffix,
    componentDiagnosticKept,
    componentDialect,
    toVirtualSource,
} from "../component-source.js";

// The virtual text is what the compiler parses in place of a component, so
// every offset in it must be the component's own.
function expectInvariants(source, dialect) {
    const virtual = toVirtualSource(source, dialect);
    expect(virtual.text.length).toBe(source.length);
    for (let i = 0; i < source.length; i++) {
        if (source[i] === "\n" || source[i] === "\r")
            expect(virtual.text[i]).toBe(source[i]);
    }
    for (const [from, to] of virtual.scriptRanges)
        expect(virtual.text.slice(from, to)).toBe(source.slice(from, to));
    return virtual;
}

describe("component dialects", () => {
    it("is decided by the extension, in any case", () => {
        expect(componentDialect("/a/App.vue")).toBe("vue");
        expect(componentDialect("/a/App.VUE")).toBe("vue");
        expect(componentDialect("/a/Counter.svelte")).toBe("svelte");
        expect(componentDialect("/a/index.astro")).toBe("astro");
        expect(componentDialect("/a/index.ts")).toBeNull();
        expect(componentDialect("/a/vue")).toBeNull();
    });

    it("offers Astro as TSX and the others as TS", () => {
        expect(componentAliasSuffix("vue")).toBe(".ts");
        expect(componentAliasSuffix("svelte")).toBe(".ts");
        expect(componentAliasSuffix("astro")).toBe(".tsx");
    });

    it("blanks everything but line breaks", () => {
        expect(blankSource("a\r\nb😀")).toBe(" \r\n   ");
    });
});

describe("script blocks", () => {
    it("keeps a Vue component's script and setup script in place", () => {
        const source =
            '<template>\n  <p>hi</p>\n</template>\n<script>\nexport default {};\n</script>\n<script setup lang="ts">\nconst a: number = 1;\n</script>\n<style>\np { color: red; }\n</style>\n';
        const virtual = expectInvariants(source, "vue");

        expect(virtual.scriptRanges).toHaveLength(2);
        expect(virtual.text).toContain("export default {};");
        expect(virtual.text).toContain("const a: number = 1;");
        expect(virtual.text).not.toContain("color");
        expect(virtual.typed).toBe(true);
    });

    it("keeps Svelte's instance and module scripts", () => {
        const source =
            '<script context="module">\nexport const shared = 1;\n</script>\n<script>\nlet count = 0;\n</script>\n<button>{count}</button>\n';
        const virtual = expectInvariants(source, "svelte");

        expect(virtual.scriptRanges).toHaveLength(2);
        expect(virtual.typed).toBe(false);
        expect(virtual.templateRanges.length).toBeGreaterThan(0);
    });

    it("keeps Astro's frontmatter and bundled scripts, not inline ones", () => {
        const source =
            "---\nimport Card from '../Card.astro';\n---\n<Card />\n<script>\nimport { boot } from '../boot';\nboot();\n</script>\n<script is:inline>\nwindow.x = 1;\n</script>\n";
        const virtual = expectInvariants(source, "astro");

        expect(virtual.text).toContain("import Card from '../Card.astro';");
        expect(virtual.text).toContain("boot();");
        expect(virtual.text).not.toContain("window.x");
        expect(virtual.typed).toBe(true);
    });

    it("closes a script at the first </script, as a browser does", () => {
        const source =
            '<script>\nconst s = "</script>";\n</script>\n<template><p/></template>\n';
        const virtual = expectInvariants(source, "vue");

        expect(virtual.scriptRanges[0][1]).toBe(source.indexOf("</script>"));
    });

    it("keeps offsets after a character outside the basic plane", () => {
        const source =
            "<template><p>😀</p></template>\n<script>\nexport const x = 1;\n</script>\n";
        const virtual = expectInvariants(source, "vue");

        expect(virtual.text.indexOf("export const x")).toBe(
            source.indexOf("export const x"),
        );
    });

    it("handles CRLF line endings and a component without a script", () => {
        expectInvariants(
            "<template>\r\n  <p>only markup</p>\r\n</template>\r\n",
            "vue",
        );
        const virtual = expectInvariants("<p>only markup</p>\n", "svelte");
        expect(virtual.scriptRanges).toEqual([]);
    });

    it("ends the Vue template at its own closing tag, past nested ones", () => {
        const source =
            '<template>\n  <template v-if="a"><p/></template>\n</template>\n<script>\nexport default {};\n</script>\n';
        const virtual = expectInvariants(source, "vue");

        expect(virtual.templateRanges).toHaveLength(1);
        expect(virtual.templateRanges[0][1]).toBe(
            source.lastIndexOf("</template>"),
        );
    });

    it("refuses an unclosed script or frontmatter", () => {
        expect(() => toVirtualSource("<script>\nlet a;\n", "vue")).toThrow(
            ComponentParseError,
        );
        expect(() =>
            toVirtualSource("---\nconst a = 1;\n<p/>\n", "astro"),
        ).toThrow(ComponentParseError);
    });

    it("refuses a TSX or JSX script, which cannot be offered as TS", () => {
        expect(() =>
            toVirtualSource(
                '<script lang="tsx">\nexport default {};\n</script>\n',
                "vue",
            ),
        ).toThrow(/lang="tsx"/);
    });
});

// The template part of a Vue component, wrapped so tests read as markup.
function vue(template, script = "export default {};") {
    const source = `<template>\n${template}\n</template>\n<script setup lang="ts">\n${script}\n</script>\n`;
    return { source, virtual: expectInvariants(source, "vue").text };
}

describe("Vue templates", () => {
    it("writes an interpolation back as a statement", () => {
        const { source, virtual } = vue("<p>{{ format(x) }}</p>");
        const at = source.indexOf("{{");
        expect(virtual.slice(at, at + 15)).toBe(";( format(x) ) ");
    });

    it("writes directive values back and blanks plain attributes", () => {
        const { virtual } = vue(
            '<button class="card" @click="save(item)" :to="route" v-if="ok">x</button>',
        );
        expect(virtual).toContain(";{save(item)}");
        expect(virtual).toContain(";(route)");
        expect(virtual).toContain(";(ok)");
        expect(virtual).not.toContain("card");
    });

    it("keeps only the iterable of a v-for", () => {
        const { virtual } = vue('<li v-for="(item, i) in items">x</li>');
        expect(virtual).toContain(";(items)");
        expect(virtual).not.toContain("item,");
    });

    it("blanks slot bindings", () => {
        const { virtual } = vue(
            '<Table v-slot="{ row }" #cell="{ value }"></Table>',
        );
        expect(virtual).not.toContain("row");
        expect(virtual).not.toContain("value");
    });

    it("keeps a handler spanning several lines", () => {
        const { source, virtual } = vue(
            '<b @click="\n  first();\n  second()\n">x</b>',
        );
        // A handler is a statement list, so it is framed as a block.
        expect(virtual).toContain("{\n  first();");
        expect(virtual).toContain("second()");
        expect(virtual.split("\n").length).toBe(source.split("\n").length);
    });

    it("writes component tags as references, kebab-case as PascalCase", () => {
        const { source, virtual } = vue(
            "<UserCard /><user-card></user-card><div/><router-link/>",
        );
        expect(
            virtual.slice(
                source.indexOf("<UserCard"),
                source.indexOf("<UserCard") + 9,
            ),
        ).toBe(";UserCard");
        expect(
            virtual.slice(
                source.indexOf("<user-card"),
                source.indexOf("<user-card") + 10,
            ),
        ).toBe(";UserCard ");
        expect(virtual).toContain(";RouterLink");
        expect(virtual).not.toContain("div");
    });

    it("leaves an expression that does not parse on its own blank", () => {
        const { virtual } = vue('<b @click="a(">x</b><i :x="ok">y</i>');
        expect(virtual).not.toContain("a(");
        expect(virtual).toContain(";(ok)");
    });

    it("blanks HTML comments in the template", () => {
        const { virtual } = vue("<!-- {{ hidden() }} --><p>{{ shown() }}</p>");
        expect(virtual).not.toContain("hidden");
        expect(virtual).toContain("shown()");
    });

    it("parses as TypeScript without syntax errors", () => {
        const { virtual } = vue(
            '<UserCard v-for="u in users" :key="u.id" @click="select(u)">{{ u.name }}</UserCard>',
            "const users = [];\nfunction select(u: unknown) {}",
        );
        const file = ts.createSourceFile(
            "x.ts",
            virtual,
            ts.ScriptTarget.Latest,
            false,
            ts.ScriptKind.TS,
        );
        expect(file.parseDiagnostics).toEqual([]);
    });
});

function svelte(markup, script = "let a = 1;") {
    const source = `<script lang="ts">\n${script}\n</script>\n${markup}\n`;
    return { source, virtual: expectInvariants(source, "svelte").text };
}

describe("Svelte markup", () => {
    it("keeps expressions as blocks, in text and attributes", () => {
        const { virtual } = svelte(
            '<a href={url} class="x {active}">{label}</a>',
        );
        expect(virtual).toContain("{url}");
        expect(virtual).toContain("{active}");
        expect(virtual).toContain("{label}");
    });

    it("keeps the expression of each block tag and blanks the keyword", () => {
        const { virtual } = svelte(
            "{#if ready}{:else if waiting}{/if}{#each items as item, i (item.id)}{item.name}{/each}{#await load() then v}{/await}{@html body}{@const total = sum(xs)}{#key k}{/key}",
        );
        for (const kept of [
            "ready}",
            "waiting}",
            "items",
            "load()",
            "body}",
            "sum(xs)}",
            "k}",
        ])
            expect(virtual).toContain(kept);
        for (const gone of [
            "#if",
            "else",
            "#each",
            " as ",
            "then v",
            "@html",
            "@const",
            "total",
            "/if",
        ])
            expect(virtual).not.toContain(gone);
    });

    it("keeps directive expressions and spreads", () => {
        const { virtual } = svelte(
            "<input bind:value={name} on:input={update} {...rest} />",
        );
        expect(virtual).toContain("{name}");
        expect(virtual).toContain("{update}");
        expect(virtual).toContain("rest}");
        expect(virtual).not.toContain("...");
    });

    it("reads a $store as the store itself", () => {
        const { virtual } = svelte("<p>{$count} {$$props.x}</p>");
        expect(virtual).toContain("{ count}");
        expect(virtual).toContain("$$props.x");
    });

    it("is not thrown off by an apostrophe in text", () => {
        const { virtual } = svelte("<p>Don't {shown}</p>");
        expect(virtual).toContain("{shown}");
    });

    it("is not thrown off by > inside an attribute expression", () => {
        const { virtual } = svelte(
            "<div class={a > b ? 'x' : 'y'}><Child /></div>",
        );
        expect(virtual).toContain("{a > b ? 'x' : 'y'}");
        expect(virtual).toContain(";Child");
    });

    it("blanks snippets and namespaced tags", () => {
        const { virtual } = svelte(
            "{#snippet row(x)}{/snippet}<svelte:head></svelte:head>",
        );
        expect(virtual).not.toContain("row");
        expect(virtual).not.toContain("svelte");
    });
});

describe("Astro markup", () => {
    it("keeps JSX inside expressions and parses as TSX", () => {
        const source =
            "---\nconst items = [1];\n---\n<ul>{items.map((i) => <li><Item value={i} /></li>)}</ul>\n<Footer />\n";
        const virtual = expectInvariants(source, "astro").text;
        expect(virtual).toContain(
            "{items.map((i) => <li><Item value={i} /></li>)}",
        );
        expect(virtual).toContain(";Footer");
        const file = ts.createSourceFile(
            "x.tsx",
            virtual,
            ts.ScriptTarget.Latest,
            false,
            ts.ScriptKind.TSX,
        );
        expect(file.parseDiagnostics).toEqual([]);
    });

    it("treats quoted attributes as strings", () => {
        const source = '---\n---\n<a title="{not}" href={real}>x</a>\n';
        const virtual = expectInvariants(source, "astro").text;
        expect(virtual).not.toContain("not");
        expect(virtual).toContain("{real}");
    });
});

describe("component diagnostics", () => {
    const text =
        "<template></template><script setup>defineProps(); missing();</script>";
    const script = [text.indexOf("defineProps"), text.indexOf("</script>")];
    const at = (name, code, typed = true, dialect = "vue") =>
        componentDiagnosticKept(
            { dialect, scriptRanges: [script], typed },
            {
                file: { text },
                start: text.indexOf(name),
                length: name.length,
                code,
            },
        );

    it("drops what falls outside the script", () => {
        expect(at("template", 2304)).toBe(false);
    });

    it("drops a missing framework global, keeps any other", () => {
        expect(at("defineProps", 2304)).toBe(false);
        expect(at("missing", 2304)).toBe(true);
    });

    it("keeps only syntax errors for an untyped script", () => {
        expect(at("missing", 2304, false)).toBe(false);
        expect(at("missing", 1005, false)).toBe(true);
    });
});

describe("Astro self-closing scripts", () => {
    it("are empty elements, not unclosed blocks", () => {
        const source =
            '---\nconst json = "[]";\n---\n<script id="data" type="application/json" is:inline set:html={json} />\n<p>{json}</p>\n';
        const virtual = expectInvariants(source, "astro");

        expect(virtual.text).toContain("{json}");
    });
});

describe("Astro diagnostics", () => {
    const text =
        "---\nconst a = Astro.props;\nreturn Astro.redirect('/');\n---\n<script>\nconst a = 1;\n</script>\n";
    const scripts = [
        [4, text.indexOf("---", 4)],
        [text.indexOf("\nconst a = 1"), text.indexOf("</script>")],
    ];
    const kept = (code, at = text.indexOf("Astro")) =>
        componentDiagnosticKept(
            { dialect: "astro", scriptRanges: scripts, typed: true },
            { file: { text }, start: at, length: 5, code },
        );

    it("drops what comes from Astro's compiler rather than the code", () => {
        // `Astro` used as a value where only its types are installed.
        expect(kept(2708)).toBe(false);
        // A page's frontmatter may return a redirect.
        expect(kept(1108, text.indexOf("return"))).toBe(false);
        // Frontmatter and a client script are separate modules to Astro.
        expect(kept(2300, text.indexOf("a = 1"))).toBe(false);
        expect(kept(2451, text.indexOf("a = 1"))).toBe(false);
        // Parameters left untyped because `Astro.props` is.
        expect(kept(7006)).toBe(false);
    });

    it("keeps the rest", () => {
        expect(kept(2322)).toBe(true);
    });
});

describe("comments inside expressions", () => {
    it("do not open a string with an apostrophe", () => {
        const { virtual } = svelte(
            "<span\n  class={css({\n    // the header's band\n    color: \"x\",\n  })}>text</span\n>\n<p>{after}</p>\n<b title={/* it's */ y}>z</b>",
        );
        expect(virtual).toContain("{after}");
        expect(virtual).toContain('color: "x"');
        expect(virtual).toContain("y}");
        const astro = expectInvariants(
            "---\nconst x = 1;\n---\n<span title={`the site's own ${x}`}>a</span>\n<p>{x}</p>\n",
            "astro",
        ).text;
        expect(astro.split("\n")[4]).toBe("   {x}    ");
    });
});
