/** Dev-only. Drives the capture's tmux session: keys in, frames out. */
import { execFile } from "node:child_process";
import { promisify } from "node:util";

const run = promisify(execFile);
// CSI sequences (colours, cursor) and OSC sequences (titles, links), ended by BEL or ST.
const ANSI =
    // eslint-disable-next-line no-control-regex -- matching escape sequences is the point
    /\u001b\[[0-?]*[ -/]*[@-~]|\u001b\][^\u0007\u001b]*(?:\u0007|\u001b\\)/g;

export const plain = (s) => s.replace(ANSI, "");

export async function send(session, ...keys) {
    await run("tmux", ["send-keys", "-t", session.name, ...keys]);
}

export async function type(session, text) {
    await run("tmux", ["send-keys", "-t", session.name, "-l", text]);
}

/** The visible pane, with its colours as escape sequences. */
export async function snap(session) {
    const { stdout } = await run("tmux", [
        "capture-pane",
        "-t",
        session.name,
        "-e",
        "-p",
    ]);
    return stdout;
}

/**
 * Polls the pane until the predicate holds on its plain text, and returns that frame (with its escapes).
 * On a timeout it throws an error that names the step and carries the last frame seen, as plain text.
 */
export async function waitFor(
    session,
    predicate,
    { timeoutMs = 30000, step, pollMs = 200 },
) {
    const read = session.snap ?? (() => snap(session));
    const end = Date.now() + timeoutMs;
    let last = "";
    while (Date.now() < end) {
        last = await read();
        if (predicate(plain(last))) return last;
        await new Promise((resolve) => setTimeout(resolve, pollMs));
    }
    const error = new Error(`step "${step}" timed out after ${timeoutMs} ms`);
    error.lastFrame = plain(last);
    throw error;
}
