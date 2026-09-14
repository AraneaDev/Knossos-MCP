/**
 * What the worker process says, and the code it exits with, when the scanner
 * thread ends without being asked to.
 *
 * A thread that runs out of heap reports `ERR_WORKER_OUT_OF_MEMORY` instead of
 * taking the process down with V8's fatal message. That message is what the
 * core recognises as heap exhaustion and answers by splitting the batch, so it
 * is repeated here word for word, with the exit code of the abort V8 would
 * have raised (134, SIGABRT). Any other end is a crash: a scan that will never
 * answer must end the process rather than leave the core waiting.
 *
 * @param {unknown} error the thread's `error` event, if one preceded the exit
 * @param {number} exitCode the thread's exit code
 * @returns {{message: string, exitCode: number}}
 */
export function scanThreadFailure(error, exitCode) {
    if (error?.code === "ERR_WORKER_OUT_OF_MEMORY") {
        return {
            message: `FATAL ERROR: TypeScript scanner thread reached its heap limit (${error.message})\nJavaScript heap out of memory\n`,
            exitCode: 134,
        };
    }
    if (error !== undefined) {
        return {
            message: `FATAL ERROR: TypeScript scanner thread crashed: ${error?.stack ?? String(error)}\n`,
            exitCode: 70,
        };
    }
    return {
        message: `FATAL ERROR: TypeScript scanner thread exited unexpectedly (exit ${exitCode}).\n`,
        exitCode: exitCode === 0 ? 70 : exitCode,
    };
}
