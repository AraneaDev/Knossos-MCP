import { describe, expect, it } from "vitest";
import { plain, waitFor } from "./driver.mjs";

describe("plain", () => {
    it("strips ANSI sequences", () =>
        expect(plain("\u001b[1;31mHi\u001b[0m")).toBe("Hi"));
});

describe("waitFor", () => {
    it("resolves on the first frame that matches", async () => {
        const frames = ["a", "b", "Overview"];
        const fake = { snap: async () => frames.shift() ?? "Overview" };
        await expect(
            waitFor(fake, (s) => s.includes("Overview"), {
                timeoutMs: 1000,
                step: "open",
                pollMs: 1,
            }),
        ).resolves.toContain("Overview");
    });
    it("names the step and keeps the last frame on timeout", async () => {
        const fake = { snap: async () => "nothing" };
        await expect(
            waitFor(fake, () => false, {
                timeoutMs: 20,
                step: "open pane",
                pollMs: 5,
            }),
        ).rejects.toMatchObject({
            message: expect.stringContaining("open pane"),
            lastFrame: "nothing",
        });
    });
});
