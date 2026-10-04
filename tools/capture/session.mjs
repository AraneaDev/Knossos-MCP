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
import { Buffer } from "node:buffer";
import { randomBytes } from "node:crypto";
import { lstatSync, readlinkSync, realpathSync } from "node:fs";
import {
    access,
    copyFile,
    cp,
    mkdir,
    mkdtemp,
    open,
    readdir,
    readFile,
    rm,
    stat,
    writeFile,
} from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { backup, DatabaseSync } from "node:sqlite";
import { promisify } from "node:util";

const run = promisify(execFile);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const cleanups = [];

/** Adds a step for runCleanups; the function it returns takes the step back out, for work that cleaned up after itself. */
export function registerCleanup(fn) {
    cleanups.push(fn);
    return () => {
        const at = cleanups.lastIndexOf(fn);
        if (at !== -1) cleanups.splice(at, 1);
    };
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
 * One way out for the whole process: the first handler to fire runs the
 * cleanups and then exits with its own code; every later one, of any kind (a
 * repeated Ctrl-C, a SIGTERM during a SIGINT's cleanup, an error thrown from a
 * cleanup step), joins that same run instead of exiting while it is still
 * stopping the session or removing its files.
 */
export function exitGroup({
    exit = (c) => process.exit(c),
    log = (m) => process.stderr.write(m),
} = {}) {
    let leaving = null;
    return (code) => (reason) => {
        if (reason instanceof Error) log(`${reason.stack ?? reason.message}\n`);
        leaving ??= runCleanups().then(() => exit(code));
        return leaving;
    };
}

const leave = exitGroup();
let installed = false;

/** Makes sure a signal, an uncaught exception or an unhandled rejection still leaves nothing behind. */
export function installExitHandlers() {
    if (installed) return;
    installed = true;
    process.on("SIGINT", leave(130));
    process.on("SIGTERM", leave(143));
    process.on("uncaughtException", leave(1));
    process.on("unhandledRejection", leave(1));
}

/**
 * The real path of `p`: its deepest existing ancestor resolved through any
 * links, plus the rest. A dangling link on the way is followed to where it
 * points, so a link to a dir that does not exist yet is judged by its target.
 */
export function real(p, depth = 0) {
    const absolute = path.resolve(p);
    if (depth > 40) throw new Error(`too many links resolving ${p}`);
    const rest = [];
    let probe = absolute;
    for (;;) {
        try {
            return path.join(realpathSync.native(probe), ...rest);
        } catch {
            let target = null;
            try {
                if (lstatSync(probe).isSymbolicLink())
                    target = readlinkSync(probe);
            } catch {
                // Not there at all: go up a level.
            }
            if (target !== null) {
                return real(
                    path.resolve(path.dirname(probe), target, ...rest),
                    depth + 1,
                );
            }
            const parent = path.dirname(probe);
            if (parent === probe) return absolute;
            rest.unshift(path.basename(probe));
            probe = parent;
        }
    }
}

const inside = (p, root) => {
    const r = path.relative(real(root), real(p));
    return (
        r === "" ||
        (r !== ".." && !r.startsWith(`..${path.sep}`) && !path.isAbsolute(r))
    );
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
const SIDECAR = /-(wal|shm|journal)$/;
const MAGIC = Buffer.from("SQLite format 3\0", "latin1");

/** True for a regular file that starts with SQLite's header; any other `.db` is just a file. */
async function isSqlite(file) {
    const info = await stat(file).catch(() => null);
    if (info === null || !info.isFile()) return false;
    const handle = await open(file, "r");
    try {
        const { buffer, bytesRead } = await handle.read(
            Buffer.alloc(16),
            0,
            16,
            0,
        );
        return bytesRead === 16 && buffer.equals(MAGIC);
    } finally {
        await handle.close();
    }
}

const exists = (file) =>
    access(file).then(
        () => true,
        () => false,
    );

/**
 * Size and mtime of a database file and its rollback journal. The WAL is left
 * out on purpose: appends to it come with every write and never make a copy
 * wrong (SQLite reads a WAL only up to its last whole, checksummed commit).
 * What would is the database file changing under the copy, which a
 * checkpoint does, and that shows here.
 */
const fingerprint = async (db) =>
    (
        await Promise.all(
            ["", "-journal"].map((side) =>
                stat(db + side).then(
                    (i) => `${i.size}:${i.mtimeMs}`,
                    () => "-",
                ),
            ),
        )
    ).join(" ");

/**
 * Copies a database and its -wal or -journal as plain files, the database
 * first, again until the database file stood still through the whole copy,
 * so the files belong together. The -shm is never copied: SQLite rebuilds it
 * from the WAL.
 */
async function quietCopy(from, to, { copy, attempts }) {
    for (let n = 0; n < attempts; n += 1) {
        const before = await fingerprint(from);
        for (const side of ["", "-wal", "-journal"]) {
            await rm(to + side, { force: true });
            if (await exists(from + side)) await copy(from + side, to + side);
        }
        if ((await fingerprint(from)) === before) return;
    }
    throw new Error(`${from} kept changing while it was copied`);
}

/**
 * Backs one database up into `to` as one consistent snapshot. SQLite never
 * opens the original, not even read only: a read-only open of a WAL database
 * whose writer has just closed would create its -wal and -shm again in the
 * user's data dir. The files are copied to a temp dir first, and the backup is
 * taken from there. That dir is registered for cleanup as soon as it exists,
 * so a signal during the copy removes it too.
 */
export async function snapshot(
    from,
    to,
    { copy = copyFile, attempts = 5 } = {},
) {
    const scratch = await mkdtemp(
        path.join(os.tmpdir(), "knossos-capture-db-"),
    );
    const drop = () => rm(scratch, { recursive: true, force: true });
    const forget = registerCleanup(drop);
    try {
        const origin = path.join(scratch, path.basename(from));
        await quietCopy(from, origin, { copy, attempts });
        const db = new DatabaseSync(origin);
        try {
            // One step: a multi-step backup restarts whenever another connection writes in between.
            await backup(db, to, { rate: -1 });
        } finally {
            db.close();
        }
    } finally {
        forget();
        await drop();
    }
}

/**
 * Copies a data dir for a capture. Each SQLite database is taken as one
 * consistent snapshot (see snapshot) even while the user's watcher writes;
 * its -wal, -shm and -journal files never land in the copy. The rest is
 * copied as is, except the watch locks (they name the user's live watcher,
 * which the copy must never mistake for its own), the backups and the logs.
 */
export async function copyData(source, dest, options = {}) {
    const databases = [];
    await cp(source, dest, {
        recursive: true,
        filter: async (from) => {
            const rel = path.relative(source, from);
            if (rel === "") return true;
            if (["watch", "backups"].includes(rel.split(path.sep)[0]))
                return false;
            if (rel.endsWith(".log")) return false;
            const base = from.replace(SIDECAR, "");
            if (base !== from && DATABASE.test(base) && (await isSqlite(base)))
                return false;
            if (DATABASE.test(rel) && (await isSqlite(from))) {
                databases.push(rel);
                return false;
            }
            return true;
        },
    });
    for (const rel of databases) {
        await mkdir(path.dirname(path.join(dest, rel)), { recursive: true });
        await snapshot(path.join(source, rel), path.join(dest, rel), options);
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
    home = os.homedir(),
    claudeArgs = ["--permission-mode", "default"],
}) {
    installExitHandlers();
    const base = await mkdtemp(path.join(os.tmpdir(), "knossos-capture-"));
    const removeBase = () => rm(base, { recursive: true, force: true });
    const forgetBase = registerCleanup(removeBase);
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
        // Claude Code's demo mode: no account email or organisation on screen.
        IS_DEMO: "1",
        // Essential traffic only, which also keeps promotional notices out of the startup header.
        CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC: "1",
        // The header names the project from here: `~/<name>` when the project lies in it.
        HOME: home,
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
        /** Stops the session and removes its temp dir now, for a run that shoots several sessions in turn. */
        async close() {
            forgetStop();
            forgetBase();
            await session.stop();
            await removeBase();
        },
    };
    // Before the session starts, so a signal while it starts still ends it.
    const forgetStop = registerCleanup(() => session.stop());
    const envArgs = [
        "CLAUDE_CONFIG_DIR",
        "KNOSSOS_DATA_DIR",
        "KNOSSOS_ROOTS_FILE",
        "COLORTERM",
        "CLAUDE_CODE_TMUX_TRUECOLOR",
        "ENABLE_CLAUDEAI_MCP_SERVERS",
        "DISABLE_AUTOUPDATER",
        "IS_DEMO",
        "CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC",
        "HOME",
    ].flatMap((k) => ["-e", `${k}=${env[k]}`]);
    await run("tmux", [
        // Focus events on, or Claude Code prints a tmux hint at the bottom of the first frames.
        "start-server",
        ";",
        "set-option",
        "-s",
        "focus-events",
        "on",
        ";",
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
        ...claudeArgs,
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
