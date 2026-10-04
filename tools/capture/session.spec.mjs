import { describe, expect, it } from "vitest";
import { guardEnv, registerCleanup, runCleanups } from "./session.mjs";

describe("guardEnv", () => {
    const real = {
        realDataDir: "/root/.knossos",
        realConfigDir: "/root/.claude",
    };
    it("refuses the real data dir", () => {
        expect(() =>
            guardEnv(
                {
                    KNOSSOS_DATA_DIR: "/root/.knossos",
                    CLAUDE_CONFIG_DIR: "/tmp/x",
                },
                real,
            ),
        ).toThrow(/real data dir/);
    });
    it("refuses a missing or real config dir", () => {
        expect(() => guardEnv({ KNOSSOS_DATA_DIR: "/tmp/d" }, real)).toThrow(
            /CLAUDE_CONFIG_DIR/,
        );
        expect(() =>
            guardEnv(
                {
                    KNOSSOS_DATA_DIR: "/tmp/d",
                    CLAUDE_CONFIG_DIR: "/root/.claude",
                },
                real,
            ),
        ).toThrow(/real config/);
    });
    it("refuses a path that resolves into the real dirs", () => {
        expect(() =>
            guardEnv(
                {
                    KNOSSOS_DATA_DIR: "/root/.knossos/../.knossos",
                    CLAUDE_CONFIG_DIR: "/tmp/c",
                },
                real,
            ),
        ).toThrow();
    });
    it("accepts temp dirs", () => {
        expect(() =>
            guardEnv(
                { KNOSSOS_DATA_DIR: "/tmp/d", CLAUDE_CONFIG_DIR: "/tmp/c" },
                real,
            ),
        ).not.toThrow();
    });
});

describe("cleanup", () => {
    it("runs every registered step once, last first, even when one throws", async () => {
        const seen = [];
        registerCleanup(() => seen.push("a"));
        registerCleanup(() => {
            seen.push("b");
            throw new Error("x");
        });
        await runCleanups();
        await runCleanups();
        expect(seen).toEqual(["b", "a"]);
    });
});
