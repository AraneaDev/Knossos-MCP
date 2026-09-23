import { describe, expect, it } from "vitest";

import {
    ComponentParseError,
    blankSource,
    componentAliasSuffix,
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
