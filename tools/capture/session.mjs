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
import { promisify } from "node:util";

const run = promisify(execFile);
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

for (const signal of ["SIGINT", "SIGTERM"]) {
    process.once(signal, () => {
        void runCleanups().finally(() => process.exit(130));
    });
}

const inside = (p, root) => {
    const r = path.relative(path.resolve(root), path.resolve(p));
    return r === "" || (!r.startsWith("..") && !path.isAbsolute(r));
};

/** Throws unless both the data dir and the config dir are set and lie outside the real ones. */
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

/**
 * What of the data dir the copy leaves out: the watch locks (they name the
 * user's live watcher, which the copy must never mistake for its own), the
 * backups and the logs.
 */
const SKIP = new Set(["watch", "backups"]);
const keep = (dataDir) => (source) => {
    const rel = path.relative(dataDir, source);
    if (rel === "") return true;
    return !SKIP.has(rel.split(path.sep)[0]) && !rel.endsWith(".log");
};

/**
 * The PIDs of processes (the shared watcher, chiefly) started against this
 * data copy, known by the KNOSSOS_DATA_DIR in their own environment. The
 * watcher's command line names only the project, which the user's own
 * watcher shares, so it is never matched on that. The lock state file can
 * name the holder too, but it is written only at the first heartbeat.
 */
async function ownWatchers(dataCopy) {
    const mark = `KNOSSOS_DATA_DIR=${dataCopy}`;
    const pids = [];
    for (const entry of await readdir("/proc").catch(() => [])) {
        const pid = Number(entry);
        if (!Number.isInteger(pid) || pid <= 1 || pid === process.pid) continue;
        const environ = await readFile(`/proc/${pid}/environ`, "utf8").catch(
            () => "",
        );
        if (environ.split("\0").includes(mark)) pids.push(pid);
    }
    return pids;
}

export async function createSession({
    project,
    dataDir,
    cols,
    rows,
    theme,
    pluginDir,
}) {
    const base = await mkdtemp(path.join(os.tmpdir(), "knossos-capture-"));
    registerCleanup(() => rm(base, { recursive: true, force: true }));
    const configDir = path.join(base, "config");
    const dataCopy = path.join(base, "data");
    await mkdir(configDir, { recursive: true });
    await cp(dataDir, dataCopy, { recursive: true, filter: keep(dataDir) });
    await cp(
        path.join(os.homedir(), ".claude", ".credentials.json"),
        path.join(configDir, ".credentials.json"),
    );
    // Pre-seed what a first run would ask for, so no dialog waits on a key press.
    await writeFile(
        path.join(configDir, ".claude.json"),
        JSON.stringify({
            hasCompletedOnboarding: true,
            theme,
            projects: { [project]: { hasTrustDialogAccepted: true } },
        }),
    );
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
    const name = `knossos-capture-${process.pid}`;
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
    ]);
    const session = {
        name,
        env,
        configDir,
        dataCopy,
        cols,
        rows,
        async stop() {
            await run("tmux", ["kill-session", "-t", name]).catch(() => {});
            // The watcher leaves with the session as a rule; give it a moment, then stop what is left.
            // Only a watcher started against this session's data copy is ours to stop.
            await new Promise((resolve) => setTimeout(resolve, 1000));
            for (const pid of await ownWatchers(dataCopy)) {
                try {
                    process.kill(pid, "SIGTERM");
                } catch {
                    // Already gone with the session.
                }
            }
        },
    };
    registerCleanup(() => session.stop());
    return session;
}
