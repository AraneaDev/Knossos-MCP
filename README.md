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
[![Status](https://img.shields.io/badge/status-in%20development-orange)](#quick-start)

</div>

> **Knossos** (Κνωσός) is the Bronze Age palace at the heart of Minoan Crete, a complex so
> sprawling that Greek myth remembered it as the Labyrinth: the maze Daedalus built for the
> Minotaur, which no one could navigate without a thread to follow back out. Ariadne handed
> Theseus that thread.

Knossos-MCP is a local-first MCP server that scans a repository once and answers architecture
questions from an evidence-backed graph, so an agent stops re-reading the whole source tree to
work out what depends on what. It is the thread through your own labyrinth.

Every fact points back to a file and a source location. Facts that static
analysis cannot prove are labelled with their confidence and origin instead of
being guessed. Nothing in the scan pipeline installs dependencies, imports a
module, or boots an application framework. The Git-backed diff tools do invoke
`git` inside the project, with repository-controlled command hooks
(`core.fsmonitor`, `core.hooksPath`, `diff.external`, and any
`.gitattributes` filter/textconv driver) forced off, `core.pager` neutralised
by `--no-pager` at each call site, and a minimal environment. See [the HTTP
threat model](docs/operations/http-threat-model.md).

> **Status:** pre-release. Knossos-MCP is **not yet published to Packagist or any container
> registry**. The source is public on [GitHub](https://github.com/AraneaDev/Knossos-MCP), so
> build from source (see [Quick start](#quick-start)). Image names such as `knossos-mcp:dev`
> in this README are built locally by you; there is no `docker pull` to fetch them yet.

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

Every query and analysis capability is available both as an MCP tool and as an
equivalent CLI command; `watch`, `serve`, and the graph-bundle commands remain
CLI-only. See the [documentation index](docs/README.md) for the full map.

## Worked example

Scanning this repository takes about ten seconds and yields a graph you can
interrogate. Output below is real, abridged with `…`.

```console
$ knossos scan . --json
{"summary":"Scanned 402 files into 4965 nodes and 23126 relationships.",
 "data":{"files":402,"nodes":4965,"edges":23126,"diagnostics":20,"mode":"full",
 "scanner_metadata":{"knossos.php":{"files_scanned":353},
   "knossos.typescript":{"files_scanned":34,"programs":9},
   "knossos.python":{"files_scanned":15,"parser":"python.ast"}},
 "metrics":{"elapsed_ms":10541.0, …}}}
```

Orient yourself in a codebase you have never opened:

```console
$ knossos architecture-summary project_1b4f41… --json
{"summary":"Knossos-MCP contains 4965 nodes and 23126 relationships.",
 "data":{"node_kinds":[{"kind":"method","count":3243},{"kind":"class","count":426},
   {"kind":"function","count":138},{"kind":"interface","count":15},
   {"kind":"route","count":12}, …]}}
```

Ask what breaks if you change an interface. Each dependant carries the edge that
justifies it and the exact source line, so the answer is checkable:

```console
$ knossos impact-analysis project_1b4f41… 'Knossos\Scanner\ScannerClient' --json
{"summary":"Found 37 potential static dependants within depth 4.",
 "data":{"target":{"kind":"interface","canonical_name":"Knossos\\Scanner\\ScannerClient", …},
   "dependants":[{"node":{"canonical_name":"Knossos\\Scanner\\Worker\\ProcessScannerClient", …},
     "distance":1,"path_confidence":"certain",
     "via":{"kind":"implements","origin":"ast",
       "explanation":"ProcessScannerClient depends through --implements (certain, ast)--> ScannerClient",
       "evidence":{"path":"src/Scanner/Worker/ProcessScannerClient.php","start_line":10}}}, …],
   "counts":{"by_distance":{"1":1,"2":8,"3":2,"4":26},
     "by_confidence":{"certain":12,"probable":25,"possible":0}}, …}}
```

`dependants` is a flat, BFS-ordered list rather than grouped by distance; sort
or filter on the `distance` and `path_confidence` fields directly. MCP tool
calls default to a compact verbosity that agents should prefer over `--json`:
it hoists each repeated `node` (and `target`) object into a one-time
`component_legend` keyed by canonical name, leaving a plain name string
behind wherever the node appeared, and shortens each `via` object down to
just its edge kind (e.g. `"via":"implements"`).

Find refactor targets without shelling out to `wc` and `find`:

```console
$ knossos file-metrics project_1b4f41… --limit=3 --json
{"summary":"3 of 402 files by line_count desc.",
 "data":{"files":[{"path":"tests/phpunit/Reconciliation/GraphReconcilerTest.php","language":"php","line_count":1666},
   {"path":"src/Mcp/ToolService.php","language":"php","line_count":1426},
   {"path":"tests/phpunit/Cli/CommandsTest.php","language":"php","line_count":1354}]}}
```

Answers that rest on inference say so. `impact-analysis` returns the warning
"Impact is a conservative static blast radius; it does not guarantee that a
dependant will break", and dead-code candidates report absence of evidence
rather than proven absence.

## Tools

Thirty-three MCP tools, all but `server_info` with an equivalent CLI command. Read tools are
annotated read-only and idempotent. The four write tools
(`annotate_component`, `remove_project`, `cleanup_stale_scans`, and
`maintain_database`) preview by default and only apply once called with
`execute` set; `remove_project` and `cleanup_stale_scans` are additionally
annotated destructive. The server also exposes
per-project MCP resources (`summary`, `boundaries`, `brief`) and prompts
(`orient`, `review_diff`); these are MCP-protocol surfaces with no CLI
equivalent. Agent annotations
(`intended_boundary`, `confirmed_dead`, `false_positive`, `note`) record a
durable judgment on a component that survives rescans; a `false_positive`
annotation removes that component from future dead-code candidates.

**Orientation**

| MCP tool           | CLI      | Answers                                                                                      |
| ------------------ | -------- | -------------------------------------------------------------------------------------------- |
| `server_info`      | –        | Which roots this server may read, the roots file to extend, and whether it is containerised. |
| `diagnose_runtime` | `doctor` | Whether the runtimes, scanner workers, database, and migrations are healthy.                 |

**Projects and history**

| MCP tool              | CLI                   | Answers                                                     |
| --------------------- | --------------------- | ----------------------------------------------------------- |
| `list_projects`       | `list-projects`       | Which projects are scanned, how fresh, how large.           |
| `scan_project`        | `scan`                | Build or refresh the graph (auto, full, or incremental).    |
| `list_snapshots`      | `list-snapshots`      | The retained scan history for a project.                    |
| `snapshot_diff`       | `snapshot-diff`       | What changed architecturally between two scans.             |
| `quality_gate`        | `quality-gate`        | Whether a change breaches architecture budgets, with SARIF. |
| `architecture_trends` | `architecture-trends` | How metrics moved over recent scans, plus release notes.    |

**Finding and reading components**

| MCP tool               | CLI                    | Answers                                                             |
| ---------------------- | ---------------------- | ------------------------------------------------------------------- |
| `find_component`       | `find-component`       | Ranked candidates when you only know part of a name.                |
| `inspect_component`    | `inspect-component`    | One component's roles, boundary, relations, and evidence.           |
| `list_usages`          | `list-usages`          | Every usage site of a symbol with file:line evidence.               |
| `architecture_summary` | `architecture-summary` | A one-call overview by language and node/edge kind.                 |
| `search_architecture`  | `search-architecture`  | Components filtered by kind, role, boundary, confidence.            |
| `file_metrics`         | `file-metrics`         | Files ranked by line count or path, filterable.                     |
| `list_boundaries`      | `list-boundaries`      | How the codebase is partitioned, explicitly or inferred.            |
| `export_agent_brief`   | `export-agent-brief`   | A ready-to-paste markdown orientation brief for agent memory files. |
| `list_annotations`     | `list-annotations`     | Durable agent annotations recorded on components.                   |

**Structure and change analysis**

| MCP tool               | CLI                    | Answers                                                            |
| ---------------------- | ---------------------- | ------------------------------------------------------------------ |
| `impact_analysis`      | `impact-analysis`      | What depends on a symbol, with the edge that proves it.            |
| `explain_flow`         | `explain-flow`         | How A reaches B, as ranked evidence-backed paths.                  |
| `dependency_cycles`    | `dependency-cycles`    | Circular dependencies as bounded strongly connected groups.        |
| `architecture_health`  | `architecture-health`  | Hubs, hotspots, and uncertainty-labelled dead-code candidates.     |
| `check_architecture`   | `check-architecture`   | Which relationships violate declared boundary policies.            |
| `suggest_location`     | `suggest-location`     | Where new code for a feature belongs, with visible factors.        |
| `change_impact`        | `change-impact`        | Static blast radius weighted by recent Git churn.                  |
| `changed_files_impact` | `changed-files-impact` | What a set of changed files, or your working tree, touches.        |
| `test_impact`          | `test-impact`          | Which test files statically exercise a change, ranked by distance. |
| `review_diff`          | `review-diff`          | One-call review: impact, boundary violations, gate delta, cycles.  |
| `architecture_context` | `architecture-context` | A bounded task-shaped evidence bundle for a coding task.           |
| `export_diagram`       | `export-diagram`       | Mermaid or PlantUML source for the current graph.                  |

**Maintenance**

| MCP tool              | CLI                   | Answers                                                               |
| --------------------- | --------------------- | --------------------------------------------------------------------- |
| `annotate_component`  | `annotate-component`  | Record a durable annotation on a component. Preview unless `execute`. |
| `remove_project`      | `remove-project`      | Delete a project and its graph. Previews unless `execute`.            |
| `cleanup_stale_scans` | `cleanup-stale-scans` | Drop failed, cancelled, or abandoned scan records.                    |
| `maintain_database`   | `maintain-database`   | Integrity check, checkpoint, optimize, or atomic backup.              |

Three helpers remain CLI-only: `watch` rescans on change, `export-bundle` and
`import-bundle` move a graph between databases, and `serve` starts the server
itself. `doctor` is also available over MCP as `diagnose_runtime`.
Full input schemas are in the [MCP tool reference](docs/reference/mcp-tools.md)
and [CLI reference](docs/reference/cli.md).

## Quick start

The recommended distribution is Docker: it pins PHP 8.5, Node 26, Python 3.13,
Composer, SQLite, the PHP parser, and the TypeScript compiler, so the scanned
project needs none of them.

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

Register the server with an MCP client, and grant the narrowest readable tree:

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

Docker, native, and client-specific variants are in
[installation](docs/guides/installation.md).

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
  reads a bounded query-time excerpt (≤40 lines, files ≤2MB) through the same
  root-guard as scanning and degrades to `unavailable` rather than failing, so
  it does not require write access.
- MCP stdio is the default and recommended transport. The constrained
  loopback-only Streamable HTTP profile and its deployment limits are documented
  in the [HTTP threat model](docs/operations/http-threat-model.md).
- Failed work is never activated: the last complete scan remains the queryable
  graph. See the [fault recovery matrix](docs/operations/recovery-matrix.md).

## Documentation

[docs/README.md](docs/README.md) is the index. The most-used entries:

- [Installation and MCP configuration](docs/guides/installation.md)
- [Checked-in project configuration](docs/guides/project-configuration.md)
- [Running in Docker](docs/operations/container.md)
- [CLI reference](docs/reference/cli.md) and [MCP tool reference](docs/reference/mcp-tools.md)
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
