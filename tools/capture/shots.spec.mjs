import { describe, expect, it } from "vitest";
import { lapse, paneFocused, parseArgs, promptText } from "./shoot.mjs";
import { SHOTS, STEP_KINDS, THIS_SESSION, WAIT_BUDGET_MS } from "./shots.mjs";

const entries = Object.entries(SHOTS);

describe("SHOTS", () => {
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
