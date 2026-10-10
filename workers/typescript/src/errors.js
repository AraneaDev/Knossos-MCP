/**
 * Error helpers shared by every filesystem read: a readable message for any
 * thrown value, and the stack overflow that must never be swallowed as a
 * filesystem answer.
 */

export function errorMessage(error) {
    return error instanceof Error ? error.message : String(error);
}

// V8's own message for a JavaScript stack overflow. Any other RangeError is a
// real fault and keeps propagating.
export function isStackOverflow(error) {
    return (
        error instanceof RangeError &&
        error.message.includes("Maximum call stack size exceeded")
    );
}

// For a catch that reads any failure as the filesystem's answer. A stack
// overflow is not one: a host callback is a leaf frame of a deep program build,
// so swallowing the overflow there would drop one read and let the build carry
// on, truncating the program instead of reaching the TS_PROGRAM_TOO_DEEP
// backstop in #scanProgram.
export function rethrowStackOverflow(error) {
    if (isStackOverflow(error)) throw error;
}
