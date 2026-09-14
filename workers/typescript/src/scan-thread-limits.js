/**
 * Resource limits for the thread the scanner runs in.
 *
 * TypeScript builds a program by recursing once per import
 * (`processImportedModules` -> `findSourceFile` -> module resolution -> host
 * callbacks). On Node's default stack that overflows somewhere between 1000 and
 * 1500 files deep in a linear import chain, and plain `tsc` overflows the same
 * way. A thread with a 64 MB stack scanned a 10,500-deep chain in about 3 s, so
 * the limit moves past any import chain a real project has.
 */
export const SCAN_THREAD_STACK_MB = 64;

/**
 * Test-only: lowers the thread's stack so a short import chain overflows it.
 * Production never sets it; the core's worker command passes no such variable.
 */
export const TEST_STACK_MB_VARIABLE =
    "KNOSSOS_TYPESCRIPT_TEST_SCAN_THREAD_STACK_MB";

/**
 * Test-only: caps the thread's old generation so a scan exhausts it. Only takes
 * effect when the process runs without `--max-old-space-size`: V8 flags are
 * process-wide and win over a thread's own resource limit.
 */
export const TEST_HEAP_MB_VARIABLE =
    "KNOSSOS_TYPESCRIPT_TEST_SCAN_THREAD_HEAP_MB";

/**
 * The `resourceLimits` to start the scanner thread with.
 *
 * The old generation mirrors the process's `--max-old-space-size`, the cap the
 * core sizes the TypeScript worker's memory by. The flag value is used rather
 * than `v8.getHeapStatistics().heap_size_limit`, which adds the young
 * generation on top and would hand the thread a larger old generation than the
 * process was given. V8 applies the flag to every isolate anyway, so this
 * states the inherited cap explicitly instead of relying on that precedence.
 * Without the flag the thread keeps V8's default, as the process does.
 *
 * @param {{execArgv?: string[], nodeOptions?: string, env?: Record<string, string|undefined>}} source
 * @returns {{stackSizeMb: number, maxOldGenerationSizeMb?: number}}
 */
export function scanThreadResourceLimits({
    execArgv = [],
    nodeOptions = "",
    env = {},
} = {}) {
    const limits = {
        stackSizeMb:
            positiveNumber(env[TEST_STACK_MB_VARIABLE]) ?? SCAN_THREAD_STACK_MB,
    };
    const heapMb =
        positiveNumber(env[TEST_HEAP_MB_VARIABLE]) ??
        maxOldSpaceSize([...splitOptions(nodeOptions), ...execArgv]);
    if (heapMb !== undefined) limits.maxOldGenerationSizeMb = heapMb;
    return limits;
}

// Node applies NODE_OPTIONS first and the command line after it, so the last
// occurrence across both is the one in force.
function maxOldSpaceSize(args) {
    let found;
    for (let i = 0; i < args.length; ++i) {
        const match = /^--max[-_]old[-_]space[-_]size(?:=(.*))?$/.exec(args[i]);
        if (match === null) continue;
        const value = positiveNumber(match[1] ?? args[i + 1]);
        if (value !== undefined) found = value;
    }
    return found;
}

function splitOptions(nodeOptions) {
    return nodeOptions.split(/\s+/).filter((option) => option !== "");
}

function positiveNumber(value) {
    if (typeof value !== "string" || value.trim() === "") return undefined;
    const number = Number(value);
    return Number.isFinite(number) && number > 0 ? number : undefined;
}
