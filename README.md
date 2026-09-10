<div align="center">

# Knossos-MCP

**The labyrinth mapped once, so nobody has to wander it again.**

[![Release](https://img.shields.io/github/v/release/AraneaDev/Knossos-MCP?label=release)](https://github.com/AraneaDev/Knossos-MCP/releases)
[![Tool page](https://img.shields.io/badge/tool%20page-aranea--development.nl-0b7285)](https://aranea-development.nl/en/tools/knossos-mcp)
[![CI](https://img.shields.io/github/actions/workflow/status/AraneaDev/Knossos-MCP/quality.yml?label=CI)](https://github.com/AraneaDev/Knossos-MCP/actions/workflows/quality.yml)
[![Coverage](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FAraneaDev%2FKnossos-MCP%2Fgh-pages%2Fcoverage.json)](https://github.com/AraneaDev/Knossos-MCP/actions/workflows/quality.yml)
[![License](https://img.shields.io/github/license/AraneaDev/Knossos-MCP?label=license&color=yellow)](./LICENSE)
[![Language](https://img.shields.io/github/languages/top/AraneaDev/Knossos-MCP)](https://github.com/AraneaDev/Knossos-MCP)
[![Last commit](https://img.shields.io/github/last-commit/AraneaDev/Knossos-MCP?label=last%20commit)](https://github.com/AraneaDev/Knossos-MCP/commits/main)
[![Conventional Commits](https://img.shields.io/badge/commits-conventional-fe5196?logo=conventionalcommits&logoColor=white)](https://www.conventionalcommits.org/)
[![MCP Observatory](https://mcpobservatory.com/servers/github:AraneaDev/Knossos-MCP/badge.svg)](https://mcpobservatory.com/servers/github:AraneaDev/Knossos-MCP/security)
[![Status](https://img.shields.io/badge/status-in%20development-orange)](#install)

</div>

> **Knossos** (Κνωσός) is the Bronze Age palace at the heart of Minoan Crete, a complex so
> sprawling that Greek myth remembered it as the Labyrinth: the maze Daedalus built for the
> Minotaur, which no one could navigate without a thread to follow back out. Ariadne handed
> Theseus that thread.

Knossos-MCP is a local-first MCP server that scans a repository once and answers architecture
questions from an evidence-backed graph, so an agent stops re-reading the whole source tree to
work out what depends on what. It is the thread through your own labyrinth.

Every fact points back to a file and a source location. Facts that static analysis cannot prove
are labelled with their confidence and origin instead of being guessed. Nothing in the scan
pipeline installs dependencies, imports a module, or boots an application framework.

> **Status:** pre-release. Knossos-MCP is **not yet published to Packagist or any container
> registry**. The source is public on [GitHub](https://github.com/AraneaDev/Knossos-MCP), so
> build from source (see [Install](#install)). Image names such as `knossos-mcp:dev`
> in this README are built locally by you; there is no `docker pull` to fetch them yet.

**Contents:** [What you can ask](#what-you-can-ask-after-one-scan) ·
[Worked example](#worked-example) · [Tools](#tools) · [Install](#install) ·
[Languages](#supported-languages) · [Safety](#safety-model) ·
[Documentation](docs/README.md)

---

## What you can ask after one scan

- What are the major modules, entry points, and boundaries?
- What depends, directly or transitively, on `UserRepository`?
- What are the exact call sites of `ScannerClient::scan`?
- How can a checkout request reach invoice generation?
- Which relationships cross a declared boundary policy?
- Which test files exercise the blast radius of this diff?
- What does this working tree risk, reviewed architecturally in one call?
- Where would a refunds feature fit the existing structure?

Every query and analysis capability is available both as an MCP tool and as an equivalent CLI
command. See the [documentation index](docs/README.md) for the full map.

## Worked example

Scanning this repository takes about six seconds and yields a graph you can
interrogate. Output below is real, abridged with `…`.

```console
$ knossos scan . --json
{"summary":"Scanned 425 files into 6126 nodes and 35667 relationships.",
 "data":{"files":425,"nodes":6126,"edges":35667,"diagnostics":0,"mode":"full",
 "scanner_metadata":{"knossos.php":{"files_scanned":394},
   "knossos.typescript":{"files_scanned":17,"programs":1},
   "knossos.python":{"files_scanned":5,"parser":"python.ast"},
   "knossos.rust":{"files_scanned":9,"parser":"rust.syn"}},
 "metrics":{"elapsed_ms":6103.6, …}}}
```

Orient yourself in a codebase you have never opened:

```console
$ knossos architecture-summary project_1b4f41… --json
{"summary":"Knossos-MCP contains 6126 nodes and 35667 relationships.",
 "data":{"node_kinds":[{"kind":"method","count":4196},{"kind":"class","count":453},
   {"kind":"function","count":311},{"kind":"module","count":51},
   {"kind":"interface","count":12}, …],
  "edge_kinds":[{"kind":"calls","count":23381},{"kind":"contains","count":4727},
   {"kind":"constructs","count":3898}, …]}}
```

Ask what breaks if you change an interface. Each dependant carries the edge that
justifies it and the exact source line, so the answer is checkable:

```console
$ knossos impact-analysis project_1b4f41… 'Knossos\Scanner\ScannerClient' --json
{"summary":"Found 100 potential static dependants within depth 4.",
 "data":{"target":{"kind":"interface","canonical_name":"Knossos\\Scanner\\ScannerClient", …},
   "dependants":[{"node":{"canonical_name":"Knossos\\Scanner\\Worker\\ProcessScannerClient", …},
     "distance":1,"path_confidence":"certain",
     "via":{"kind":"implements","origin":"ast",
       "explanation":"ProcessScannerClient depends through --implements (certain, ast)--> ScannerClient",
       "evidence":{"path":"src/Scanner/Worker/ProcessScannerClient.php","start_line":11}}}, …],
   "counts":{"by_distance":{"1":5,"2":15,"3":75,"4":5},
     "by_confidence":{"certain":100,"probable":0,"possible":0}}, …}}
```

Find refactor targets without shelling out to `wc` and `find`:

```console
$ knossos file-metrics project_1b4f41… --limit=3 --json
{"summary":"3 of 425 files by line_count desc.",
 "data":{"files":[{"path":"tests/phpunit/Reconciliation/GraphReconcilerTest.php","language":"php","line_count":2473},
   {"path":"workers/rust/src/visit.rs","language":"rust","line_count":1559},
   {"path":"workers/rust/tests/scan.rs","language":"rust","line_count":1521}]}}
```

Answers that rest on inference say so. `impact_analysis` returns the warning
"Impact is a conservative static blast radius; it does not guarantee that a
dependant will break", and dead-code candidates report absence of evidence
rather than proven absence.

MCP tool calls default to a compact verbosity that agents should prefer over `--json`: it
hoists each repeated node object into a one-time `component_legend` keyed by canonical name,
leaving a plain name string behind wherever the node appeared, and shortens each `via` object
down to just its edge kind. The same call is roughly a third of the tokens. See
[response envelopes](docs/reference/response-envelopes.md).

## Tools

Thirty-three MCP tools, all but `server_info` with an equivalent CLI command. Full input
schemas are in the [MCP tool reference](docs/reference/mcp-tools.md) and
[CLI reference](docs/reference/cli.md); each group below links to its capability guide.

**Orientation**

| MCP tool           | CLI      | Answers                                                                                      |
| ------------------ | -------- | -------------------------------------------------------------------------------------------- |
| `server_info`      | –        | Which roots this server may read, the roots file to extend, and whether it is containerised. |
| `diagnose_runtime` | `doctor` | Whether the runtimes, scanner workers, database, and migrations are healthy.                 |

**[Finding and reading components](docs/capabilities/finding-components.md)**

| MCP tool               | CLI                    | Answers                                                   |
| ---------------------- | ---------------------- | --------------------------------------------------------- |
| `list_projects`        | `list-projects`        | Which projects are scanned, how fresh, how large.         |
| `find_component`       | `find-component`       | Ranked candidates when you only know part of a name.      |
| `inspect_component`    | `inspect-component`    | One component's roles, boundary, relations, and evidence. |
| `list_usages`          | `list-usages`          | Every usage site of a symbol with file:line evidence.     |
| `architecture_summary` | `architecture-summary` | A one-call overview by language and node/edge kind.       |
| `search_architecture`  | `search-architecture`  | Components filtered by kind, role, boundary, confidence.  |
| `file_metrics`         | `file-metrics`         | Files ranked by line count or path, filterable.           |
| `list_boundaries`      | `list-boundaries`      | How the codebase is partitioned, explicitly or inferred.  |
| `export_diagram`       | `export-diagram`       | Mermaid or PlantUML source for the current graph.         |

**[Structure analysis](docs/capabilities/structure-analysis.md)**

| MCP tool              | CLI                   | Answers                                                                                     |
| --------------------- | --------------------- | ------------------------------------------------------------------------------------------- |
| `impact_analysis`     | `impact-analysis`     | What depends on a symbol, with the edge that proves it.                                     |
| `explain_flow`        | `explain-flow`        | How A reaches B, as ranked evidence-backed paths.                                           |
| `dependency_cycles`   | `dependency-cycles`   | Circular dependencies as bounded strongly connected groups.                                 |
| `architecture_health` | `architecture-health` | Hubs, hotspots, and dead code split into what nothing references and what only tests reach. |
| `suggest_location`    | `suggest-location`    | Where new code for a feature belongs, with visible factors.                                 |

**[Reviewing a change](docs/capabilities/change-review.md)**

| MCP tool               | CLI                    | Answers                                                            |
| ---------------------- | ---------------------- | ------------------------------------------------------------------ |
| `review_diff`          | `review-diff`          | One-call review: impact, boundary violations, gate delta, cycles.  |
| `changed_files_impact` | `changed-files-impact` | What a set of changed files, or your working tree, touches.        |
| `test_impact`          | `test-impact`          | Which test files statically exercise a change, ranked by distance. |
| `change_impact`        | `change-impact`        | Static blast radius weighted by recent Git churn.                  |

**[Rules, budgets, and history](docs/capabilities/architecture-rules.md)**

| MCP tool              | CLI                   | Answers                                                     |
| --------------------- | --------------------- | ----------------------------------------------------------- |
| `check_architecture`  | `check-architecture`  | Which relationships violate declared boundary policies.     |
| `quality_gate`        | `quality-gate`        | Whether a change breaches architecture budgets, with SARIF. |
| `list_snapshots`      | `list-snapshots`      | The retained scan history for a project.                    |
| `snapshot_diff`       | `snapshot-diff`       | What changed architecturally between two scans.             |
| `architecture_trends` | `architecture-trends` | How metrics moved over recent scans, plus release notes.    |

**[Agent integration](docs/capabilities/agent-integration.md)**

| MCP tool               | CLI                    | Answers                                                             |
| ---------------------- | ---------------------- | ------------------------------------------------------------------- |
| `architecture_context` | `architecture-context` | A bounded task-shaped evidence bundle for a coding task.            |
| `export_agent_brief`   | `export-agent-brief`   | A ready-to-paste markdown orientation brief for agent memory files. |
| `annotate_component`   | `annotate-component`   | Record a durable annotation on a component.                         |
| `list_annotations`     | `list-annotations`     | Durable agent annotations recorded on components.                   |

**Scanning and maintenance**

| MCP tool              | CLI                   | Answers                                                  |
| --------------------- | --------------------- | -------------------------------------------------------- |
| `scan_project`        | `scan`                | Build or refresh the graph (auto, full, or incremental). |
| `remove_project`      | `remove-project`      | Delete a project and its graph.                          |
| `cleanup_stale_scans` | `cleanup-stale-scans` | Drop failed, cancelled, or abandoned scan records.       |
| `maintain_database`   | `maintain-database`   | Integrity check, checkpoint, optimize, or atomic backup. |

Read tools are annotated read-only and idempotent. The four write tools
(`annotate_component`, `remove_project`, `cleanup_stale_scans`, and `maintain_database`)
preview by default and only apply once called with `execute` set; `remove_project` and
`cleanup_stale_scans` are additionally annotated destructive.

Eight commands are CLI-only: `version`, `serve`, `watch`, `session-brief`,
`install-agent-plugin`, `allow-root`, and the `export-bundle`/`import-bundle` pair that moves
a graph between databases. The server also exposes per-project MCP resources
(`summary`, `boundaries`, `brief`) and prompts (`orient`, `review_diff`), which have no CLI
equivalent because they are MCP-protocol surfaces.

## Install

The recommended distribution is Docker: built from digest-pinned base images, it carries PHP
8.5, Node 26, Python, Composer, SQLite, the PHP parser, the TypeScript compiler, and a
prebuilt Rust worker, so the scanned project needs none of them.

```sh
docker build -t knossos-mcp:dev .
docker run --rm knossos-mcp:dev doctor --json
```

Scan a project with networking disabled and the source mounted read-only:

```sh
docker run --rm --network none \
  --mount type=bind,source=/absolute/project,target=/workspace,readonly \
  --mount type=volume,source=knossos-data,target=/data \
  knossos-mcp:dev scan /workspace --json
```

Recover persisted project IDs later without exposing absolute roots:

```sh
docker run --rm \
  --mount type=volume,source=knossos-data,target=/data \
  knossos-mcp:dev list-projects --json
```

A native install needs PHP 8.3+ (with JSON, PDO, PDO SQLite), Node 22+, Python 3.11+,
Composer 2, and Git; Cargo 1.82+ is optional and enables the Rust scanner. Each is a floor,
not a range.

### Registering the server

Register the server once, for your user. `tools/install` writes this for you;
the shape matters more than the mechanism:

```json
{
    "mcpServers": {
        "knossos": {
            "command": "/absolute/Knossos-MCP/bin/knossos",
            "args": ["serve"],
            "env": {
                "KNOSSOS_DATA_DIR": "/absolute/knossos-data",
                "KNOSSOS_ROOTS_FILE": "/absolute/knossos-data/roots.json"
            }
        }
    }
}
```

Three things are deliberate. The command is **absolute**, so the server cannot
resolve to whatever checkout the client happened to launch it from. There is no
`--allow-root`: the roots file governs, and it is re-read per request, so
granting another project needs no restart. And `KNOSSOS_DATA_DIR` is **pinned**,
which is what keeps one graph. Leave it out and the location falls back to
`<cwd>/.knossos`, so the server, the CLI you type, and the session-brief hook
can each end up addressing a different database of the same project without
anything warning you.

Docker, native, and client-specific variants are in
[installation](docs/guides/installation.md).

### Session orientation for Claude Code

A separate, optional plugin injects a short
[session brief](docs/capabilities/session-brief.md) at the start of each Claude Code session:
whether the graph is fresh, the project's boundary rules and recorded notes, and its entry
points and hubs.

```sh
knossos install-agent-plugin           # previews; add --execute to apply
```

The hook runs whatever `knossos` binary it can find on the machine and fails silently when it
finds none, so a symlink is usually the missing step:

```sh
ln -s /absolute/Knossos-MCP/bin/knossos ~/.local/bin/knossos
```

Registering the MCP server and installing the plugin are separate steps and stay that way.
The full picture, including containerised installs and why there is no public marketplace
route, is in [the agent plugin guide](docs/guides/agent-plugin.md).

## Supported languages

| Language                                         | Extraction                                                                          | Framework enrichment                                                               |
| ------------------------------------------------ | ----------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| PHP 8.3 or newer                                 | Declarations, inheritance, calls, construction, types, injection                    | [Laravel](docs/languages/php-laravel.md), [Symfony](docs/languages/php-symfony.md) |
| TypeScript/JavaScript                            | Compiler symbol resolution, imports, calls, types, project references               | [Next.js, React, Vue, stores, endpoints](docs/languages/typescript.md)             |
| Python 3.11 or newer                             | Standard-library AST in an isolated interpreter; manifests, packages, calls, routes | [FastAPI, Django, Flask, Celery](docs/languages/python.md)                         |
| Rust 1.82 or newer, optional on a native install | `syn` parsing; Cargo manifests, cross-file impls, routes; never invokes cargo/rustc | [Details and limits](docs/languages/rust.md)                                       |

Mixed repositories reconcile into one graph. Third-party scanners plug in as
isolated worker processes through the [scanner SDK](docs/reference/scanner-sdk.md).

## Safety model

- Scanning never installs dependencies, executes project code, or boots a
  framework; workers are supervised, resource-capped, and their output is
  untrusted until it passes schema and limit validation.
- The allow-list is a security boundary, not a convenience. `serve` refuses to
  start with no root from `--allow-root`, `KNOSSOS_ALLOWED_ROOTS`, or the roots
  file. That file is re-read per request so one installation can serve every
  project; Knossos only ever reads it, so widening the boundary stays a
  deliberate act recorded on disk.
- The SQLite database is derived and rebuildable; source mounts stay read-only.
  The one exception is `architecture_context`'s opt-in `include_source`, which
  reads a bounded query-time excerpt (≤40 lines) through the same root guard as
  scanning and degrades to `unavailable` rather than failing, so it does not
  require write access.
- The Git-backed diff tools do invoke `git` inside the project, with
  repository-controlled command hooks (`core.fsmonitor`, `core.hooksPath`,
  `diff.external`, and any `.gitattributes` filter/textconv driver) forced off,
  `core.pager` neutralised by `--no-pager` at each call site, and a minimal
  environment.
- MCP stdio is the default and recommended transport. The constrained
  loopback-only Streamable HTTP profile and its deployment limits are documented
  in the [HTTP threat model](docs/operations/http-threat-model.md).
- Failed work is never activated: the last complete scan remains the queryable
  graph. See the [fault recovery matrix](docs/operations/recovery-matrix.md).

## Documentation

[docs/README.md](docs/README.md) is the index. The most-used entries:

- [Installation and MCP configuration](docs/guides/installation.md)
- [MCP tool reference](docs/reference/mcp-tools.md) and [CLI reference](docs/reference/cli.md)
- [Checked-in project configuration](docs/guides/project-configuration.md)
- [Running in Docker](docs/operations/container.md)
- [Troubleshooting and migrations](docs/operations/troubleshooting-and-migrations.md)

## Development

One versioned quality profile runs locally, in Git hooks, and in CI:

```sh
tools/quality-container fast
tools/quality-container full
```

`fast` covers linting, static analysis, formatting, hygiene, and the whole test
suite; `full` adds security audits, coverage floors, performance budgets,
mutation score, and supply-chain assurance. Details are in
[quality gates](docs/development/quality.md).

See [CONTRIBUTING.md](CONTRIBUTING.md) for the contribution workflow, the
Conventional Commit prefixes that drive releases, and how to add a language
scanner.

## License

[MIT](LICENSE).

---

Built by [Aranea Development](https://aranea-development.nl).
