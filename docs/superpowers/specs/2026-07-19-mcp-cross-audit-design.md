# Knossos ↔ Chaos cross-audit, MCP registration, and compose

Date: 2026-07-19
Status: approved, not yet implemented
Repos: `/root/Knossos-MCP` (primary), `/root/Chaos-MCP`

## Goal

Run each MCP server against the other's codebase and act on both kinds of
result: findings about the target repository, and defects in the tool that
surfaced while producing them. Dogfooding pays twice, and the second payoff is
the one that is normally discarded.

A precondition of the exercise is that Knossos is reachable over MCP the same
way Chaos already is, so the comparison is between two MCP servers rather than
between an MCP server and a CLI.

## Part 1 — MCP enablement and compose (Knossos only)

### 1.1 Registration

Knossos already implements MCP stdio (`knossos serve`, `src/Mcp/StdioServer.php`)
and a Streamable HTTP profile (`bin/http-router.php`). A smoke test over stdio
returned protocol `2025-11-25`, a populated `tools/list`, and `readOnlyHint`
annotations. No server code is required — this is registration and packaging.

Commit a project-scoped `.mcp.json` at the Knossos repo root:

```json
{
    "mcpServers": {
        "knossos": {
            "command": "php",
            "args": ["bin/knossos", "serve", "--allow-root=."]
        }
    }
}
```

Project-scoped rather than `claude mcp add`, because `claude mcp add` is
imperative, user-scoped, and uncommitted; contributors dogfooding their own
server is the point of the file existing.

Relative allowed roots work today and need no code change. `RootGuard::resolve()`
calls `realpath()` on each configured root (`src/Discovery/RootGuard.php:21`), so
roots resolve against the server process's working directory. Verified from
`/root/Chaos-MCP`:

- `--allow-root=.` resolves to `/root/Chaos-MCP`
- `/root/Knossos-MCP` is rejected as outside that root
- `--allow-root=../Knossos-MCP` resolves to `/root/Knossos-MCP`

This makes the committed registration fully portable: no absolute paths, valid
on any contributor's checkout.

**Assumption to verify, not assume:** that the MCP client launches stdio servers
with the working directory set to the project root. This is checked empirically
after registration. If it does not hold, the fallback is `--allow-root=${PWD}`,
since `.mcp.json` performs environment expansion. The `bin/knossos` argument is
relative for the same reason: a wrong working directory fails immediately and
loudly instead of mis-scoping the grant silently.

**Second root stays out of the committed file.** The cross-audit needs
`--allow-root=../Chaos-MCP`, which is correct for this work and wrong to ship to
everyone. It goes in a local, uncommitted `.mcp.json` override.

**Explicitly rejected:** defaulting allowed roots to the working directory when
`--allow-root` is omitted. Omission is currently a hard error
(`src/Cli/Command/ServeCommand.php:32`) and should stay one. The flag is the
security boundary; a server that silently grants access to wherever it was
launched has a materially different posture. `--allow-root=.` provides the same
ergonomics while keeping the grant explicit.

`docs/INSTALLATION.md` gains the `claude mcp add` one-liner for external users
and a note that `--allow-root` is a security boundary, not a convenience flag.

### 1.2 docker-compose.yml

One compose file at the Knossos repo root, one `knossos-data` volume, three
services:

| Service        | Profile | Shape                                                                                                                        |
| -------------- | ------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `knossos`      | default | One-shot CLI: `docker compose run --rm knossos scan /workspace --json`. Source bind-mounted read-only, `network_mode: none`. |
| `knossos-mcp`  | `mcp`   | stdio server, `stdin_open: true`, `tty: false`. Invoked as `docker compose run --rm -T knossos-mcp`.                         |
| `knossos-http` | `http`  | Loopback HTTP. Ports bound `127.0.0.1:8080:8080` only, bearer token required via environment, never started by default.      |

Both server services sit behind opt-in profiles so that a bare
`docker compose up` cannot silently expose a port.

The source mount uses `${KNOSSOS_SOURCE:-${PWD}}` with an `.env.example`. The
relative-path trick from 1.1 does not transfer: relative paths in a compose bind
mount resolve against the compose file's directory, not the invocation working
directory, so interpolation is the only mechanism available. Documentation notes
that PowerShell does not export `PWD`, so Windows users set `KNOSSOS_SOURCE`
explicitly.

The `knossos-http` service carries an inline comment pointing at
`docs/HTTP-THREAT-MODEL.md`, which states that PHP's development server is
single-process and unsuitable for hostile traffic. Compose makes "always up"
easy, so that caveat must be impossible to miss at the point of use.

## Part 2 — the cross-audit

### Phase A: Knossos analyses Chaos-MCP

Scan `/root/Chaos-MCP`, then run the read-only query suite:
`architecture-summary`, `file-metrics`, `dependency-cycles`,
`architecture-health`, `list-boundaries`, and `architecture-context` on the
hottest files.

Chaos is roughly twenty flat TypeScript modules in a single directory. The
cluster of `handler`, `triage-handler`, `estimate-handler`, `enrich`, `format`,
and `tool-context` is the shape in which import cycles and an oversized module
typically hide.

First check, before any query: that the scan ignores `node_modules/`, `build/`,
and `coverage/`. If it does not, that is Knossos finding number one.

### Phase B: Chaos analyses Knossos

Three attempts, each preceded by `estimate_audit`, whose accuracy against an
unfamiliar repository is itself under test.

1. **Control run.** One `estimate_audit` against a Chaos file, to establish that
   the tool functions in this environment. Without it, every Knossos failure is
   unattributable.
2. **PHP** (`src/**`). pcov is present, but Infection is not installed and the
   Knossos suite is a custom `php tests/run.php` rather than PHPUnit. Failure is
   expected; what is under test is whether the error names the real cause or
   something misleading.
3. **TypeScript worker** (`workers/typescript/src`). The worker uses vitest 3.x,
   which the Chaos README itself flags as incompatible with StrykerJS 9.x.
   Failure is expected; what is under test is whether Chaos detects and explains
   the incompatibility or simply relays Stryker's output.

Watch item: Chaos copies the workspace into a sandbox, and Knossos carries
`vendor/`, `node_modules/`, and `coverage/`. The copy is timed rather than
eyeballed. An unbounded or slow copy is a defect, not an inconvenience.

Knossos is not reshaped to suit the expectations of Chaos's engines. Blockers
are evidence, not obstacles to route around.

### Phase C: triage

Findings are sorted into four buckets: Chaos source, Knossos source, Chaos tool
defect, Knossos tool defect.

Every finding is fact-checked against actual source before it is asserted. False
positives are discarded outright rather than softened into weak findings. Reports
are written to `docs/audits/` in the respective repository.

### Phase D: implement

Findings go past the user one at a time. Each repository gets its own branch and
one commit per finding, tests written first.

Verification gates: `composer check` for Knossos, `npm run check` for Chaos.
Chaos mutation runs use `npm run mutation -- <target>`; a full suite per mutant
is never run.

## Calibration, recorded in advance

Stated before the run so that accuracy can be judged afterwards:

- Chaos source: import cycles and one oversized module.
- Chaos tool: poor-to-mediocre error messages on both blocked engines.
- Knossos tool: dead-code false positives on MCP entrypoints, which are reached
  only through the JSON-RPC dispatch table and so are invisible to static call
  analysis.

The `ResultEnricher` and verbosity work in the three most recent Knossos commits
has never faced a foreign repository. It is the highest-yield surface in the
plan.

## Out of scope

- Reshaping either repository to suit the other's tooling.
- Any source change before the specific finding is approved.
- A compose file for Chaos. Chaos depends on host language toolchains and copies
  the workspace into a sandbox; containerizing it is a design question in its own
  right, not a wrapper.
- Publishing, releasing, or version bumps in either repository.
