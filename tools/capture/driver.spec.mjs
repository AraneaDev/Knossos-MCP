import { describe, expect, it } from "vitest";
import { plain, resize, waitFor } from "./driver.mjs";

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

describe("resize", () => {
    it("resizes the window on the session's own server and keeps the new size", async () => {
        const calls = [];
        const session = {
            name: "s",
            socket: "sock",
            cols: 160,
            rows: 48,
            runTmux: async (argv) => calls.push(argv),
        };
        await resize(session, 224, 56);
        expect(calls).toEqual([
            ["-L", "sock", "resize-window", "-t", "s", "-x", "224", "-y", "56"],
        ]);
        expect([session.cols, session.rows]).toEqual([224, 56]);
    });
});
