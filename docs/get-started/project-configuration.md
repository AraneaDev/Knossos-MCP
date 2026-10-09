# Project configuration

A `knossos.json` in the root of a project tells Knossos what to skip, where the
boundaries are, which rules to check and how much a scan may spend. Every scan
reads it, from the CLI, the MCP server or the watcher. You need one when the
defaults scan too much, or when you want [boundaries and policies](../concepts/architecture-rules.md)
that belong to the repository and not to whoever scans it.

Use either `knossos.json` or `knossos.jsonc`. Keeping both is an error.
JSONC accepts comments and trailing commas without changing string contents.

## A minimal file

```json
{
    "$schema": "./schemas/project-config-v1.schema.json",
    "version": 1
}
```

`version` is required and must be `1`. The `$schema` line gives editors
completion; the schema is
[`project-config-v1.schema.json`](../../schemas/project-config-v1.schema.json).

This repository's own [`knossos.json`](../../knossos.json) is a worked example
with ignores, boundaries and deny policies.

## Settings

Unknown keys are an error. These are the keys:

| Key                      | Shape                           | What it does                                                  |
| ------------------------ | ------------------------------- | ------------------------------------------------------------- |
| `ignores`                | up to 100 strings               | Relative patterns to skip, on top of the built-in exclusions. |
| `limits`                 | object                          | Discovery and worker bounds, below.                           |
| `boundaries`             | up to 50 objects                | Named groups of components, by path or by PHP namespace.      |
| `frameworks`             | up to 20 strings                | Hints for static analysis of a supported framework.           |
| `snapshot_retention`     | integer, 0 to 20                | How many historical snapshots to keep.                        |
| `policies`               | up to 50 objects                | Architecture rules between boundaries.                        |
| `quality_budgets`        | object of integers, 0 to 100000 | Allowed regressions for the quality gate.                     |
| `dead_code_suppressions` | up to 200 strings               | Components to leave out of the dead-code candidates.          |

### Ignores

Patterns are relative to the project root. Absolute paths and parent traversal
(`..`) are rejected, and a pattern is at most 500 bytes. The built-in
exclusions come in two groups.

These apply anywhere in the tree, before your patterns, and a `!` pattern
cannot re-include them:

- dependency and tool directories: `vendor`, `node_modules`, `.next`, `.nuxt`,
  `.venv`, `venv`, `__pycache__`, `.tox`, `.mypy_cache`, `.pytest_cache`,
  `.pnpm-store`, `.yarn`, `.stryker-tmp`, `.worktrees` and the VCS and IDE
  folders `.git` and `.idea`;
- Knossos's own namespace: `.knossos` and anything starting with `.knossos-`,
  such as `.knossos-src` and `.knossos-ci`;
- Laravel IDE Helper stubs (`_ide_helper*`), the VitePress `.vitepress/cache`
  and `.vitepress/dist` directories, and the Laravel paths `public/build`,
  `storage/framework`, `storage/attachments`, `storage/debugbar` and
  `storage/logs` at the project root;
- minified bundles (`*.min.js`, `*.min.mjs`, `*.min.cjs`), and JavaScript
  compiled beside its source: a `.js` ending with a `sourceMappingURL` comment
  next to a same-named `.ts`.

Build output, `build`, `dist`, `coverage` and `site`, is skipped only directly
under the project root or directly under a manifest root: a directory holding
`composer.json`, `package.json`, `pyproject.toml`, `Cargo.toml`,
`requirements*.txt` or `tsconfig*.json`. A directory of that name anywhere else,
such as `src/build` or `apps/site` when `apps` holds no manifest, is scanned.
Each skipped directory is reported as an `info` diagnostic,
`DISCOVERY_BUILD_OUTPUT_SKIPPED`, unless your own patterns or a `.gitignore`
already leave it out. Your patterns decide after this rule, so a `!` pattern
re-includes build output: `"!dist"` scans every anchored `dist`, and
`"!packages/a/dist/**"` scans that one.

The configuration file itself cannot be ignored. It is part of the scanner
configuration fingerprint, so changing it invalidates the cached contributions.

### Limits

| Key                     | Range                         | Meaning                                               |
| ----------------------- | ----------------------------- | ----------------------------------------------------- |
| `max_files`             | 1 to 100,000                  | Files discovered per scan.                            |
| `max_file_bytes`        | 1 to 100,000,000              | Size of the largest file a worker reads.              |
| `worker_timeout_ms`     | 1,000 to 120,000 (30,000)     | How long a worker may stay silent during one request. |
| `worker_memory_mb`      | 64 to 65,536                  | Heap cap for a language worker.                       |
| `watch_scan_timeout_ms` | 10,000 to 3,600,000 (300,000) | How long one scan of the shared watcher may run.      |

Every file a worker reports and every heartbeat restarts the `worker_timeout_ms`
wait, and no request runs longer than 120,000 milliseconds. When a worker hits
its heap cap, the scan error names `limits.worker_memory_mb` as the fix.

`watch_scan_timeout_ms` bounds each scan of the [shared
watcher](../operate/watch-mode.md#shared-mode) the Claude Code mod starts. It
is a project key rather than a flag because the mod's hooks start that watcher.
A scan past the limit is stopped and retried, and the watcher stops after 3
timeouts in a row. The watcher reads the value before each scan. Like any edit
to this file, changing it changes the configuration fingerprint, so the next
scan analyses the project again.

### Boundaries

```json
{
    "boundaries": [
        { "name": "core", "path_prefix": "src" },
        { "name": "billing", "namespace_prefix": "App\\Billing" }
    ]
}
```

Each boundary has a unique `name` and exactly one of `path_prefix` (project
relative) or `namespace_prefix`. Policies refer to boundaries by name.

### Frameworks

`laravel`, `symfony`, `django`, `fastapi`, `flask`, `nextjs`, `nestjs`, `react`,
`vue`, `axum`, `actix` and `rocket`. Any other value fails the scan.

### Policies

A policy has an `id`, a `from_boundary`, and at least one of `allow_targets` or
`deny_targets`, each a list of boundary names. An optional `edge_kinds` list
limits it to some relationship kinds. [Architecture rules](../concepts/architecture-rules.md)
explains how they are checked.

### Quality budgets

The supported keys are `new_cycles`, `boundary_violations`, `error_diagnostics`,
`warning_diagnostics`, `hub_degree_growth`, `unreferenced_candidates` and
`public_surface_changes`. Each is a count the quality gate tolerates against a
baseline snapshot. See [change review](../concepts/change-review.md).

### Dead-code suppressions

Each entry is a canonical name, matched exactly or, with a trailing `*`, as a
prefix. Matching components are omitted from the `architecture_health` dead-code
candidates, and `bounds.suppressed_candidates` reports how many were. A bare
`"*"` suppresses every candidate in the project, so do not commit it. To mark
one component as a false positive without editing the file, use
[an annotation](../agents/agent-integration.md#component-annotations).

## Precedence

Values resolve in this order:

1. An explicit CLI or MCP scan argument.
2. The checked-in configuration.
3. The built-in default.

`--snapshot-retention=0` overrides a configured value, and an explicit empty
`boundaries` list in an MCP scan disables the configured boundaries for that
scan. Omitted arguments inherit the file.

A scan result reports the configuration source, the precedence rule, the
framework hints, the policy count, the reviewed quality budgets, and the
effective worker timeout and stream limits. It never copies absolute project
roots into that metadata.

## Validation

A configuration that breaks a rule fails before discovery, with a stable
diagnostic prefix:

| Prefix                               | Cause                                                                                         |
| ------------------------------------ | --------------------------------------------------------------------------------------------- |
| `PROJECT_CONFIG_AMBIGUOUS`           | Both `knossos.json` and `knossos.jsonc` exist.                                                |
| `PROJECT_CONFIG_UNKNOWN_KEY`         | A key outside the lists above, at any level.                                                  |
| `PROJECT_CONFIG_VERSION_UNSUPPORTED` | `version` is missing or not `1`.                                                              |
| `PROJECT_CONFIG_INVALID`             | A value out of range, an unsupported framework, a malformed boundary, policy or budget.       |
| `PROJECT_CONFIG_UNSAFE`              | A path matcher that is absolute or climbs out of the project, or a file over 1,000,000 bytes. |
| `PROJECT_CONFIG_UNREADABLE`          | The file exists and cannot be read.                                                           |

The file is never executed.
