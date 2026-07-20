# Knossos MCP

Knossos is a local-first MCP server that scans a repository once and answers
architecture questions from an evidence-backed graph, so an agent stops
re-reading the whole source tree to work out what depends on what.

Every fact points back to a file and a source location. Facts that static
analysis cannot prove are labelled with their confidence and origin instead of
being guessed. Nothing in the scan pipeline installs dependencies, imports a
module, or boots an application framework.

## What you can ask after one scan

- What are the major modules, entry points, and boundaries?
- What depends, directly or transitively, on `UserRepository`?
- How can a checkout request reach invoice generation?
- Which relationships cross a declared boundary policy?
- Where would a refunds feature fit the existing structure?
- What did this branch's changed files just put at risk?

Every capability is available both as an MCP tool and as an equivalent CLI
command. See the [documentation index](docs/README.md) for the full map.

## Worked example

Scanning this repository takes about four seconds and yields a graph you can
interrogate. Output below is real, abridged with `…`.

```console
$ knossos scan . --json
{"summary":"Scanned 219 files into 1699 nodes and 5943 relationships.",
 "data":{"files":219,"nodes":1699,"edges":5943,"diagnostics":19,"mode":"full",
 "scanner_metadata":{"knossos.php":{"files_scanned":174},
   "knossos.typescript":{"files_scanned":33,"programs":9},
   "knossos.python":{"files_scanned":12,"parser":"python.ast"}},
 "metrics":{"elapsed_ms":3981.4, …}}}
```

Orient yourself in a codebase you have never opened:

```console
$ knossos architecture-summary project_1b4f41… --json
{"summary":"Knossos-MCP contains 1699 nodes and 5943 relationships.",
 "data":{"node_kinds":[{"kind":"method","count":803},{"kind":"class","count":185},
   {"kind":"function","count":121},{"kind":"interface","count":12},
   {"kind":"route","count":10}, …]}}
```

Ask what breaks if you change an interface. Each dependant carries the edge that
justifies it and the exact source line, so the answer is checkable:

```console
$ knossos impact-analysis project_1b4f41… 'Knossos\Scanner\ScannerClient' --json
{"summary":"Found 5 potential static dependants within depth 4.",
 "data":{"direct_dependants":[{"node":{"display_name":"ProcessScannerClient"},
   "distance":1,"path_confidence":"certain",
   "via":{"kind":"implements","origin":"ast",
     "explanation":"ProcessScannerClient depends through --implements (certain, ast)--> ScannerClient",
     "evidence":{"path":"src/Scanner/Worker/ProcessScannerClient.php","start_line":10}}}, …]}}
```

Find refactor targets without shelling out to `wc` and `find`:

```console
$ knossos file-metrics project_1b4f41… --limit=3 --json
{"summary":"3 of 219 files by line_count desc.",
 "data":{"files":[{"path":"tests/run.php","language":"php","line_count":5200},
   {"path":"src/Mcp/ToolService.php","language":"php","line_count":1046},
   {"path":"workers/typescript/src/scanner.js","language":"javascript","line_count":1007}]}}
```

Answers that rest on inference say so. `impact-analysis` returns the warning
"Impact is a conservative static blast radius; it does not guarantee that a
dependant will break", and dead-code candidates report absence of evidence
rather than proven absence.

## Tools

Twenty-five MCP tools, each with an equivalent CLI command. Read tools are
annotated read-only and idempotent; the two deletion tools are annotated
destructive and preview unless you pass `execute`.

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

| MCP tool               | CLI                    | Answers                                                   |
| ---------------------- | ---------------------- | --------------------------------------------------------- |
| `find_component`       | `find-component`       | Ranked candidates when you only know part of a name.      |
| `inspect_component`    | `inspect-component`    | One component's roles, boundary, relations, and evidence. |
| `architecture_summary` | `architecture-summary` | A one-call overview by language and node/edge kind.       |
| `search_architecture`  | `search-architecture`  | Components filtered by kind, role, boundary, confidence.  |
| `file_metrics`         | `file-metrics`         | Files ranked by line count or path, filterable.           |
| `list_boundaries`      | `list-boundaries`      | How the codebase is partitioned, explicitly or inferred.  |

**Structure and change analysis**

| MCP tool               | CLI                    | Answers                                                        |
| ---------------------- | ---------------------- | -------------------------------------------------------------- |
| `impact_analysis`      | `impact-analysis`      | What depends on a symbol, with the edge that proves it.        |
| `explain_flow`         | `explain-flow`         | How A reaches B, as ranked evidence-backed paths.              |
| `dependency_cycles`    | `dependency-cycles`    | Circular dependencies as bounded strongly connected groups.    |
| `architecture_health`  | `architecture-health`  | Hubs, hotspots, and uncertainty-labelled dead-code candidates. |
| `check_architecture`   | `check-architecture`   | Which relationships violate declared boundary policies.        |
| `suggest_location`     | `suggest-location`     | Where new code for a feature belongs, with visible factors.    |
| `change_impact`        | `change-impact`        | Static blast radius weighted by recent Git churn.              |
| `changed_files_impact` | `changed-files-impact` | What a set of changed files — or your working tree — touches.  |
| `architecture_context` | `architecture-context` | A bounded task-shaped evidence bundle for a coding task.       |
| `export_diagram`       | `export-diagram`       | Mermaid or PlantUML source for the current graph.              |

**Maintenance**

| MCP tool              | CLI                   | Answers                                                    |
| --------------------- | --------------------- | ---------------------------------------------------------- |
| `remove_project`      | `remove-project`      | Delete a project and its graph. Previews unless confirmed. |
| `cleanup_stale_scans` | `cleanup-stale-scans` | Drop failed, cancelled, or abandoned scan records.         |
| `maintain_database`   | `maintain-database`   | Integrity check, checkpoint, optimize, or atomic backup.   |

CLI-only helpers round this out: `doctor` verifies the runtime, workers,
protocol, and database; `watch` rescans on change; `export-bundle` and
`import-bundle` move a graph between databases; `serve` starts the MCP server.
Full input schemas are in the [MCP tool reference](docs/reference/mcp-tools.md)
and [CLI reference](docs/reference/cli.md).

## Quick start

The recommended distribution is Docker: it pins PHP 8.4, Node 24, Python,
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

| Language              | Extraction                                                             | Framework enrichment                                                               |
| --------------------- | ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| PHP 8.3–8.4           | Declarations, inheritance, calls, construction, types, injection       | [Laravel](docs/languages/php-laravel.md), [Symfony](docs/languages/php-symfony.md) |
| TypeScript/JavaScript | Compiler symbol resolution, imports, calls, types, project references  | [Next.js, React, Vue, stores, endpoints](docs/languages/typescript.md)             |
| Python 3.11–3.13      | Standard-library AST in an isolated interpreter; never imports modules | [FastAPI, Django, Celery](docs/languages/python.md)                                |

Mixed repositories reconcile into one graph. Third-party scanners plug in as
isolated worker processes through the [scanner SDK](docs/reference/scanner-sdk.md).

## Safety model

- Scanning never installs dependencies, executes project code, or boots a
  framework; workers are supervised, resource-capped, and their output is
  untrusted until it passes schema and limit validation.
- `--allow-root` is a security boundary, not a convenience flag. `serve` refuses
  to start without one.
- The SQLite database is derived and rebuildable; source mounts stay read-only.
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
