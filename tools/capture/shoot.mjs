/**
 * Dev-only. Shoots the README and docs captures: the hero GIF and the pane
 * stills, each in its own isolated Claude Code session (see session.mjs).
 *
 *     node tools/capture/shoot.mjs [--only=hero,overview] [--out=docs/images/claude-code]
 *
 * The sessions never run in this checkout: the hero's model turn edits a
 * file, so they run in a throwaway git worktree of HEAD, scanned into a staged
 * copy of the graph before the first session starts. The stage, the worktree
 * and every session are removed however the run ends. CAPTURE_TRACE=1 prints
 * each step with the frame count reached, for timing the GIF.
 */
import { execFile } from "node:child_process";
import {
    copyFile,
    mkdir,
    mkdtemp,
    readFile,
    rm,
    writeFile,
} from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { clearInterval, setInterval } from "node:timers";
import { promisify } from "node:util";
import { plain, send, snap, type, waitFor } from "./driver.mjs";
import { framePage } from "./frame.mjs";
import { buildGif } from "./gif.mjs";
import { renderPng } from "./render.mjs";
import {
    copyData,
    createSession,
    guardEnv,
    installExitHandlers,
    registerCleanup,
    runCleanups,
} from "./session.mjs";
import { FRAME_MS, SHOTS, WAIT_MS } from "./shots.mjs";

const run = promisify(execFile);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, "..", "..");
const OUT = path.join(HERE, "out");
const REAL_DATA = path.join(os.homedir(), ".knossos");
const REAL_CONFIG = path.join(os.homedir(), ".claude");

/** `--only=a,b` and `--out=dir`; every shot and docs/images/claude-code when left out. */
export function parseArgs(argv, shots = SHOTS) {
    let only = Object.keys(shots);
    let out = path.join(REPO, "docs", "images", "claude-code");
    for (const arg of argv) {
        const [key, value = ""] = arg.split(/=(.*)/s);
        if (key === "--only") {
            only = value.split(",").filter((s) => s !== "");
            const unknown = only.filter((s) => !Object.hasOwn(shots, s));
            if (unknown.length > 0)
                throw new Error(`unknown shot: ${unknown.join(", ")}`);
        } else if (key === "--out") {
            out = path.resolve(value);
        } else {
            throw new Error(`unknown option: ${arg}`);
        }
    }
    return { only, out };
}

const git = (dir, ...args) =>
    run("git", ["-C", dir, ...args]).then(({ stdout }) => stdout.trim());

/**
 * A detached worktree of HEAD, named like this checkout so the pane's header
 * reads the same. Its removal is registered before it is added, so a signal
 * while git adds it still removes it. Never `git config` in it: in a linked
 * worktree that writes the shared repository config.
 */
export async function throwawayWorktree(repo = REPO) {
    const parent = await mkdtemp(
        path.join(os.tmpdir(), "knossos-capture-tree-"),
    );
    const dir = path.join(parent, path.basename(repo));
    registerCleanup(async () => {
        await git(repo, "worktree", "remove", "--force", dir).catch(() => {});
        await rm(parent, { recursive: true, force: true });
        await git(repo, "worktree", "prune").catch(() => {});
    });
    await git(repo, "worktree", "add", "--detach", dir, "HEAD");
    return dir;
}

/**
 * A copy of the real graph with the worktree allowed and freshly scanned
 * into it, for each session to copy in turn. With `base`, the commit where
 * HEAD left main is scanned first, so the Branch tab has a snapshot to
 * compare with. The real data dir is only read.
 */
export async function stageGraph(
    worktree,
    { source = REAL_DATA, base = false } = {},
) {
    const stage = await mkdtemp(
        path.join(os.tmpdir(), "knossos-capture-stage-"),
    );
    registerCleanup(() => rm(stage, { recursive: true, force: true }));
    const env = {
        ...process.env,
        KNOSSOS_DATA_DIR: stage,
        KNOSSOS_ROOTS_FILE: path.join(stage, "roots.json"),
    };
    guardEnv(
        { ...env, CLAUDE_CONFIG_DIR: stage },
        { realDataDir: source, realConfigDir: REAL_CONFIG },
    );
    await copyData(source, stage);
    const rootsFile = env.KNOSSOS_ROOTS_FILE;
    const roots = JSON.parse(
        await readFile(rootsFile, "utf8").catch(() => '{"roots":[]}'),
    );
    roots.roots = [...new Set([...(roots.roots ?? []), worktree])];
    await writeFile(rootsFile, `${JSON.stringify(roots, null, 4)}\n`);
    const scan = () =>
        run(
            path.join(REPO, "bin", "knossos"),
            ["scan", worktree, "--snapshot-retention=5"],
            { env, maxBuffer: 64 * 1024 * 1024 },
        );
    if (base) {
        const head = await git(worktree, "rev-parse", "HEAD");
        const fork = await git(worktree, "merge-base", "HEAD", "main");
        await git(worktree, "checkout", "--quiet", "--detach", fork);
        await scan();
        await git(worktree, "checkout", "--quiet", "--detach", head);
    }
    await scan();
    return stage;
}

const pad = (n) => String(n).padStart(5, "0");

// eslint-disable-next-line no-control-regex -- matching escape sequences is the point
const SGR = /\u001b\[([0-9;]*)m/gy;

/**
 * Whether the pane has the keyboard: Claude Code draws a plugin pane's left
 * border dim while the prompt has it, and in the accent colour when the pane
 * does. Read from the first row, at the first `│`.
 */
export function paneFocused(ansi) {
    const row = ansi.split("\n")[0] ?? "";
    let dim = false;
    for (let i = 0; i < row.length;) {
        SGR.lastIndex = i;
        const m = SGR.exec(row);
        if (m !== null) {
            const codes = m[1] === "" ? ["0"] : m[1].split(";");
            for (let c = 0; c < codes.length; c += 1) {
                const code = codes[c];
                // 38, 48 and 58 carry a colour: `5;n` or `2;r;g;b`, never a dim.
                if (["38", "48", "58"].includes(code))
                    c += codes[c + 1] === "5" ? 2 : 4;
                else if (code === "0" || code === "22") dim = false;
                else if (code === "2") dim = true;
            }
            i = SGR.lastIndex;
            continue;
        }
        if (row[i] === "│") return !dim;
        i += 1;
    }
    return false;
}

/** The pane's left border column, from the first row's plain text. */
const borderColumn = (ansi) => [...plain(ansi).split("\n")[0]].indexOf("│");

async function cursor(session) {
    const { stdout } = await run("tmux", [
        "display-message",
        "-p",
        "-t",
        session.name,
        "#{cursor_x}",
    ]);
    return Number(stdout.trim());
}

/**
 * Gives the pane the keyboard with ctrl+x tab, which Claude Code toggles: it
 * is sent only while the pane (or, with `field`, a field in it, where the
 * terminal cursor then stands) does not have it, and checked again after.
 */
async function focusPane(session, { field = false, prompt = false } = {}) {
    const has = async () => {
        const frame = await snap(session);
        if (prompt) return !paneFocused(frame);
        if (!field) return paneFocused(frame);
        return (await cursor(session)) > borderColumn(frame);
    };
    for (let attempt = 0; attempt < 4; attempt += 1) {
        if (await has()) return;
        if (prompt) {
            // Escape is the one key a pane cannot keep: it hands the keyboard back.
            await send(session, "Escape");
        } else {
            await send(session, "C-x");
            await sleep(100);
            await send(session, "Tab");
        }
        const end = Date.now() + 1500;
        while (Date.now() < end) {
            await sleep(150);
            if (await has()) return;
        }
    }
    const error = new Error(
        prompt
            ? "the prompt never got the keyboard back"
            : `the pane never got the keyboard${field ? " in its field" : ""}`,
    );
    error.lastFrame = plain(await snap(session));
    throw error;
}

/** The text in Claude Code's prompt: the last line that starts with its `❯`. */
export function promptText(screen) {
    const line = plain(screen)
        .split("\n")
        .filter((l) => l.startsWith("❯"))
        .at(-1);
    return line === undefined ? "" : line.slice(1).trim();
}

/**
 * Sends a key to the pane. The pane can lose the keyboard between the check
 * and the key (it does as it redraws), so a printable key that turns up in
 * the prompt instead is taken back out. With `done`, a regex the key must
 * bring on screen, a key that changed nothing is sent again after the
 * keyboard went back to the prompt (Escape) and into the pane afresh, since
 * something else above the prompt (the band's buttons) can hold it.
 */
async function pressKey(session, keys, done) {
    const printable = keys.length === 1 && [...keys[0]].length === 1;
    const reached = done === undefined ? null : new RegExp(done, "m");
    for (let attempt = 0; attempt < 4; attempt += 1) {
        if (attempt > 0 && reached !== null) {
            await focusPane(session, { prompt: true });
            await sleep(250);
        }
        await focusPane(session);
        const before = promptText(await snap(session));
        await send(session, ...keys);
        await sleep(250);
        if (printable && promptText(await snap(session)) !== before) {
            await send(session, "BSpace");
            await sleep(250);
            continue;
        }
        if (reached === null) return;
        const end = Date.now() + 3000;
        while (Date.now() < end) {
            if (reached.test(plain(await snap(session)))) return;
            await sleep(200);
        }
    }
    const error = new Error(`the pane never took the key ${keys.join(" ")}`);
    error.lastFrame = plain(await snap(session));
    throw error;
}

/** Widens the pane four columns a press until it is at least `columns` wide (border included). */
async function widen(session, columns) {
    for (let n = 0; n < 40; n += 1) {
        const frame = await snap(session);
        if (session.cols - borderColumn(frame) >= columns) return;
        await focusPane(session);
        await send(session, "C-x", "Left");
        await sleep(300);
    }
    const error = new Error(`the pane never got ${columns} columns wide`);
    error.lastFrame = plain(await snap(session));
    throw error;
}

/** Waits until two snaps 600 ms apart are the same: nothing on screen is still moving. */
async function settle(session, { timeoutMs = 15000 } = {}) {
    const end = Date.now() + timeoutMs;
    let before = await snap(session);
    while (Date.now() < end) {
        await sleep(600);
        const now = await snap(session);
        if (plain(now) === plain(before)) return now;
        before = now;
    }
    return before;
}

/** At most `n` of `frames`, evenly spread and keeping the last. */
export function lapse(frames, n) {
    if (frames.length <= n) return frames;
    return Array.from(
        { length: n },
        (_, i) => frames[Math.round(((i + 1) * (frames.length - 1)) / n)],
    );
}

/**
 * Records a frame every FRAME_MS while it runs: a GIF plays in real time.
 * A hold pauses it and adds copies of the screen, and a wait keeps only a
 * time-lapse of how long it took.
 */
function recorder(session, on) {
    const frames = [];
    let timer = null;
    const start = () => {
        if (!on || timer !== null) return;
        timer = setInterval(() => {
            void snap(session)
                .then((f) => frames.push(f))
                .catch(() => {});
        }, FRAME_MS);
    };
    const stop = () => {
        if (timer !== null) clearInterval(timer);
        timer = null;
    };
    start();
    return {
        frames,
        get on() {
            return on;
        },
        stop() {
            stop();
            on = false;
        },
        async hold(ms) {
            if (!on) return sleep(ms);
            stop();
            const last = await snap(session);
            for (let t = 0; t < ms; t += FRAME_MS) frames.push(last);
            start();
        },
        mark: () => frames.length,
        squeeze(from, n) {
            frames.splice(
                from,
                frames.length - from,
                ...lapse(frames.slice(from), n),
            );
        },
    };
}

const until = (session, step, re) =>
    waitFor(session, (s) => new RegExp(re, "m").test(s), {
        timeoutMs: 20000,
        step: `${step} until ${re}`,
    });

/** Steps a GIF shows only the outcome of. */
const QUICK = ["send", "press", "focus", "widen"];

/**
 * Runs one shot's steps in its session (see shots.mjs for what each does).
 * Returns the GIF's frames (ANSI), when the shot records, and the stills by name.
 */
export async function playShot(session, shot, { worktree, record = false }) {
    const tape = recorder(session, record);
    const stills = {};
    try {
        for (const step of shot.steps) {
            const from = tape.mark();
            switch (step.do) {
                case "type":
                    if (step.delayMs) {
                        // By words for a long text, as a fast typist's bursts; by keys for a short one.
                        const parts =
                            step.words === true
                                ? step.text.split(/(?<= )/)
                                : [...step.text];
                        for (const part of parts) {
                            await type(session, part);
                            await sleep(step.delayMs);
                        }
                    } else {
                        await type(session, step.text);
                    }
                    break;
                case "send":
                    await send(session, ...step.keys);
                    break;
                case "press":
                    await pressKey(
                        session,
                        Array.isArray(step.key) ? step.key : [step.key],
                        step.until,
                    );
                    break;
                case "focus":
                    await focusPane(session, {
                        field: step.field === true,
                        prompt: step.prompt === true,
                    });
                    break;
                case "widen":
                    await widen(session, step.columns);
                    break;
                case "wait": {
                    const re = new RegExp(step.match, "m");
                    const test =
                        step.absent === true
                            ? (s) => !re.test(s)
                            : (s) => re.test(s);
                    const from = tape.mark();
                    await waitFor(session, test, {
                        timeoutMs: step.timeoutMs ?? 30000,
                        step: step.step,
                    });
                    tape.squeeze(from, step.lapse ?? 4);
                    await sleep(WAIT_MS);
                    break;
                }
                case "hold":
                    await tape.hold(step.ms);
                    break;
                case "edit": {
                    const file = path.join(worktree, step.file);
                    const lines = (await readFile(file, "utf8")).split("\n");
                    lines.splice(step.line - 1, 0, step.text);
                    await writeFile(file, lines.join("\n"));
                    break;
                }
                case "cut":
                    tape.stop();
                    break;
                case "still":
                    stills[step.name] = await settle(session);
                    break;
                default:
                    throw new Error(`unknown step: ${step.do}`);
            }
            if (step.until !== undefined && step.do !== "press")
                await until(session, step.do, step.until);
            // A key and its checks show as their outcome, not as the time the checks took.
            if (QUICK.includes(step.do)) tape.squeeze(from, 3);
            if (process.env.CAPTURE_TRACE)
                process.stderr.write(
                    `${step.do} ${step.step ?? step.key ?? step.text ?? ""}: ${tape.mark()} frames\n`,
                );
        }
    } finally {
        tape.stop();
    }
    return { frames: tape.frames, stills };
}

async function shootOne(name, shot, { worktree, stage, out }) {
    const [cols, rows] = shot.size;
    const view = { cols, rows, theme: shot.theme };
    // A fresh tree for every shot: an earlier shot's edit is not this one's.
    await git(worktree, "checkout", "--quiet", "--", ".");
    const session = await createSession({
        project: worktree,
        dataDir: stage,
        ...view,
        pluginDir: path.join(REPO, ".plugin"),
        // The header then names the project `~/Knossos-MCP`.
        home: path.dirname(worktree),
        ...(shot.claude ? { claudeArgs: shot.claude } : {}),
    });
    try {
        const record = shot.gif === true;
        const { frames, stills } = await playShot(session, shot, {
            worktree,
            record,
        });
        await session.close();
        const dir = path.join(OUT, name);
        await rm(dir, { recursive: true, force: true });
        await mkdir(dir, { recursive: true });
        await mkdir(out, { recursive: true });
        if (record) {
            const pngs = frames.map((_, i) => path.join(dir, `${pad(i)}.png`));
            // The frames as text too, to look at or rebuild without shooting again.
            for (const [i, f] of frames.entries())
                await writeFile(path.join(dir, `${pad(i)}.ansi`), f);
            await renderPng(
                frames.map((f) => framePage(f, view)),
                pngs,
                view,
            );
            const built = await buildGif(dir, path.join(out, `${name}.gif`));
            process.stdout.write(
                `${name}: ${frames.length} frames (${((frames.length * FRAME_MS) / 1000).toFixed(1)} s), ${built.bytes} bytes${built.mp4 ? `, ${built.mp4}` : ""}\n`,
            );
        }
        const named = Object.entries(stills);
        if (named.length > 0) {
            const pngs = named.map(([still]) => path.join(dir, `${still}.png`));
            await renderPng(
                named.map(([, f]) => framePage(f, view)),
                pngs,
                view,
            );
            for (const [i, [still, f]] of named.entries()) {
                await writeFile(path.join(dir, `${still}.ansi`), f);
                await copyFile(pngs[i], path.join(out, `${still}.png`));
                process.stdout.write(
                    `${still}: ${path.join(out, `${still}.png`)}\n`,
                );
            }
        }
    } finally {
        await session.close();
    }
}

export async function main(argv = process.argv.slice(2)) {
    const { only, out } = parseArgs(argv);
    installExitHandlers();
    let current = null;
    try {
        const worktree = await throwawayWorktree();
        const base = only.some((s) => SHOTS[s].base === true);
        const stage = await stageGraph(worktree, { base });
        for (const name of only) {
            current = name;
            await shootOne(name, SHOTS[name], { worktree, stage, out });
        }
        return 0;
    } catch (error) {
        process.stderr.write(`${error.message}\n`);
        if (error.lastFrame !== undefined && current !== null) {
            await mkdir(OUT, { recursive: true });
            const file = path.join(OUT, `${current}-failed.txt`);
            await writeFile(file, error.lastFrame);
            process.stderr.write(`last frame: ${file}\n`);
        }
        return 1;
    } finally {
        await runCleanups();
    }
}

if (
    process.argv[1] !== undefined &&
    import.meta.url === pathToFileURL(process.argv[1]).href
) {
    process.exitCode = await main();
}
