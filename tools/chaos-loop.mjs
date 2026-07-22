#!/usr/bin/env node
// tools/chaos-loop.mjs
// Drive chaos-mcp over MCP-over-stdio. Used by the `tools/chaos-loop`
// shell wrapper. Paths resolve from CHAOS_MCP_ROOT (or CHAOS_MCP_BIN) so
// the driver is portable beyond /root/Chaos-MCP.
//
// Usage:
//   tools/chaos-loop estimate <filePath>
//   tools/chaos-loop audit   <filePath> [--timeoutMs <ms>] [--maxSurvivors <n>] [--verbose] [--refresh]
//   tools/chaos-loop verify  <filePath> --runId <id> [--refresh]
//
// Env:
//   CHAOS_MCP_ROOT  Chaos-MCP checkout root (default: derives from CHAOS_MCP_BIN, else /root/Chaos-MCP)
//   CHAOS_MCP_BIN   chaos-mcp entry (default: <CHAOS_MCP_ROOT>/build/index.js)
//   CHAOS_MCP_CWD   workspace root chaos-mcp runs against (default: cwd)
//
// Flags:
//   --refresh   Delete /tmp/chaos-mcp-runs before connecting so a stale runId
//               baseline does not silently regress the close-the-loop evidence.
//               Strongly recommended between "add tests" and "verify" steps.

// Resolve the Chaos-MCP checkout root from CHAOS_MCP_BIN when it lives under a
// conventional /build/<entry> layout; fall back to dirname once and warn if the
// path lacks a /build/ segment.
async function resolveChaosRoot(binPath, envRoot) {
  if (envRoot) return envRoot;
  if (!binPath) {
    // No env, no bin path: fail fast with the same npm-install hint as the
    // SDK-not-importable error path below. We do NOT hard-code /root/Chaos-MCP
    // here because that would silently succeed on machines where the dir
    // exists by coincidence but is not the intended install.
    console.error("error: cannot resolve CHAOS_MCP_ROOT.");
    console.error(
      "  set CHAOS_MCP_ROOT (or CHAOS_MCP_BIN) to the Chaos-MCP checkout root",
    );
    console.error(
      "  (the directory that contains node_modules/@modelcontextprotocol/sdk).",
    );
    console.error(
      "  Did you forget to run 'npm install' + 'npm run build' inside the checkout?",
    );
    process.exit(2);
  }
  if (/\/build\/[^/]+$/.test(binPath)) {
    return binPath.replace(/\/build\/[^/]+$/, "");
  }
  const { dirname } = await import("node:path");
  console.warn(
    `tools/chaos-loop: CHAOS_MCP_BIN=${binPath} has no /build/ segment; ` +
      `falling back to dirname(${binPath})=… for SDK lookup. ` +
      `Set CHAOS_MCP_ROOT explicitly to silence this warning.`,
  );
  return dirname(binPath);
}

const CHAOS_MCP_ROOT_DEFAULT = await resolveChaosRoot(
  process.env.CHAOS_MCP_BIN,
  process.env.CHAOS_MCP_ROOT,
);

const sdkBase = `${CHAOS_MCP_ROOT_DEFAULT}/node_modules/@modelcontextprotocol/sdk/dist/esm/client`;
let Client, StdioClientTransport;
const sdkHint = [
  `set CHAOS_MCP_ROOT (or CHAOS_MCP_BIN) to the Chaos-MCP checkout root`,
  `(the directory that contains node_modules/@modelcontextprotocol/sdk).`,
  `Did you forget to run 'npm install' inside ${CHAOS_MCP_ROOT_DEFAULT}?`,
].join("\n  ");
try {
  Client = (await import(`${sdkBase}/index.js`)).Client;
} catch (e) {
  console.error(`error: chaos-mcp SDK Client not importable at ${sdkBase}/index.js: ${e?.message ?? e}`);
  console.error(`  ${sdkHint}`);
  process.exit(2);
}
try {
  StdioClientTransport = (await import(`${sdkBase}/stdio.js`)).StdioClientTransport;
} catch (e) {
  console.error(`error: chaos-mcp SDK StdioClientTransport not importable at ${sdkBase}/stdio.js: ${e?.message ?? e}`);
  console.error(`  ${sdkHint}`);
  process.exit(2);
}

const [, , cmd, file, ...rest] = process.argv;
if (!cmd || !file) {
  console.error("Usage: chaos-loop <estimate|audit|verify> <filePath> [flags]");
  process.exit(2);
}

// Numeric flag helper: rejects values that aren't a number and aren't followed
// by another flag (so `--timeoutMs --maxSurvivors 50` doesn't capture
// "--maxSurvivors" as the timeout).
function numericFlag(name, fallback) {
  const idx = rest.indexOf(`--${name}`);
  if (idx < 0 || idx + 1 >= rest.length) return fallback;
  const next = rest[idx + 1];
  if (next.startsWith("--")) return fallback;
  const n = Number(next);
  if (!Number.isFinite(n)) {
    console.error(`error: --${name} expects a number, got ${JSON.stringify(next)}`);
    process.exit(2);
  }
  return n;
}

let toolName, args;
if (cmd === "estimate") {
  toolName = "estimate_audit";
  args = { filePath: file };
} else if (cmd === "audit") {
  toolName = "audit_code_resilience";
  const hasMaxSurvivors = rest.includes("--maxSurvivors");
  args = {
    filePath: file,
    timeoutMs: numericFlag("timeoutMs", 600000),
    outputFormat: "json",
    ...(hasMaxSurvivors ? { maxSurvivors: numericFlag("maxSurvivors", 20) } : {}),
  };
} else if (cmd === "verify") {
  const runIdIdx = rest.indexOf("--runId");
  if (runIdIdx < 0 || runIdIdx + 1 >= rest.length || rest[runIdIdx + 1].startsWith("--")) {
    console.error("error: verify requires --runId <id>");
    process.exit(2);
  }
  toolName = "audit_code_resilience";
  args = { filePath: file, runId: rest[runIdIdx + 1] };
} else {
  console.error("error: unknown command:", cmd);
  process.exit(2);
}

const passVerbose = rest.includes("--verbose");
const bin = process.env.CHAOS_MCP_BIN ?? `${CHAOS_MCP_ROOT_DEFAULT}/build/index.js`;
const cwd = process.env.CHAOS_MCP_CWD ?? process.cwd();
const spawnArgs = [bin, ...(passVerbose ? ["--verbose"] : [])];

const doRefresh = rest.includes("--refresh");
if (doRefresh) {
  const { rmSync } = await import("node:fs");
  try {
    rmSync("/tmp/chaos-mcp-runs", { recursive: true, force: true });
  } catch (e) {
    // Best-effort, but surface permission errors so the user knows the cache
    // is not actually invalidated. ENOENT is a no-op success.
    if (e?.code !== "ENOENT") {
      console.error(`warn: --refresh could not delete /tmp/chaos-mcp-runs: ${e?.message ?? e}`);
    }
  }
}

const transport = new StdioClientTransport({
  command: "node",
  args: spawnArgs,
  cwd,
  stderr: "pipe",
});

const stderrChunks = [];
if (transport.stderr && typeof transport.stderr.on === "function") {
  transport.stderr.on("data", (c) => stderrChunks.push(c));
}

const startedAt = Date.now();
const client = new Client(
  { name: "knossos-chaos-loop", version: "0.0.1" },
  { capabilities: {} }
);

try {
  await client.connect(transport);
  const t0 = Date.now();
  const result = await client.callTool({ name: toolName, arguments: args });
  const t1 = Date.now();
  process.stdout.write(
    JSON.stringify(
      {
        tool: toolName,
        arguments: args,
        elapsedMs: t1 - t0,
        totalElapsedMs: Date.now() - startedAt,
        result,
      },
      null,
      2,
    ) + "\n",
  );
} catch (err) {
  console.error("ERROR:", err?.message ?? err);
  if (stderrChunks.length) {
    console.error("---server stderr (tail)---");
    console.error(Buffer.concat(stderrChunks).toString("utf8").slice(-4000));
  }
  process.exitCode = 1;
} finally {
  await client.close().catch(() => {});
}
