#!/usr/bin/env node
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
import { constants } from "node:fs";
import {
    copyFile,
    cp,
    lstat,
    mkdir,
    mkdtemp,
    readdir,
    readFile,
    readlink,
    realpath,
    rm,
    writeFile,
} from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { clearInterval, setInterval } from "node:timers";
import { promisify } from "node:util";
import { cursor, plain, resize, send, snap, type, waitFor } from "./driver.mjs";
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
import { FIXTURE, FRAME_MS, NEVER_SHOWN, SHOTS, WAIT_MS } from "./shots.mjs";

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

/** The throwaway worktree's dir name: the pane header and Claude Code's `~/` path both show it. */
export const WORKTREE_NAME = "knossos";

/**
 * A detached worktree of HEAD at `<home>/<name>` (WORKTREE_NAME), so the pane's
 * header and Claude Code's `~/<name>` read the project's name, whatever this
 * checkout's dir is called. Its removal is registered before
 * it is added, so a signal or an error while git adds it still removes it.
 * Never `git config` in it: in a linked worktree that writes the shared
 * repository config.
 */
export async function throwawayWorktree(
    repo,
    { home, name = WORKTREE_NAME, runGit = git, copy = cp },
) {
    const dir = path.join(home, name);
    registerCleanup(async () => {
        await runGit(repo, "worktree", "remove", "--force", dir).catch(
            () => {},
        );
        await rm(dir, { recursive: true, force: true });
        await runGit(repo, "worktree", "prune").catch(() => {});
    });
    await mkdir(home, { recursive: true });
    await runGit(repo, "worktree", "add", "--detach", dir, "HEAD");
    await copyInstalledPackages(repo, dir, runGit, copy);
    return dir;
}

/**
 * Copies each `node_modules` the checkout has, tracked by git or not, into
 * the worktree at the same place. A scan types a call into a package from
 * that package's declarations, so without them the stills would show a graph
 * the checkout's own scan does not have. A copy, not a link: a scan reads
 * nothing outside the root it was given, and a shot's edit must never reach
 * the checkout. For the same reason a link inside the packages is copied only
 * when it is relative and lands inside the checkout, so its copy lands inside
 * the worktree; an absolute link (`npm link`) or one that climbs out is left
 * behind.
 */
async function copyInstalledPackages(repo, dir, runGit, copy) {
    // Every untracked path, ignored or not, with each untracked directory
    // listed once rather than file by file.
    const untracked = await runGit(
        repo,
        "ls-files",
        "-z",
        "--others",
        "--directory",
    );
    const packages = [];
    for (const entry of untracked.split("\0")) {
        if (!entry.endsWith("/")) continue;
        packages.push(...(await packageDirectories(repo, entry.slice(0, -1))));
    }
    const top = await realpath(repo);
    for (const relative of packages)
        await copy(path.join(repo, relative), path.join(dir, relative), {
            recursive: true,
            verbatimSymlinks: true,
            mode: constants.COPYFILE_FICLONE,
            filter: (source) => staysInside(source, top),
        });
}

/** The `node_modules` directories at or under `relative`, not inside one another. */
async function packageDirectories(repo, relative) {
    if (path.basename(relative) === "node_modules") return [relative];
    const entries = await readdir(path.join(repo, relative), {
        withFileTypes: true,
    }).catch(() => []);
    const found = [];
    for (const entry of entries)
        if (entry.isDirectory() && entry.name !== ".git")
            found.push(
                ...(await packageDirectories(
                    repo,
                    path.join(relative, entry.name),
                )),
            );
    return found;
}

/**
 * Anything but a link, or a link that reaches a real file or directory inside
 * `top` through relative links only. Every link on the way counts, a link in
 * the path's directories included: in the worktree each lands where it does
 * here, so one absolute hop, or one that climbs out, takes the copy outside.
 */
async function staysInside(source, top) {
    if (!(await lstat(source)).isSymbolicLink()) return true;
    const directory = await realpath(path.dirname(source));
    return reachesInside(
        path.join(directory, path.basename(source)),
        top,
        MAX_LINK_HOPS,
    );
}

const MAX_LINK_HOPS = 40;

/** Walks `absolute` one component at a time from `top`, following each link. */
async function reachesInside(absolute, top, hops) {
    const relative = path.relative(top, absolute);
    if (
        relative === ".." ||
        relative.startsWith(`..${path.sep}`) ||
        path.isAbsolute(relative)
    )
        return false;
    const parts = relative === "" ? [] : relative.split(path.sep);
    let current = top;
    for (const [index, part] of parts.entries()) {
        const next = path.join(current, part);
        const stats = await lstat(next).catch(() => null);
        // A dangling link reaches nothing here, but its copy could reach
        // whatever the worktree holds at that name.
        if (stats === null) return false;
        if (stats.isSymbolicLink()) {
            const target = await readlink(next);
            if (path.isAbsolute(target) || hops === 0) return false;
            return reachesInside(
                path.join(
                    path.resolve(current, target),
                    ...parts.slice(index + 1),
                ),
                top,
                hops - 1,
            );
        }
        current = next;
    }
    return true;
}

/** The branch origin's HEAD names (`origin/main` gives `main`), or main when there is none. */
export async function defaultBranch(dir, runGit = git) {
    const ref = await runGit(
        dir,
        "symbolic-ref",
        "--short",
        "refs/remotes/origin/HEAD",
    ).catch(() => "");
    return ref.includes("/") ? ref.slice(ref.indexOf("/") + 1) : "main";
}

const knossosBin = path.join(REPO, "bin", "knossos");

/**
 * A copy of the real graph in a fresh dir under `tmpRoot`, with the worktree
 * allowed and freshly scanned into it, for each session to copy in turn.
 * With `base`, the commit where HEAD left the default branch is scanned
 * first, so the Branch tab has a snapshot to compare with. `realDataDir` is
 * only ever read: the stage is refused inside it before anything is made.
 */
export async function stageGraph(
    worktree,
    {
        source = REAL_DATA,
        realDataDir = REAL_DATA,
        tmpRoot = os.tmpdir(),
        base = false,
        runGit = git,
        runScan = (args, options) => run(knossosBin, args, options),
    } = {},
) {
    const guard = (dir) =>
        guardEnv(
            { KNOSSOS_DATA_DIR: dir, CLAUDE_CONFIG_DIR: dir },
            { realDataDir, realConfigDir: REAL_CONFIG },
        );
    guard(path.join(tmpRoot, "knossos-capture-stage"));
    const stage = await mkdtemp(path.join(tmpRoot, "knossos-capture-stage-"));
    registerCleanup(() => rm(stage, { recursive: true, force: true }));
    guard(stage);
    const env = {
        ...process.env,
        KNOSSOS_DATA_DIR: stage,
        KNOSSOS_ROOTS_FILE: path.join(stage, "roots.json"),
    };
    await copyData(source, stage);
    const rootsFile = env.KNOSSOS_ROOTS_FILE;
    const roots = JSON.parse(
        await readFile(rootsFile, "utf8").catch(() => '{"roots":[]}'),
    );
    roots.roots = [...new Set([...(roots.roots ?? []), worktree])];
    await writeFile(rootsFile, `${JSON.stringify(roots, null, 4)}\n`);
    const scan = () =>
        runScan(["scan", worktree, "--snapshot-retention=5"], {
            env,
            maxBuffer: 64 * 1024 * 1024,
        });
    if (base) {
        const head = await runGit(worktree, "rev-parse", "HEAD");
        const fork = await runGit(
            worktree,
            "merge-base",
            "HEAD",
            await defaultBranch(worktree, runGit),
        );
        await runGit(worktree, "checkout", "--quiet", "--detach", fork);
        await scan();
        await runGit(worktree, "checkout", "--quiet", "--detach", head);
    }
    await scan();
    return stage;
}

/**
 * Puts the worktree back to HEAD between shots: an earlier shot's edit, or a
 * file it left, is not the next one's. Run only after git itself says the
 * dir is the top of a worktree, and never this checkout.
 */
async function resetTree(worktree) {
    const top = await git(worktree, "rev-parse", "--show-toplevel");
    if (top !== worktree || worktree === REPO)
        throw new Error(
            `refusing to clean ${worktree}: not the capture's worktree`,
        );
    await run("git", ["checkout", "--quiet", "--", "."], { cwd: worktree });
    await run("git", ["clean", "-fdq"], { cwd: worktree });
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

/**
 * The text in Claude Code's prompt: the last line that starts with its `❯`,
 * and the lines a long text wraps onto, up to the box's lower rule.
 */
export function promptText(screen) {
    const lines = plain(screen).split("\n");
    const at = lines.findLastIndex((l) => l.startsWith("❯"));
    if (at === -1) return "";
    const parts = [lines[at].slice(1).trim()];
    for (const line of lines.slice(at + 1)) {
        if (line.startsWith("─") || line.trim() === "") break;
        parts.push(line.trim());
    }
    return parts.filter((p) => p !== "").join(" ");
}

/**
 * Why the prompt is not ready to send `expect`, or null when it is: the
 * prompt line must read exactly `expect`; a trailing space, which no line
 * shows, is read from the cursor, which then stands one past it (the text
 * starts two columns in, after `❯ `); and nothing on screen may match `absent`.
 */
export function promptReady(screen, cursorX, expect, absent) {
    const text = promptText(screen);
    if (text !== expect.trim())
        return `the prompt reads "${text}", not "${expect.trim()}"`;
    if (expect.endsWith(" ") && cursorX !== 2 + [...expect].length)
        return `the cursor is at column ${cursorX}, not past "${expect}"`;
    if (absent !== undefined && new RegExp(absent, "m").test(plain(screen)))
        return `the screen still shows ${absent}`;
    return null;
}

/**
 * Sends a key to the pane. Should the pane lose the keyboard between the
 * check and the key, a printable key that turns up in the prompt instead is
 * taken back out, so it can never be sent as a turn. With `done`, a regex the key must
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
const QUICK = ["send", "press", "focus", "widen", "resize"];

/** Where the edit goes: `text` as a line above the first line holding `anchor`. */
export function insertBefore(content, anchor, text, file = "the file") {
    const lines = content.split("\n");
    const at = lines.findIndex((l) => l.includes(anchor));
    if (at === -1)
        throw new Error(
            `edit: no line holds "${anchor}" in ${file}; FIXTURE.editAnchor needs updating`,
        );
    lines.splice(at, 0, text);
    return lines.join("\n");
}

/** A wait's timeout, naming the FIXTURE entry its match depends on. */
function fixtureError(error, key) {
    if (key === undefined) return error;
    error.message += ` (it waits on FIXTURE.${key} = "${FIXTURE[key]}", which the graph or the source may no longer have)`;
    return error;
}

/**
 * Runs one shot's steps in its session (see shots.mjs for what each does).
 * Returns the GIF's frames (ANSI), when the shot records, and the stills by name.
 */
export async function playShot(session, shot, { worktree, record = false }) {
    const tape = recorder(session, record);
    const stills = {};
    const runStep = async (step) => {
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
            case "submit":
                await submit(session, step, runStep);
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
                await waitFor(session, test, {
                    timeoutMs: step.timeoutMs ?? 30000,
                    step: step.step,
                }).catch((error) => {
                    throw fixtureError(error, step.fixture);
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
                await writeFile(
                    file,
                    insertBefore(
                        await readFile(file, "utf8"),
                        step.before,
                        step.text,
                        step.file,
                    ),
                );
                break;
            }
            case "resize":
                await resize(session, ...step.size);
                // Until Claude Code has drawn at the new size: its prompt's rule spans the width.
                await waitFor(
                    session,
                    (s) => new RegExp(`^─{${session.cols}}$`, "m").test(s),
                    {
                        timeoutMs: 20000,
                        step: `resize to ${step.size.join("x")}`,
                    },
                );
                await settle(session);
                break;
            case "roll":
                // The GIF starts here, on a screen that has stopped drawing.
                await settle(session);
                tape.frames.splice(0);
                break;
            case "cut":
                tape.stop();
                break;
            case "still":
                // With the size it was taken at: a `resize` can change it within a shot.
                stills[step.name] = {
                    frame: checkStill(await settle(session), step.name),
                    view: {
                        cols: session.cols,
                        rows: session.rows,
                        theme: shot.theme,
                    },
                };
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
                `${step.do} ${step.step ?? step.key ?? step.text ?? step.expect ?? ""}: ${tape.mark()} frames\n`,
            );
    };
    try {
        for (const step of shot.steps) await runStep(step);
    } catch (error) {
        // What was taken before the failure, so a GIF cut before it (and its model turn) is not lost.
        error.partial = {
            frames: tape.frames,
            stills,
            cut: record && !tape.on,
        };
        throw error;
    } finally {
        tape.stop();
    }
    return { frames: tape.frames, stills };
}

/**
 * Types into the prompt with the step's own steps, and sends Enter only once
 * the prompt reads exactly what it should (see promptReady). Otherwise the
 * prompt is cleared and typed again, twice at most, and the step fails with
 * nothing sent, showing the screen that failed the check.
 */
export async function submit(session, step, runStep, { attempts = 3 } = {}) {
    let why = null;
    let seen = "";
    for (let n = 0; n < attempts; n += 1) {
        for (const sub of step.steps) await runStep(sub);
        await sleep(300);
        seen = await snap(session);
        why = promptReady(seen, await cursor(session), step.expect, step.absent);
        if (why === null) {
            await send(session, "Enter");
            return;
        }
        await send(session, "C-u");
        await sleep(300);
    }
    const error = new Error(
        `submit "${step.expect.trim()}": ${why}; Enter not sent`,
    );
    // The screen that failed the check, not the cleared prompt after it.
    error.lastFrame = plain(seen);
    throw error;
}

/** The GIF's length from the frames it was built from, refused past the shot's `maxMs`. */
export async function checkLength(dir, shot) {
    const count = (await readdir(dir)).filter((f) =>
        /^\d{5}\.png$/.test(f),
    ).length;
    const ms = count * FRAME_MS;
    if (shot.maxMs !== undefined && ms > shot.maxMs)
        throw new Error(
            `the GIF plays ${(ms / 1000).toFixed(1)} s, past ${(shot.maxMs / 1000).toFixed(0)} s: shorten the holds in shots.mjs`,
        );
    return ms;
}

/** The frames a GIF may show: none with what NEVER_SHOWN matches. */
export const shown = (frames) =>
    frames.filter((f) => !new RegExp(NEVER_SHOWN).test(plain(f)));

/** A still's frame, or an error when it shows what NEVER_SHOWN matches: a still is never dropped silently. */
export function checkStill(frame, name) {
    const hit = plain(frame).match(new RegExp(NEVER_SHOWN));
    if (hit === null) return frame;
    const error = new Error(
        `still ${name} shows "${hit[0]}", which no capture may show`,
    );
    error.lastFrame = plain(frame);
    throw error;
}

/**
 * Keeps what a failed shot took once its GIF was cut, in the shot's own
 * dir under tools/capture/out (never `--out`, which only gets a whole
 * shot), and says where. Should that fail too, it says so and returns
 * null, so the caller still throws the shot's own error.
 */
export async function keepPartial(
    name,
    shot,
    error,
    {
        view,
        write = writeShot,
        log = (line) => process.stderr.write(`${line}\n`),
    },
) {
    if (error.partial?.cut !== true) return null;
    const dir = path.join(OUT, name);
    try {
        await write(name, shot, error.partial, { out: null, view });
    } catch (writeError) {
        log(`partial ${name} not kept: ${writeError.message}`);
        return null;
    }
    log(`partial ${name} kept in ${dir} (not copied to --out)`);
    return dir;
}

/**
 * Writes a shot's GIF (from its frames, when it records) and its stills
 * into its own dir under tools/capture/out, and copies them to `out`
 * unless `out` is null.
 */
async function writeShot(name, shot, taken, { out, view }) {
    const { stills } = taken;
    const frames = shown(taken.frames);
    const dir = path.join(OUT, name);
    await rm(dir, { recursive: true, force: true });
    await mkdir(dir, { recursive: true });
    if (out !== null) await mkdir(out, { recursive: true });
    // The stills first: a GIF refused for its length must not take them with it.
    for (const [still, { frame, view: at }] of Object.entries(stills)) {
        const png = path.join(dir, `${still}.png`);
        await renderPng([framePage(frame, at)], [png], at);
        await writeFile(path.join(dir, `${still}.ansi`), frame);
        if (out === null) continue;
        await copyFile(png, path.join(out, `${still}.png`));
        process.stdout.write(`${still}: ${path.join(out, `${still}.png`)}\n`);
    }
    if (shot.gif === true && frames.length > 0) {
        const pngs = frames.map((_, i) => path.join(dir, `${pad(i)}.png`));
        // The frames as text too, to look at or rebuild without shooting again.
        for (const [i, f] of frames.entries())
            await writeFile(path.join(dir, `${pad(i)}.ansi`), f);
        await renderPng(
            frames.map((f) => framePage(f, view)),
            pngs,
            view,
        );
        const ms = await checkLength(dir, shot);
        const built = await buildGif(dir, path.join(out ?? dir, `${name}.gif`));
        process.stdout.write(
            `${name}: ${frames.length} frames (${(ms / 1000).toFixed(1)} s), ${built.bytes} bytes${built.mp4 ? `, ${built.mp4}` : ""}\n`,
        );
    }
}

async function shootOne(
    name,
    shot,
    { worktree, stage, out, run: runDir, home },
) {
    const [cols, rows] = shot.size;
    const view = { cols, rows, theme: shot.theme };
    await resetTree(worktree);
    const session = await createSession({
        project: worktree,
        dataDir: stage,
        ...view,
        pluginDir: path.join(REPO, ".plugin"),
        tmpRoot: runDir,
        // The worktree lies in it, so the header names the project `~/knossos`.
        home,
        ...(shot.claude ? { claudeArgs: shot.claude } : {}),
    });
    try {
        let taken;
        try {
            taken = await playShot(session, shot, {
                worktree,
                record: shot.gif === true,
            });
        } catch (error) {
            // A GIF already cut is kept, with the stills taken so far: shooting it again costs a model turn.
            await keepPartial(name, shot, error, { view });
            throw error;
        }
        await writeShot(name, shot, taken, { out, view });
    } finally {
        await session.close();
    }
}

export async function main(argv = process.argv.slice(2), shots = SHOTS) {
    const { only, out } = parseArgs(argv, shots);
    installExitHandlers();
    let current = null;
    try {
        // One temp dir holds the run: HOME with the worktree in it, the stage and every session.
        const runDir = await mkdtemp(
            path.join(os.tmpdir(), "knossos-capture-run-"),
        );
        registerCleanup(() => rm(runDir, { recursive: true, force: true }));
        const home = path.join(runDir, "home");
        const worktree = await throwawayWorktree(REPO, { home });
        const base = only.some((s) => shots[s].base === true);
        const stage = await stageGraph(worktree, { base, tmpRoot: runDir });
        for (const name of only) {
            current = name;
            await shootOne(name, shots[name], {
                worktree,
                stage,
                out,
                run: runDir,
                home,
            });
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
