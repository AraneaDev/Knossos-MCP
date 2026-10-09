/**
 * Node's built-in modules, as Node 24 lists them in `module.builtinModules`.
 *
 * Checked in rather than read from the running Node, so a project's graph
 * does not change with the Node version that runs the worker: Node 22.8 did
 * not know `node:sqlite`, which Node 22.17 does.
 */
const BUILTINS = new Set([
    "_http_agent",
    "_http_client",
    "_http_common",
    "_http_incoming",
    "_http_outgoing",
    "_http_server",
    "_stream_duplex",
    "_stream_passthrough",
    "_stream_readable",
    "_stream_transform",
    "_stream_wrap",
    "_stream_writable",
    "_tls_common",
    "_tls_wrap",
    "assert",
    "assert/strict",
    "async_hooks",
    "buffer",
    "child_process",
    "cluster",
    "console",
    "constants",
    "crypto",
    "dgram",
    "diagnostics_channel",
    "dns",
    "dns/promises",
    "domain",
    "events",
    "fs",
    "fs/promises",
    "http",
    "http2",
    "https",
    "inspector",
    "inspector/promises",
    "module",
    "net",
    "os",
    "path",
    "path/posix",
    "path/win32",
    "perf_hooks",
    "process",
    "punycode",
    "querystring",
    "readline",
    "readline/promises",
    "repl",
    "stream",
    "stream/consumers",
    "stream/promises",
    "stream/web",
    "string_decoder",
    "sys",
    "timers",
    "timers/promises",
    "tls",
    "trace_events",
    "tty",
    "url",
    "util",
    "util/types",
    "v8",
    "vm",
    "wasi",
    "worker_threads",
    "zlib",
]);

/** The built-ins Node offers only under the `node:` prefix. */
const PREFIX_ONLY = new Set(["sea", "sqlite", "test", "test/reporters"]);

/**
 * The package a `node:` specifier names, or null when it names no built-in.
 *
 * A built-in Node also offers without the prefix is named without it, so
 * `node:fs` and `fs` are one package and `node:fs/promises` is `fs`. One
 * offered only under the prefix keeps it (`node:test`), since without it the
 * name is an npm package's.
 *
 * @param {string} specifier a specifier starting with `node:`
 * @returns {string|null}
 */
export function nodeBuiltinPackage(specifier) {
    const name = specifier.slice("node:".length);
    const top = name.split("/")[0];
    if (PREFIX_ONLY.has(name)) return `node:${top}`;
    return BUILTINS.has(name) ? top : null;
}
