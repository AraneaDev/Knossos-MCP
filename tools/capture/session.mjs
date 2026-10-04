/**
 * Dev-only. Runs Claude Code in an isolated tmux session for the README captures.
 *
 * Isolated means: its own CLAUDE_CONFIG_DIR (a temp dir holding a copy of the
 * credentials and pre-seeded first-run answers, so none of the user's
 * settings, status line, plugins or MCP servers load), and its own
 * KNOSSOS_DATA_DIR (a temp copy of the graph). Nothing is ever written to the
 * user's real config or data dir; guardEnv refuses a session that would.
 */
import { execFile } from "node:child_process";
import { randomBytes } from "node:crypto";
import { realpathSync } from "node:fs";
import {
    cp,
    mkdir,
    mkdtemp,
    readdir,
    readFile,
    rm,
    writeFile,
} from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { backup, DatabaseSync } from "node:sqlite";
import { promisify } from "node:util";

const run = promisify(execFile);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const cleanups = [];

export function registerCleanup(fn) {
    cleanups.push(fn);
}

/** Runs every registered step once, last registered first; a step that throws does not stop the rest. */
export async function runCleanups() {
    while (cleanups.length > 0) {
        const fn = cleanups.pop();
        try {
            await fn();
        } catch (error) {
            process.stderr.write(`cleanup: ${error.message}\n`);
        }
    }
}

/**
 * A handler that runs the cleanups once and then exits with `code`. A second
 * call while the first is still cleaning up (a repeated Ctrl-C, an error
 * thrown from a cleanup step) is ignored.
 */
export function exitHandler(
    code,
    {
        exit = (c) => process.exit(c),
        log = (m) => process.stderr.write(m),
    } = {},
) {
    let busy = false;
    return (reason) => {
        if (busy) return undefined;
        busy = true;
        if (reason instanceof Error) log(`${reason.stack ?? reason.message}\n`);
        return runCleanups().finally(() => exit(code));
    };
}

let installed = false;

/** Makes sure a signal, an uncaught exception or an unhandled rejection still leaves nothing behind. */
export function installExitHandlers() {
    if (installed) return;
    installed = true;
    process.on("SIGINT", exitHandler(130));
    process.on("SIGTERM", exitHandler(143));
    const fatal = exitHandler(1);
    process.on("uncaughtException", fatal);
    process.on("unhandledRejection", fatal);
}

/** The real path of `p`: its deepest existing ancestor resolved through any links, plus the rest. */
function real(p) {
    const absolute = path.resolve(p);
    const rest = [];
    let probe = absolute;
    for (;;) {
        try {
            return path.join(realpathSync.native(probe), ...rest);
        } catch {
            const parent = path.dirname(probe);
            if (parent === probe) return absolute;
            rest.unshift(path.basename(probe));
            probe = parent;
        }
    }
}

const inside = (p, root) => {
    const r = path.relative(real(root), real(p));
    return r === "" || (!r.startsWith("..") && !path.isAbsolute(r));
};

/**
 * Throws unless both the data dir and the config dir are set and lie outside
 * the real ones, compared by real path, so a link into either is refused too.
 */
export function guardEnv(env, { realDataDir, realConfigDir }) {
    if (!env.KNOSSOS_DATA_DIR || inside(env.KNOSSOS_DATA_DIR, realDataDir)) {
        throw new Error(`refusing the real data dir: ${env.KNOSSOS_DATA_DIR}`);
    }
    if (!env.CLAUDE_CONFIG_DIR)
        throw new Error("CLAUDE_CONFIG_DIR must be set to a temp dir");
    if (inside(env.CLAUDE_CONFIG_DIR, realConfigDir)) {
        throw new Error(
            `refusing the real config dir: ${env.CLAUDE_CONFIG_DIR}`,
        );
    }
}

const DATABASE = /\.(sqlite|db)$/;
const DATABASE_SIDECAR = /\.(sqlite|db)-(wal|shm|journal)$/;

/**
 * Copies a data dir for a capture. Each database is copied through SQLite's
 * online backup from a read-only connection, so the copy is one consistent
 * snapshot even while the user's watcher writes; its -wal and -shm files are
 * never copied as files. The rest is copied as is, except the watch locks
 * (they name the user's live watcher, which the copy must never mistake for
 * its own), the backups and the logs.
 */
export async function copyData(source, dest) {
    const databases = [];
    await cp(source, dest, {
        recursive: true,
        filter: (from) => {
            const rel = path.relative(source, from);
            if (rel === "") return true;
            if (["watch", "backups"].includes(rel.split(path.sep)[0]))
                return false;
            if (rel.endsWith(".log") || DATABASE_SIDECAR.test(rel))
                return false;
            if (DATABASE.test(rel)) {
                databases.push(rel);
                return false;
            }
            return true;
        },
    });
    for (const rel of databases) {
        await mkdir(path.dirname(path.join(dest, rel)), { recursive: true });
        const db = new DatabaseSync(path.join(source, rel), { readOnly: true });
        try {
            // One step: a multi-step backup restarts whenever another connection writes in between.
            await backup(db, path.join(dest, rel), { rate: -1 });
        } finally {
            db.close();
        }
    }
}

/**
 * The PIDs of processes (the shared watcher, chiefly) started against this
 * data copy, known by the exact KNOSSOS_DATA_DIR in their own environment.
 * The watcher's command line names only the project, which the user's own
 * watcher shares, so it is never matched on that. The lock state file can
 * name the holder too, but it is written only at the first heartbeat.
 */
export async function ownWatchers(
    dataCopy,
    { procRoot = "/proc", self = process.pid } = {},
) {
    const mark = `KNOSSOS_DATA_DIR=${dataCopy}`;
    const pids = [];
    for (const entry of await readdir(procRoot).catch(() => [])) {
        if (!/^\d+$/.test(entry)) continue;
        const pid = Number(entry);
        if (pid <= 1 || pid === self) continue;
        const environ = await readFile(
            path.join(procRoot, entry, "environ"),
            "utf8",
        ).catch(() => "");
        if (environ.split("\0").includes(mark)) pids.push(pid);
    }
    return pids;
}

const alive = (pid) => {
    try {
        process.kill(pid, 0);
        return true;
    } catch {
        return false;
    }
};

const signal = (pid, name) => {
    try {
        process.kill(pid, name);
    } catch {
        // Gone already.
    }
};

/** SIGTERM to every process started against the copy, then SIGKILL to any of them still there after `graceMs`. */
export async function stopWatchers(dataCopy, graceMs = 3000) {
    const pids = await ownWatchers(dataCopy);
    for (const pid of pids) signal(pid, "SIGTERM");
    const end = Date.now() + graceMs;
    while (pids.some(alive) && Date.now() < end) await sleep(100);
    // Matched again, so a PID reused in the meantime is never hit.
    const left = new Set(await ownWatchers(dataCopy));
    for (const pid of pids.filter((p) => left.has(p))) signal(pid, "SIGKILL");
}

/** A tmux session name no leftover session can share: this PID plus a random suffix. */
export const sessionName = (pid = process.pid) =>
    `knossos-capture-${pid}-${randomBytes(4).toString("hex")}`;

export async function createSession({
    project,
    dataDir,
    cols,
    rows,
    theme,
    pluginDir,
}) {
    installExitHandlers();
    const base = await mkdtemp(path.join(os.tmpdir(), "knossos-capture-"));
    registerCleanup(() => rm(base, { recursive: true, force: true }));
    const configDir = path.join(base, "config");
    const dataCopy = path.join(base, "data");
    const env = {
        ...process.env,
        CLAUDE_CONFIG_DIR: configDir,
        KNOSSOS_DATA_DIR: dataCopy,
        KNOSSOS_ROOTS_FILE: path.join(dataCopy, "roots.json"),
        COLORTERM: "truecolor",
        // Inside tmux Claude Code drops to 256 colours unless told the multiplexer passes truecolor on.
        CLAUDE_CODE_TMUX_TRUECOLOR: "1",
        // The account's claude.ai connectors are not the capture's business, and an update must not run mid-capture.
        ENABLE_CLAUDEAI_MCP_SERVERS: "false",
        DISABLE_AUTOUPDATER: "1",
    };
    guardEnv(env, {
        realDataDir: path.join(os.homedir(), ".knossos"),
        realConfigDir: path.join(os.homedir(), ".claude"),
    });
    await mkdir(configDir, { recursive: true });
    await copyData(dataDir, dataCopy);
    await cp(
        path.join(os.homedir(), ".claude", ".credentials.json"),
        path.join(configDir, ".credentials.json"),
    );
    // Pre-seed what a first run would ask for, so no dialog waits on a key press,
    // and the one-time auto mode notice, so it never shows in a shot.
    await writeFile(
        path.join(configDir, ".claude.json"),
        JSON.stringify({
            hasCompletedOnboarding: true,
            hasSeenAutoDefaultNotice: true,
            theme,
            projects: { [project]: { hasTrustDialogAccepted: true } },
        }),
    );
    const name = sessionName();
    const session = {
        name,
        env,
        configDir,
        dataCopy,
        cols,
        rows,
        async stop() {
            // Tolerates a session that never started or is gone already.
            await run("tmux", ["kill-session", "-t", name]).catch(() => {});
            // The watcher leaves with the session as a rule; what is left is stopped by its data dir.
            await stopWatchers(dataCopy);
        },
    };
    // Before the session starts, so a signal while it starts still ends it.
    registerCleanup(() => session.stop());
    const envArgs = [
        "CLAUDE_CONFIG_DIR",
        "KNOSSOS_DATA_DIR",
        "KNOSSOS_ROOTS_FILE",
        "COLORTERM",
        "CLAUDE_CODE_TMUX_TRUECOLOR",
        "ENABLE_CLAUDEAI_MCP_SERVERS",
        "DISABLE_AUTOUPDATER",
    ].flatMap((k) => ["-e", `${k}=${env[k]}`]);
    await run("tmux", [
        "new-session",
        "-d",
        "-s",
        name,
        "-x",
        String(cols),
        "-y",
        String(rows),
        ...envArgs,
        "-c",
        project,
        "claude",
        "--plugin-dir",
        pluginDir,
        "--permission-mode",
        "default",
    ]);
    return session;
}

/** Runs `fn` with a fresh session, and cleans up afterwards however it ends. */
export async function withSession(
    options,
    fn,
    { create = createSession } = {},
) {
    try {
        return await fn(await create(options));
    } finally {
        await runCleanups();
    }
}
