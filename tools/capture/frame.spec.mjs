import { describe, expect, it } from "vitest";
import { framePage, THEMES } from "./frame.mjs";

describe("framePage", () => {
    it("embeds the frame as a JSON string, never raw HTML", () => {
        const page = framePage("\u001b[31m</script><b>x", {
            cols: 80,
            rows: 24,
            theme: "dark",
        });
        expect(page).not.toContain("</script><b>");
        expect(page).toContain(
            JSON.stringify("\u001b[31m</script><b>x").replace(/</g, "\\u003c"),
        );
    });
    it("sizes the terminal and applies the theme", () => {
        const page = framePage("", { cols: 160, rows: 48, theme: "light" });
        expect(page).toContain('"cols":160');
        expect(page).toContain('"rows":48');
        expect(page).toContain(THEMES.light.background);
    });
});
