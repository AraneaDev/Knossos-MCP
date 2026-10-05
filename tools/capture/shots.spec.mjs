import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";
import { TABS } from "../../hooks/lib/layout.ts";
import {
    checkStill,
    keepPartial,
    lapse,
    paneFocused,
    parseArgs,
    promptText,
    shown,
} from "./shoot.mjs";
import {
    NEVER_SHOWN,
    SHOTS,
    STEP_KINDS,
    THIS_SESSION,
    WAIT_BUDGET_MS,
    FIXTURE,
} from "./shots.mjs";

const entries = Object.entries(SHOTS);

describe("SHOTS", () => {
    it("takes a still of every tab past the first two, pressing the key layout.ts gives it", () => {
        const pressed = new Set(
            entries.flatMap(([, shot]) =>
                shot.steps.filter((s) => s.do === "press").map((s) => s.key),
            ),
        );
        for (const tab of TABS.slice(2))
            expect(pressed, tab.id).toContain(tab.hotkey);
    });
    it("shoots the Issues tab by its key, on the over-budget rule the repository's budgets set", () => {
        const issues = TABS.find((t) => t.id === "issues");
        const shot = SHOTS.issues;
        expect(shot.steps).toContainEqual({ do: "press", key: issues.hotkey });
        const wait = shot.steps.find(
            (s) => s.do === "wait" && s.fixture === "overBudget",
        );
        expect(new RegExp(wait.match).test(FIXTURE.overBudget)).toBe(true);
        const budgets = JSON.parse(
            readFileSync(
                new URL("../../maintainability-budgets.json", import.meta.url),
                "utf8",
            ),
        );
        expect(FIXTURE.overBudget).toBe(
            `functions over ${budgets.max_php_function_lines} lines`,
        );
        expect(shot.steps.at(-1)).toEqual({ do: "still", name: "issues" });
    });
    it("gives every shot a terminal size and a theme Claude Code has", () => {
        for (const [, shot] of entries) {
            expect(shot.size).toHaveLength(2);
            const [cols, rows] = shot.size;
            expect(Number.isInteger(cols) && cols >= 80 && cols <= 300).toBe(
                true,
            );
            expect(Number.isInteger(rows) && rows >= 24 && rows <= 100).toBe(
                true,
            );
            expect(["dark", "light"]).toContain(shot.theme);
        }
    });
    it("uses only the step kinds shoot.mjs knows", () => {
        for (const [, shot] of entries) {
            for (const step of shot.steps)
                expect(STEP_KINDS).toContain(step.do);
        }
    });
    it("names every wait and gives it a pattern", () => {
        for (const [, shot] of entries) {
            for (const step of shot.steps.filter((s) => s.do === "wait")) {
                expect(typeof step.step).toBe("string");
                expect(step.step).not.toBe("");
                expect(typeof step.match).toBe("string");
                expect(() => new RegExp(step.match, "m")).not.toThrow();
            }
        }
    });
    it("ends every still shot on a still, and names each still once", () => {
        const names = entries.flatMap(([, shot]) =>
            shot.steps.filter((s) => s.do === "still").map((s) => s.name),
        );
        expect(new Set(names).size).toBe(names.length);
        for (const [, shot] of entries.filter(([, s]) => s.gif !== true)) {
            expect(shot.steps.at(-1).do).toBe("still");
        }
    });
    it("keeps the hero's recorded part, with no still in it, between 20 and 30 seconds", () => {
        const { steps } = SHOTS.hero;
        const end = steps.findIndex((s) => s.do === "cut" || s.do === "still");
        const run = end === -1 ? steps : steps.slice(0, end);
        expect(run.some((s) => s.do === "still")).toBe(false);
        const ms = run.reduce(
            (sum, s) =>
                sum +
                (s.do === "hold" ? s.ms : 0) +
                (s.do === "wait" ? WAIT_BUDGET_MS : 0),
            0,
        );
        expect(ms).toBeGreaterThanOrEqual(20000);
        expect(ms).toBeLessThanOrEqual(30000);
    });
    it("shoots the light theme for the overview and the hubs detail", () => {
        expect(SHOTS["overview-light"].theme).toBe("light");
        expect(SHOTS["hubs-detail-light"].theme).toBe("light");
    });
});

describe("parseArgs", () => {
    it("reads --only and --out, and refuses an unknown shot or option", () => {
        const { only, out } = parseArgs(["--only=hero,overview", "--out=x/y"]);
        expect(only).toEqual(["hero", "overview"]);
        expect(out.endsWith("x/y")).toBe(true);
        expect(() => parseArgs(["--only=nope"])).toThrow(/unknown shot/);
        expect(() => parseArgs(["--fast"])).toThrow(/unknown option/);
    });
});

describe("paneFocused", () => {
    it("reads the pane's border: dim while the prompt has the keyboard, coloured when the pane has it", () => {
        const dim = "   \u001b[2m\u001b[48;2;38;38;38m│\u001b[0m  ✕";
        const lit =
            "   \u001b[38;2;177;185;249m\u001b[48;2;38;38;38m│\u001b[39m  ✕";
        expect(paneFocused(`${dim}\nrest`)).toBe(false);
        expect(paneFocused(`${lit}\nrest`)).toBe(true);
        expect(paneFocused("\u001b[2mdim\u001b[22m │")).toBe(true);
        expect(paneFocused("no pane here")).toBe(false);
    });
});

describe("lapse", () => {
    it("keeps at most n frames, evenly spread, ending on the last", () => {
        const frames = Array.from({ length: 40 }, (_, i) => i);
        const kept = lapse(frames, 4);
        expect(kept).toHaveLength(4);
        expect(kept.at(-1)).toBe(39);
        expect(lapse([1, 2], 4)).toEqual([1, 2]);
    });
});

describe("promptText", () => {
    it("reads the last prompt line, without its marker", () => {
        const screen = "❯ /knossos\n  ⎿  opened\n────\n❯ 6\n────\n  footer";
        expect(promptText(screen)).toBe("6");
        expect(promptText("no prompt")).toBe("");
    });
});

describe("THIS_SESSION", () => {
    const re = new RegExp(THIS_SESSION, "m");
    it("needs one scan or more from the session itself", () => {
        expect(re.test("scans ● 1 this session")).toBe(true);
        expect(re.test("scans ●● 12 this session · 1 outside")).toBe(true);
        expect(re.test("scans ● 0 this session · 1 outside")).toBe(false);
        expect(re.test("scans ●● 10 outside · 0 this session")).toBe(false);
    });
    it("takes the Changes still in the hero's session, after its turn", () => {
        const steps = SHOTS.hero.steps;
        const turn = steps.findIndex((s) => s.do === "submit");
        const still = steps.findIndex(
            (s) => s.do === "still" && s.name === "changes-diff",
        );
        expect(turn).toBeGreaterThan(-1);
        expect(still).toBeGreaterThan(turn);
        expect(
            steps.slice(turn, still).some((s) => s.match === THIS_SESSION),
        ).toBe(true);
    });
});

describe("shown", () => {
    it("leaves out a frame with the spinner's token count, and keeps the rest", () => {
        const frames = [
            "✶ Churning… (2s · \u001b[2m↓ 82 tokens)\u001b[0m",
            "· Wibbling… (12s · ↑ 1.2k tokens)",
            "✻ Brewed for 6s · done",
            "knossos · 1 file → 2 dependents",
        ];
        expect(shown(frames)).toEqual(frames.slice(2));
    });
});

describe("NEVER_SHOWN", () => {
    const re = new RegExp(NEVER_SHOWN);
    it("matches the spinner's token counts", () => {
        for (const text of [
            "· Wibbling… (2s · ↓ 82 tokens)",
            "✶ Churning… (12s · ↑ 1,204 tokens)",
            "(40s · ↓1.2k tokens)",
            "(1m 3s · 2.4k tokens)",
        ])
            expect(re.test(text), text).toBe(true);
    });
    it("leaves a plain count in the pane alone", () => {
        for (const text of [
            "budget: 12 tokens left",
            "8,000 tokens per brief",
            "tokens: 12",
        ])
            expect(re.test(text), text).toBe(false);
    });
});

describe("checkStill", () => {
    it("returns a clean still as it is", () => {
        const frame = "knossos · 12 tokens in the brief";
        expect(checkStill(frame, "band")).toBe(frame);
    });
    it("fails a still with the spinner's token count, naming it and keeping the frame", () => {
        const frame = "\u001b[2m· Wibbling… (2s · ↓ 82 tokens)\u001b[0m";
        let error = null;
        try {
            checkStill(frame, "changes-diff");
        } catch (e) {
            error = e;
        }
        expect(error?.message).toMatch(
            /still changes-diff shows "↓ 82 tokens"/,
        );
        expect(error?.lastFrame).toBe("· Wibbling… (2s · ↓ 82 tokens)");
    });
});

describe("keepPartial", () => {
    const shot = { gif: true };
    const view = { cols: 160, rows: 48, theme: "dark" };
    const failed = (partial) =>
        Object.assign(new Error('step "band" timed out'), {
            lastFrame: "the last frame",
            partial,
        });
    it("writes a cut shot's partial to its own dir only, never to --out, and says where", async () => {
        const writes = [];
        const lines = [];
        const error = failed({ frames: ["a"], stills: {}, cut: true });
        const dir = await keepPartial("hero", shot, error, {
            view,
            write: async (...args) => writes.push(args),
            log: (line) => lines.push(line),
        });
        expect(writes).toHaveLength(1);
        expect(writes[0][3]).toEqual({ out: null, view });
        expect(dir).toMatch(/tools\/capture\/out\/hero$/);
        expect(lines).toEqual([
            `partial hero kept in ${dir} (not copied to --out)`,
        ]);
    });
    it("writes nothing for a shot that failed before its cut", async () => {
        const writes = [];
        const error = failed({ frames: ["a"], stills: {}, cut: false });
        expect(
            await keepPartial("hero", shot, error, {
                view,
                write: async (...args) => writes.push(args),
                log: () => {},
            }),
        ).toBeNull();
        expect(writes).toEqual([]);
    });
    it("leaves the shot's own error as it was when keeping the partial fails too", async () => {
        const lines = [];
        const error = failed({ frames: ["a"], stills: {}, cut: true });
        await expect(
            keepPartial("hero", shot, error, {
                view,
                write: async () => {
                    throw new Error("disk full");
                },
                log: (line) => lines.push(line),
            }),
        ).resolves.toBeNull();
        expect(error.message).toBe('step "band" timed out');
        expect(error.lastFrame).toBe("the last frame");
        expect(lines).toEqual(["partial hero not kept: disk full"]);
    });
});
