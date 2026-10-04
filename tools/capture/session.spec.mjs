import { spawn } from "node:child_process";
import {
    mkdir,
    mkdtemp,
    readdir,
    readFile,
    rm,
    stat,
    symlink,
    writeFile,
} from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { DatabaseSync } from "node:sqlite";
import { describe, expect, it } from "vitest";
import {
    copyData,
    exitGroup,
    guardEnv,
    ownWatchers,
    registerCleanup,
    runCleanups,
    sessionName,
    stopWatchers,
    withSession,
} from "./session.mjs";

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

describe("guardEnv with links", () => {
    it("refuses a dir that reaches the real dirs through a symlink", async () => {
        const root = await mkdtemp(path.join(os.tmpdir(), "guard-"));
        try {
            const realData = path.join(root, "knossos");
            const realConfig = path.join(root, "claude");
            await mkdir(realData);
            await mkdir(realConfig);
            await symlink(realData, path.join(root, "tmp-data"));
            await symlink(realConfig, path.join(root, "tmp-config"));
            const real = { realDataDir: realData, realConfigDir: realConfig };
            const elsewhere = path.join(root, "elsewhere");
            expect(() =>
                guardEnv(
                    {
                        KNOSSOS_DATA_DIR: path.join(
                            root,
                            "tmp-data",
                            "x",
                            "data",
                        ),
                        CLAUDE_CONFIG_DIR: elsewhere,
                    },
                    real,
                ),
            ).toThrow(/real data dir/);
            expect(() =>
                guardEnv(
                    {
                        KNOSSOS_DATA_DIR: elsewhere,
                        CLAUDE_CONFIG_DIR: path.join(
                            root,
                            "tmp-config",
                            "config",
                        ),
                    },
                    real,
                ),
            ).toThrow(/real config/);
            expect(() =>
                guardEnv(
                    {
                        KNOSSOS_DATA_DIR: path.join(elsewhere, "d"),
                        CLAUDE_CONFIG_DIR: path.join(elsewhere, "c"),
                    },
                    real,
                ),
            ).not.toThrow();
        } finally {
            await rm(root, { recursive: true, force: true });
        }
    });
});

describe("exitGroup", () => {
    it("cleans up once, exits with its code, and folds a repeat into the same run", async () => {
        const seen = [];
        const exits = [];
        registerCleanup(async () => {
            await new Promise((r) => setTimeout(r, 10));
            seen.push("stop");
        });
        const leave = exitGroup({ exit: (c) => exits.push(c), log: () => {} });
        const handler = leave(143);
        const first = handler();
        expect(handler()).toBe(first);
        await first;
        expect(seen).toEqual(["stop"]);
        expect(exits).toEqual([143]);
    });
    it("exits once, after the first cleanup ends, with the first code, when other handlers fire meanwhile", async () => {
        const events = [];
        let release;
        registerCleanup(() =>
            new Promise((r) => (release = r)).then(() =>
                events.push("cleaned"),
            ),
        );
        const leave = exitGroup({
            exit: (c) => events.push(`exit ${c}`),
            log: () => {},
        });
        const sigint = leave(130)();
        await new Promise((r) => setTimeout(r, 5));
        const sigterm = leave(143)();
        const rejection = leave(1)(new Error("late"));
        await new Promise((r) => setTimeout(r, 5));
        expect(events).toEqual([]);
        release();
        await Promise.all([sigint, sigterm, rejection]);
        expect(events).toEqual(["cleaned", "exit 130"]);
    });
});

describe("guardEnv edges", () => {
    it("refuses a dangling link whose target lies in a real dir that does not have it yet", async () => {
        const root = await mkdtemp(path.join(os.tmpdir(), "guard-"));
        try {
            const realData = path.join(root, "knossos");
            await mkdir(realData);
            await mkdir(path.join(root, "tmp"));
            await symlink(
                path.join(realData, "not-yet-there"),
                path.join(root, "tmp", "data"),
            );
            const real = {
                realDataDir: realData,
                realConfigDir: path.join(root, "claude"),
            };
            expect(() =>
                guardEnv(
                    {
                        KNOSSOS_DATA_DIR: path.join(root, "tmp", "data"),
                        CLAUDE_CONFIG_DIR: path.join(root, "c"),
                    },
                    real,
                ),
            ).toThrow(/real data dir/);
            expect(() =>
                guardEnv(
                    {
                        KNOSSOS_DATA_DIR: path.join(
                            root,
                            "tmp",
                            "data",
                            "deeper",
                        ),
                        CLAUDE_CONFIG_DIR: path.join(root, "c"),
                    },
                    real,
                ),
            ).toThrow(/real data dir/);
        } finally {
            await rm(root, { recursive: true, force: true });
        }
    });
    it("refuses a dir inside the real one whose name starts with two dots", () => {
        const real = {
            realDataDir: "/tmp/guard-x/knossos",
            realConfigDir: "/tmp/guard-x/claude",
        };
        const elsewhere = "/tmp/guard-y";
        expect(() =>
            guardEnv(
                {
                    KNOSSOS_DATA_DIR: "/tmp/guard-x/knossos/..data",
                    CLAUDE_CONFIG_DIR: elsewhere,
                },
                real,
            ),
        ).toThrow(/real data dir/);
        expect(() =>
            guardEnv(
                {
                    KNOSSOS_DATA_DIR: elsewhere,
                    CLAUDE_CONFIG_DIR: "/tmp/guard-x/claude/..config",
                },
                real,
            ),
        ).toThrow(/real config/);
        expect(() =>
            guardEnv(
                {
                    KNOSSOS_DATA_DIR: "/tmp/guard-x/..data",
                    CLAUDE_CONFIG_DIR: "/tmp/guard-x/..config",
                },
                real,
            ),
        ).not.toThrow();
    });
});

describe("withSession", () => {
    it("cleans up after the work, also when it throws", async () => {
        const seen = [];
        const create = async () => {
            registerCleanup(() => seen.push("stop"));
            return { name: "s" };
        };
        await expect(
            withSession({}, async (s) => s.name, { create }),
        ).resolves.toBe("s");
        await expect(
            withSession(
                {},
                async () => {
                    throw new Error("boom");
                },
                { create },
            ),
        ).rejects.toThrow("boom");
        expect(seen).toEqual(["stop", "stop"]);
    });
    it("cleans up when creating the session fails half way", async () => {
        const seen = [];
        const create = async () => {
            registerCleanup(() => seen.push("rm"));
            throw new Error("no tmux");
        };
        await expect(
            withSession({}, async () => "never", { create }),
        ).rejects.toThrow("no tmux");
        expect(seen).toEqual(["rm"]);
    });
});

describe("sessionName", () => {
    it("carries the pid and a random suffix", () => {
        const a = sessionName(42);
        expect(a).toMatch(/^knossos-capture-42-[0-9a-f]{8}$/);
        expect(sessionName(42)).not.toBe(a);
    });
});

describe("ownWatchers", () => {
    it("matches only the exact data copy in a process environment", async () => {
        const proc = await mkdtemp(path.join(os.tmpdir(), "proc-"));
        const copy = "/tmp/knossos-capture-abc/data";
        const procs = {
            100: [`KNOSSOS_DATA_DIR=${copy}`, "PATH=/usr/bin"],
            101: [`KNOSSOS_DATA_DIR=${copy}-other`],
            102: [`KNOSSOS_DATA_DIR=${copy}/nested`],
            103: ["KNOSSOS_DATA_DIR=/root/.knossos"],
            104: [`NOTE=KNOSSOS_DATA_DIR=${copy}`],
            105: [`KNOSSOS_DATA_DIR=${copy}`],
            self: [`KNOSSOS_DATA_DIR=${copy}`],
        };
        try {
            for (const [entry, vars] of Object.entries(procs)) {
                await mkdir(path.join(proc, entry));
                await writeFile(
                    path.join(proc, entry, "environ"),
                    vars.join("\0") + "\0",
                );
            }
            await mkdir(path.join(proc, "106"));
            expect(
                (await ownWatchers(copy, { procRoot: proc, self: 105 })).sort(),
            ).toEqual([100]);
            expect(
                await ownWatchers("/root/.knossos", {
                    procRoot: proc,
                    self: 1,
                }),
            ).toEqual([103]);
        } finally {
            await rm(proc, { recursive: true, force: true });
        }
    });
});

describe("copyData", () => {
    it("snapshots each database with its uncheckpointed WAL, and leaves out locks, backups, logs and sidecars", async () => {
        const root = await mkdtemp(path.join(os.tmpdir(), "copy-"));
        const source = path.join(root, "src");
        const dest = path.join(root, "dest");
        await mkdir(path.join(source, "watch"), { recursive: true });
        await mkdir(path.join(source, "backups"));
        await writeFile(path.join(source, "roots.json"), '{"roots":[]}');
        await writeFile(path.join(source, "mcp-serve.log"), "log");
        await writeFile(path.join(source, "watch", "abc.json"), '{"pid":1}');
        await writeFile(path.join(source, "backups", "old.sqlite"), "");
        const live = new DatabaseSync(path.join(source, "knossos.sqlite"));
        try {
            live.exec(
                "PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0; CREATE TABLE t(x); INSERT INTO t VALUES (1), (2);",
            );
            await copyData(source, dest);
            // Listed before the copy is opened: opening a WAL database makes its sidecars.
            const names = await readdir(dest);
            for (const left of [
                "watch",
                "backups",
                "mcp-serve.log",
                "knossos.sqlite-wal",
                "knossos.sqlite-shm",
            ]) {
                expect(names).not.toContain(left);
            }
            const copy = new DatabaseSync(path.join(dest, "knossos.sqlite"), {
                readOnly: true,
            });
            expect(copy.prepare("SELECT count(*) AS n FROM t").get().n).toBe(2);
            copy.close();
            expect(await readFile(path.join(dest, "roots.json"), "utf8")).toBe(
                '{"roots":[]}',
            );
        } finally {
            live.close();
            await rm(root, { recursive: true, force: true });
        }
    });
});

describe("stopWatchers", () => {
    it("ends a process that ignores SIGTERM with SIGKILL, and leaves a process on another data dir alone", async () => {
        const copy = `/tmp/knossos-capture-spec-${process.pid}/data`;
        const stubborn =
            "process.on('SIGTERM', () => {}); setInterval(() => {}, 1000)";
        const start = (dir) => {
            const child = spawn(process.execPath, ["-e", stubborn], {
                env: { ...process.env, KNOSSOS_DATA_DIR: dir },
                stdio: "ignore",
            });
            const ended = new Promise((resolve) =>
                child.once("exit", (code, signal) => resolve(signal)),
            );
            return { child, ended };
        };
        const ours = start(copy);
        const theirs = start(`${copy}-user`);
        try {
            await new Promise((r) => setTimeout(r, 300));
            await stopWatchers(copy, 500);
            await expect(ours.ended).resolves.toBe("SIGKILL");
            expect(theirs.child.exitCode).toBeNull();
            expect(theirs.child.signalCode).toBeNull();
        } finally {
            ours.child.kill("SIGKILL");
            theirs.child.kill("SIGKILL");
        }
    });
});

describe("copyData without a live writer", () => {
    it("leaves the source dir untouched when its WAL database has no sidecars", async () => {
        const root = await mkdtemp(path.join(os.tmpdir(), "copy-"));
        const source = path.join(root, "src");
        await mkdir(source);
        const db = new DatabaseSync(path.join(source, "knossos.sqlite"));
        db.exec(
            "PRAGMA journal_mode=WAL; CREATE TABLE t(x); INSERT INTO t VALUES (1), (2), (3);",
        );
        db.close();
        const listing = async () =>
            (await readdir(source)).sort().map(async (name) => {
                const info = await stat(path.join(source, name));
                return `${name} ${info.size} ${info.mtimeMs}`;
            });
        try {
            const before = await Promise.all(await listing());
            expect(before.map((l) => l.split(" ")[0])).toEqual([
                "knossos.sqlite",
            ]);
            await copyData(source, path.join(root, "dest"));
            expect(await Promise.all(await listing())).toEqual(before);
            const copy = new DatabaseSync(
                path.join(root, "dest", "knossos.sqlite"),
                { readOnly: true },
            );
            expect(copy.prepare("SELECT count(*) AS n FROM t").get().n).toBe(3);
            copy.close();
        } finally {
            await rm(root, { recursive: true, force: true });
        }
    });
    it("backs up only real SQLite files: another .db is copied as is, a .db dir as a dir", async () => {
        const root = await mkdtemp(path.join(os.tmpdir(), "copy-"));
        const source = path.join(root, "src");
        const dest = path.join(root, "dest");
        await mkdir(path.join(source, "cache.db"), { recursive: true });
        await writeFile(path.join(source, "cache.db", "inner.txt"), "inner");
        await writeFile(
            path.join(source, "notes.db"),
            "plain text, not a database",
        );
        await writeFile(path.join(source, "notes.db-journal"), "also plain");
        try {
            await copyData(source, dest);
            expect(await readFile(path.join(dest, "notes.db"), "utf8")).toBe(
                "plain text, not a database",
            );
            expect(
                await readFile(path.join(dest, "notes.db-journal"), "utf8"),
            ).toBe("also plain");
            expect(
                await readFile(
                    path.join(dest, "cache.db", "inner.txt"),
                    "utf8",
                ),
            ).toBe("inner");
        } finally {
            await rm(root, { recursive: true, force: true });
        }
    });
});
