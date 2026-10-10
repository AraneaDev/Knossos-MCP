import { execFile } from "node:child_process";
import {
    cp,
    lstat,
    mkdir,
    mkdtemp,
    readdir,
    readFile,
    readlink,
    rm,
    stat,
    symlink,
    writeFile,
} from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { promisify } from "node:util";
import { describe, expect, it } from "vitest";
import { cursor, send, snap, type } from "./driver.mjs";
import {
    launchArgv,
    runCleanups,
    socketPath,
    stopSession,
} from "./session.mjs";
import {
    checkLength,
    defaultBranch,
    insertBefore,
    playShot,
    promptReady,
    stageGraph,
    submit,
    throwawayWorktree,
} from "./shoot.mjs";
import { COMMAND_LIST, PROMPT } from "./shots.mjs";

const run = promisify(execFile);

/** A session whose tmux calls are recorded and answered by `answer`. */
const fakeSession = (answer = () => "") => {
    const calls = [];
    return {
        calls,
        session: {
            name: "knossos-capture-1-abcd",
            socket: "knossos-capture-1-abcd",
            cols: 160,
            dataCopy: "/nonexistent/knossos-capture-copy",
            runTmux: async (argv) => {
                calls.push(argv);
                return { stdout: answer(argv) };
            },
        },
    };
};

describe("tmux server", () => {
    it("runs every tmux call on the session's own server", async () => {
        const { calls, session } = fakeSession((argv) =>
            argv.includes("display-message") ? "3" : "",
        );
        await send(session, "Enter");
        await type(session, "x");
        await snap(session);
        await cursor(session);
        await stopSession(session);
        const launch = launchArgv(session, {
            env: {},
            project: "/p",
            pluginDir: "/q",
            claudeArgs: [],
        });
        for (const argv of [...calls, launch]) {
            expect(argv.slice(0, 2)).toEqual(["-L", session.socket]);
        }
        expect(calls.at(-1)).toEqual(["-L", session.socket, "kill-server"]);
        expect(socketPath("knossos-capture-x")).toMatch(
            /\/tmux-\d+\/knossos-capture-x$/,
        );
        // Focus events are set on that private server, in the same command that starts it.
        expect(launch.join(" ")).toContain(
            "start-server ; set-option -s focus-events on ; new-session",
        );
    });
});

const screen = (prompt, extra = "") =>
    `header\n${extra}────\n❯ ${prompt}\n────\n  footer`;

describe("stopSession", () => {
    it("removes the socket a killed server leaves behind", async () => {
        const dir = await mkdtemp(path.join(os.tmpdir(), "sock-"));
        const { session } = fakeSession();
        session.socketPath = path.join(dir, session.socket);
        await writeFile(session.socketPath, "");
        try {
            await stopSession(session);
            await expect(stat(session.socketPath)).rejects.toThrow(/ENOENT/);
        } finally {
            await rm(dir, { recursive: true, force: true });
        }
    });
});

describe("promptReady", () => {
    it("lets the hero's prompt go only when the prompt line reads it exactly", () => {
        expect(promptReady(screen(PROMPT), 2, PROMPT)).toBeNull();
        expect(promptReady(screen(PROMPT.slice(0, -1)), 2, PROMPT)).toMatch(
            /prompt reads/,
        );
        // Text elsewhere on screen is not the prompt.
        expect(promptReady(screen("", `${PROMPT}\n`), 2, PROMPT)).toMatch(
            /prompt reads ""/,
        );
    });
    it("reads a prompt that wraps onto more lines as one text", () => {
        const half = PROMPT.indexOf(" Edit");
        const wrapped = `────\n❯ ${PROMPT.slice(0, half)}\n  ${PROMPT.slice(half + 1)}\n────`;
        expect(promptReady(wrapped, 2, PROMPT)).toBeNull();
    });
    it("lets /knossos go only with its space typed and the command list shut", () => {
        const open =
            "  /knossos       Toggle the Knossos pane\n  /knossos:graph  (Knossos) Use when\n";
        expect(
            promptReady(screen("/knossos"), 11, "/knossos ", COMMAND_LIST),
        ).toBeNull();
        expect(
            promptReady(screen("/knossos"), 10, "/knossos ", COMMAND_LIST),
        ).toMatch(/cursor/);
        expect(
            promptReady(
                screen("/knossos", open),
                11,
                "/knossos ",
                COMMAND_LIST,
            ),
        ).toMatch(/still shows/);
        expect(
            promptReady(
                screen("/knossos:graph"),
                17,
                "/knossos ",
                COMMAND_LIST,
            ),
        ).toMatch(/prompt reads/);
    });
});

describe("submit", () => {
    const step = { expect: "/knossos ", absent: COMMAND_LIST, steps: [{}] };
    it("clears and retypes a wrong prompt twice, then fails without Enter, showing the screen that failed", async () => {
        let cleared = false;
        const { calls, session } = fakeSession((argv) => {
            if (argv.includes("send-keys")) cleared = argv.at(-1) === "C-u";
            if (argv.includes("display-message")) return "11";
            return screen(cleared ? "" : "/knossso");
        });
        let typed = 0;
        const failed = submit(session, step, async () => {
            typed += 1;
            cleared = false;
        });
        await expect(failed).rejects.toThrow(/Enter not sent/);
        expect((await failed.catch((e) => e)).lastFrame).toContain("/knossso");
        expect(typed).toBe(3);
        const keys = calls
            .filter((a) => a.includes("send-keys"))
            .map((a) => a.at(-1));
        expect(keys).toEqual(["C-u", "C-u", "C-u"]);
    });
    it("sends Enter once the prompt is right", async () => {
        const { calls, session } = fakeSession((argv) =>
            argv.includes("display-message") ? "11" : screen("/knossos"),
        );
        await submit(session, step, async () => {});
        expect(
            calls.filter((a) => a.includes("send-keys")).map((a) => a.at(-1)),
        ).toEqual(["Enter"]);
    });
});

describe("insertBefore", () => {
    it("puts the line above the anchor, and names the fixture when it is gone", () => {
        expect(
            insertBefore(
                "a\nfunction normalise() {}\n",
                "function normalise",
                "// x",
            ),
        ).toBe("a\n// x\nfunction normalise() {}\n");
        expect(() =>
            insertBefore("a\n", "function normalise", "// x", "paths.ts"),
        ).toThrow(/FIXTURE\.editAnchor/);
    });
});

describe("wait on a fixture", () => {
    it("names the FIXTURE entry on a timeout", async () => {
        const session = { name: "s", snap: async () => "nothing here" };
        await expect(
            playShot(
                session,
                {
                    steps: [
                        {
                            do: "wait",
                            step: "hub",
                            match: "zzz",
                            timeoutMs: 30,
                            fixture: "hub",
                        },
                    ],
                },
                { worktree: "/nonexistent" },
            ),
        ).rejects.toThrow(/FIXTURE\.hub = "ResultEnvelope"/);
    });
});

describe("checkLength", () => {
    it("counts the frames built and refuses a GIF past the shot's limit", async () => {
        const dir = await mkdtemp(path.join(os.tmpdir(), "frames-"));
        try {
            for (let i = 0; i < 9; i += 1)
                await writeFile(
                    path.join(dir, `${String(i).padStart(5, "0")}.png`),
                    "",
                );
            await writeFile(path.join(dir, "palette.png"), "");
            expect(await checkLength(dir, { maxMs: 2000 })).toBe(1125);
            await expect(checkLength(dir, { maxMs: 1000 })).rejects.toThrow(
                /past 1 s/,
            );
        } finally {
            await rm(dir, { recursive: true, force: true });
        }
    });
});

describe("defaultBranch", () => {
    it("takes origin's HEAD, and main when there is none", async () => {
        expect(await defaultBranch("/r", async () => "origin/trunk")).toBe(
            "trunk",
        );
        expect(
            await defaultBranch("/r", async () => {
                throw new Error("no ref");
            }),
        ).toBe("main");
    });
});

const AUTHOR = {
    GIT_AUTHOR_NAME: "AraneaDev",
    GIT_AUTHOR_EMAIL: "12177132+AraneaDev@users.noreply.github.com",
    GIT_COMMITTER_NAME: "AraneaDev",
    GIT_COMMITTER_EMAIL: "12177132+AraneaDev@users.noreply.github.com",
};

/**
 * A checkout with installed packages in every place git can hold them, a
 * relative `.bin` link, an absolute link and a relative link out of it.
 */
async function packagesFixture() {
    const root = await mkdtemp(path.join(os.tmpdir(), "tree-"));
    const repo = path.join(root, "Repo");
    const outside = path.join(root, "outside");
    const env = { ...process.env, ...AUTHOR };
    const files = {
        ".gitignore": "/node_modules/\n/out/\n",
        "a.txt": "a",
        "node_modules/@types/node/index.d.ts": "export {}\n",
        "node_modules/pkg/bin.js": "bin\n",
        "my worker/node_modules/pkg/index.js": "1\n",
        "out/nested/node_modules/x/index.js": "x\n",
        "out/shot.png": "png",
    };
    for (const [relative, contents] of Object.entries(files)) {
        await mkdir(path.dirname(path.join(repo, relative)), {
            recursive: true,
        });
        await writeFile(path.join(repo, relative), contents);
    }
    await mkdir(outside);
    await writeFile(path.join(outside, "index.js"), "outside\n");
    await mkdir(path.join(repo, "node_modules", ".bin"));
    await symlink("../pkg/bin.js", path.join(repo, "node_modules/.bin/tool"));
    await symlink(outside, path.join(repo, "node_modules/linked"));
    await symlink("../../outside", path.join(repo, "node_modules/escape"));
    // Relative hops that each look local but end outside: through the link
    // above, and through a tracked directory link git recreates in a worktree.
    await symlink("escape", path.join(repo, "node_modules/hop"));
    await symlink("../outside", path.join(repo, "lib"));
    await symlink("../lib/index.js", path.join(repo, "node_modules/viadir"));
    await run("git", ["init", "-q", repo], { env });
    await run("git", ["-C", repo, "add", ".gitignore", "a.txt", "lib"], {
        env,
    });
    await run("git", ["-C", repo, "commit", "-qm", "a"], { env });
    return { root, repo, outside };
}

/** Everything the fixture installed is still there, links included. */
async function expectOriginals(repo, outside) {
    expect(await readFile(path.join(outside, "index.js"), "utf8")).toBe(
        "outside\n",
    );
    expect(
        await readFile(
            path.join(repo, "node_modules/@types/node/index.d.ts"),
            "utf8",
        ),
    ).toBe("export {}\n");
    expect(
        await readFile(
            path.join(repo, "my worker/node_modules/pkg/index.js"),
            "utf8",
        ),
    ).toBe("1\n");
    expect(await readlink(path.join(repo, "node_modules/.bin/tool"))).toBe(
        "../pkg/bin.js",
    );
    expect(await readlink(path.join(repo, "node_modules/linked"))).toBe(
        outside,
    );
}

describe("throwawayWorktree", () => {
    it("adds a detached worktree, and removes it when the work after it throws", async () => {
        const root = await mkdtemp(path.join(os.tmpdir(), "tree-"));
        const repo = path.join(root, "Repo");
        const env = { ...process.env, ...AUTHOR };
        await mkdir(repo);
        await run("git", ["init", "-q", repo], { env });
        await writeFile(path.join(repo, "a.txt"), "a");
        await run("git", ["-C", repo, "add", "a.txt"], { env });
        await run("git", ["-C", repo, "commit", "-qm", "a"], { env });
        try {
            let dir = null;
            await expect(
                (async () => {
                    try {
                        dir = await throwawayWorktree(repo, {
                            home: path.join(root, "home"),
                        });
                        expect(
                            await readFile(path.join(dir, "a.txt"), "utf8"),
                        ).toBe("a");
                        throw new Error("the shot failed");
                    } finally {
                        await runCleanups();
                    }
                })(),
            ).rejects.toThrow(/the shot failed/);
            expect(dir).toBe(path.join(root, "home", "knossos"));
            await expect(stat(dir)).rejects.toThrow(/ENOENT/);
            const { stdout } = await run("git", [
                "-C",
                repo,
                "worktree",
                "list",
            ]);
            expect(stdout.trim().split("\n")).toHaveLength(1);
        } finally {
            await rm(root, { recursive: true, force: true });
        }
    });
    // A scan types a call into a package from that package's installed
    // declarations. A worktree has none, so an object literal handed to a
    // Node API (`registerHooks({ resolve() {} })`) lost its contract and its
    // methods read as dead code the checkout's own graph does not report.
    it("carries every installed node_modules, and no link that leaves the checkout", async () => {
        const { root, repo, outside } = await packagesFixture();
        try {
            const dir = await throwawayWorktree(repo, {
                home: path.join(root, "home"),
            });
            const inTree = (relative) => path.join(dir, relative);
            // Ignored at the root, untracked and not ignored, inside an
            // ignored dir, and under a name with a space in it.
            expect(
                await readFile(
                    inTree("node_modules/@types/node/index.d.ts"),
                    "utf8",
                ),
            ).toBe("export {}\n");
            expect(
                await readFile(
                    inTree("my worker/node_modules/pkg/index.js"),
                    "utf8",
                ),
            ).toBe("1\n");
            expect(
                await readFile(
                    inTree("out/nested/node_modules/x/index.js"),
                    "utf8",
                ),
            ).toBe("x\n");
            await expect(stat(inTree("out/shot.png"))).rejects.toThrow(
                /ENOENT/,
            );
            // A real directory: a scan reads nothing outside its root.
            expect((await lstat(inTree("node_modules"))).isSymbolicLink()).toBe(
                false,
            );
            // A relative link inside the tree stays a link to the copy.
            expect(await readlink(inTree("node_modules/.bin/tool"))).toBe(
                "../pkg/bin.js",
            );
            expect(
                await readFile(inTree("node_modules/.bin/tool"), "utf8"),
            ).toBe("bin\n");
            // An absolute link, or a relative one out of the checkout, would
            // let a scan or an edit reach outside the worktree.
            await expect(lstat(inTree("node_modules/linked"))).rejects.toThrow(
                /ENOENT/,
            );
            for (const link of ["escape", "hop", "viadir"])
                await expect(
                    lstat(inTree(`node_modules/${link}`)),
                ).rejects.toThrow(/ENOENT/);
            await runCleanups();
            await expect(stat(dir)).rejects.toThrow(/ENOENT/);
            await expectOriginals(repo, outside);
        } finally {
            await runCleanups();
            await rm(root, { recursive: true, force: true });
        }
    });
    it("removes a link in the worktree, never what it points at", async () => {
        const { root, repo, outside } = await packagesFixture();
        try {
            const dir = await throwawayWorktree(repo, {
                home: path.join(root, "home"),
            });
            await symlink(
                path.join(repo, "node_modules"),
                path.join(dir, "back"),
            );
            await symlink(outside, path.join(dir, "node_modules", "planted"));
            await runCleanups();
            await expect(stat(dir)).rejects.toThrow(/ENOENT/);
            await expectOriginals(repo, outside);
        } finally {
            await runCleanups();
            await rm(root, { recursive: true, force: true });
        }
    });
    it("removes the worktree and leaves the originals when a copy fails halfway", async () => {
        const { root, repo, outside } = await packagesFixture();
        let copies = 0;
        const copy = async (from, to, options) => {
            copies += 1;
            if (copies === 2) throw new Error("disk full");
            await cp(from, to, options);
        };
        try {
            let dir = null;
            await expect(
                (async () => {
                    try {
                        await throwawayWorktree(repo, {
                            home: path.join(root, "home"),
                            copy,
                        });
                    } finally {
                        dir = path.join(root, "home", "knossos");
                        await runCleanups();
                    }
                })(),
            ).rejects.toThrow(/disk full/);
            expect(copies).toBe(2);
            await expect(stat(dir)).rejects.toThrow(/ENOENT/);
            await expectOriginals(repo, outside);
        } finally {
            await rm(root, { recursive: true, force: true });
        }
    });
    it("registers the removal before git adds it, so a failed add is still cleaned", async () => {
        const root = await mkdtemp(path.join(os.tmpdir(), "tree-"));
        const calls = [];
        const runGit = async (_dir, ...args) => {
            calls.push(args.slice(0, 2).join(" "));
            if (args[1] === "add") throw new Error("add failed");
            return "";
        };
        try {
            await expect(
                throwawayWorktree("/r/Repo", { home: root, runGit }),
            ).rejects.toThrow(/add failed/);
            await runCleanups();
            expect(calls).toEqual([
                "worktree add",
                "worktree remove",
                "worktree prune",
            ]);
        } finally {
            await rm(root, { recursive: true, force: true });
        }
    });
});

describe("stageGraph", () => {
    it("scans the worktree into a stage of its own, never the real data dir", async () => {
        const root = await mkdtemp(path.join(os.tmpdir(), "stage-"));
        const source = path.join(root, "real");
        await mkdir(source);
        await writeFile(path.join(source, "roots.json"), '{"roots":["/a"]}');
        const scans = [];
        const gits = [];
        try {
            const stage = await stageGraph("/w/knossos", {
                source,
                realDataDir: source,
                tmpRoot: root,
                base: true,
                runScan: async (args, options) =>
                    scans.push({ args, env: options.env }),
                runGit: async (_dir, ...args) => {
                    gits.push(args.join(" "));
                    return args[0] === "rev-parse"
                        ? "headsha"
                        : args[0] === "merge-base"
                          ? "forksha"
                          : "";
                },
            });
            expect(path.dirname(stage)).toBe(root);
            expect(scans).toHaveLength(2);
            for (const scan of scans) {
                expect(scan.args).toEqual([
                    "scan",
                    "/w/knossos",
                    "--snapshot-retention=5",
                ]);
                expect(scan.env.KNOSSOS_DATA_DIR).toBe(stage);
                expect(scan.env.KNOSSOS_ROOTS_FILE).toBe(
                    path.join(stage, "roots.json"),
                );
            }
            expect(gits).toEqual([
                "rev-parse HEAD",
                "symbolic-ref --short refs/remotes/origin/HEAD",
                "merge-base HEAD main",
                "checkout --quiet --detach forksha",
                "checkout --quiet --detach headsha",
            ]);
            expect(
                JSON.parse(
                    await readFile(path.join(stage, "roots.json"), "utf8"),
                ).roots,
            ).toEqual(["/a", "/w/knossos"]);
            expect(
                await readFile(path.join(source, "roots.json"), "utf8"),
            ).toBe('{"roots":["/a"]}');
        } finally {
            await runCleanups();
            await rm(root, { recursive: true, force: true });
        }
    });
    it("refuses a stage inside the real data dir before it makes anything", async () => {
        const root = await mkdtemp(path.join(os.tmpdir(), "stage-"));
        let scanned = false;
        try {
            await expect(
                stageGraph("/w/knossos", {
                    source: root,
                    realDataDir: root,
                    tmpRoot: root,
                    runScan: async () => {
                        scanned = true;
                    },
                }),
            ).rejects.toThrow(/real data dir/);
            expect(scanned).toBe(false);
            expect(await readdir(root)).toEqual([]);
        } finally {
            await rm(root, { recursive: true, force: true });
        }
    });
});

describe("shoot.mjs as a command", () => {
    const self = new URL("./shoot.mjs", import.meta.url);
    it("starts with a node shebang, so a scan reads it as an executable script", async () => {
        const [first] = (await readFile(self, "utf8")).split("\n", 1);
        expect(first).toBe("#!/usr/bin/env node");
    });
    it("is executable", async () => {
        expect((await stat(self)).mode & 0o111).not.toBe(0);
    });
});
