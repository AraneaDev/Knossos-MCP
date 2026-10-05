<div align="center">

# Knossos

**The labyrinth mapped once: on screen beside Claude Code, and in the notes your agent reads while it works.**

[![Release](https://img.shields.io/github/v/release/AraneaDev/knossos?label=release)](https://github.com/AraneaDev/knossos/releases)
[![Tool page](https://img.shields.io/badge/tool%20page-aranea--development.nl-0b7285)](https://aranea-development.nl/en/tools/knossos-mcp)
[![CI](https://img.shields.io/github/actions/workflow/status/AraneaDev/knossos/quality.yml?label=CI)](https://github.com/AraneaDev/knossos/actions/workflows/quality.yml)
[![Coverage](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FAraneaDev%2Fknossos%2Fgh-pages%2Fcoverage.json)](https://github.com/AraneaDev/knossos/actions/workflows/quality.yml)
[![License](https://img.shields.io/github/license/AraneaDev/knossos?label=license&color=yellow)](LICENSE)
[![Language](https://img.shields.io/github/languages/top/AraneaDev/knossos)](https://github.com/AraneaDev/knossos)
[![Last commit](https://img.shields.io/github/last-commit/AraneaDev/knossos?label=last%20commit)](https://github.com/AraneaDev/knossos/commits/main)
[![Conventional Commits](https://img.shields.io/badge/commits-conventional-fe5196?logo=conventionalcommits&logoColor=white)](https://www.conventionalcommits.org/)
[![MCP Observatory](https://mcpobservatory.com/servers/github:AraneaDev/knossos/badge.svg)](https://mcpobservatory.com/servers/github:AraneaDev/knossos/security)
[![Status](https://img.shields.io/badge/status-in%20development-orange)](#installation)

</div>

> **Knossos** (Κνωσός) is the Bronze Age palace at the heart of Minoan Crete, a complex so
> sprawling that Greek myth remembered it as the Labyrinth: the maze Daedalus built for the
> Minotaur, which no one could navigate without a thread to follow back out. Ariadne handed
> Theseus that thread.

**TL;DR:** Knossos scans a repository into a local graph of its components and the
relationships between them, each with the file and line that proves it. In Claude Code,
`/knossos` opens that graph as a live pane beside your session, and the agent gets a short note
at the moment it reads, edits or commits a file that matters: how much depends on it, which
rules bind it, and which tests reach it. The same graph answers 33 MCP tools and a CLI, for
Claude Code, Codex or any MCP client.

Facts that static analysis cannot prove are labelled with their confidence and origin.
Nothing in the scan installs dependencies, imports a module or boots a framework.

> **Status:** pre-release. Knossos is **not yet published to Packagist or any container
> registry**. The source is public on [GitHub](https://github.com/AraneaDev/knossos), so install
> from a checkout (see [Installation](#installation)). Image names such as `knossos:dev` are
> built locally by you; there is no `docker pull` to fetch them yet.

---

<!-- still:hero -->

A Claude Code session: the brief at its start, `/knossos` opening the pane, a walk through its
tabs and a search, then an edit, the note the agent gets about it, and the diff in Changes.

## Features

- **The pane**: `/knossos` opens the project's architecture beside your session on eight tabs
  (Overview, Hubs, Boundaries, Cycles, Issues, Changes, Branch and Churn), with a detail for
  every component and file, its blast radius as rings, and the route between any two components
- **The band**: after a turn that edited files, one line above the prompt says how far the
  change reaches: the files, their dependents, the tests that reach them, the boundaries hit
- **Notes for the agent**: when the agent reads a heavily depended-on or policed file, edits
  one, ends a turn or makes a commit, it gets one short line about what that file or change
  carries. Each note is said once and never blocks a tool call
- **`knossos_context`**: one tool call for a file's boundary and the rules that bind it, its
  dependents, the tests that reach it, its latest commits and whether this session changed it
- **33 MCP tools**: impact analysis, call sites, flows between components, cycles, hubs,
  dead-code candidates, change review, test impact, snapshots and trends. Every tool except
  `server_info` has an equivalent CLI command
- **Evidence on every fact**: each relationship points back to a file and a line, and a path is
  only as confident as its weakest edge
- **4 languages**: PHP (with Laravel and Symfony), TypeScript and JavaScript, Python and Rust,
  reconciled into one graph for a mixed repository
- **Architecture rules and budgets**: boundary policies that say which part may depend on
  which, and quality budgets checked against a reviewed baseline, with SARIF for CI
- **A live watcher**: one per project, shared by your sessions, rescans as files change, so the
  pane and the notes follow edits made by anyone
- **Session brief and routing skill**: each session starts knowing whether the graph is fresh,
  the project's rules and recorded notes, and which questions to bring to the graph
- **Safety model**: scanning never runs project code, the server reads only allowed roots, and
  a failed scan never replaces the last good graph

## Installation

You need PHP 8.3 or newer with JSON, PDO and PDO SQLite, Node 22 or newer, Python 3.11 or newer,
Composer 2 and Git. Cargo 1.82 or newer is optional and adds Rust scanning. Without PHP on the
host, use [Docker](docs/get-started/installation.md#docker).

### Claude Code

Clone the repository, then run the installer from the project you want to scan first:

```sh
git clone https://github.com/AraneaDev/knossos.git /absolute/path/to/knossos
cd /absolute/path/to/your-project
/absolute/path/to/knossos/tools/install
```

It installs the worker dependencies, creates the data directory `~/.knossos` with a roots file
that allows this project, scans it, and registers the MCP server with Claude Code at user scope.
Re-run it from another project to add that one.

Then install the plugin, which carries the pane, the notes, the session brief and the routing
skill. Its hooks run the `knossos` on your path, and must read the data directory your server
uses:

```sh
ln -s /absolute/path/to/knossos/bin/knossos ~/.local/bin/knossos
knossos install-agent-plugin                                        # preview
knossos install-agent-plugin --data-dir="$HOME/.knossos" --execute
```

Start a new session. The brief appears at its start, and `/knossos` opens the pane. Updating,
removing and trying the plugin for one session are in [the plugin guide](docs/claude-code/plugin.md).

### Codex

Register the server with pinned data and roots paths:

```sh
codex mcp add knossos \
    --env KNOSSOS_DATA_DIR="$HOME/.knossos" \
    --env KNOSSOS_ROOTS_FILE="$HOME/.knossos/roots.json" \
    -- /absolute/path/to/knossos/tools/mcp-serve
```

A Codex plugin in this checkout adds the routing skill. The pane and the notes are Claude Code
hooks and do not run in Codex. Both steps, and a Docker variant, are in
[installation](docs/get-started/installation.md#codex).

### Any MCP client

Any client that uses the common `mcpServers` stdio shape takes this entry. Keep every path
absolute:

```json
{
    "mcpServers": {
        "knossos": {
            "command": "/absolute/path/to/knossos/tools/mcp-serve",
            "env": {
                "KNOSSOS_DATA_DIR": "/absolute/knossos-data",
                "KNOSSOS_ROOTS_FILE": "/absolute/knossos-data/roots.json"
            }
        }
    }
}
```

The roots file is the allow-list, re-read on every request, so granting another project needs
no restart: `knossos allow-root /absolute/path --execute`.

### Installation pitfalls

- **Pin the data directory.** Without `KNOSSOS_DATA_DIR`, each caller falls back to
  `<cwd>/.knossos`, and the server, the CLI and the plugin can each read a different graph of
  the same project without a warning.
- **Build the right Docker stage.** `docker build --target runtime`; a plain `docker build`
  produces the CI `quality` stage.
- **No `-t` on Docker stdio.** Use `-i` alone, because terminal framing corrupts the stream.
- **Mount projects at the same path.** In Docker, mount each project at its host path and pass
  that path to `--allow-root`.

Each one, with the fix, is in [installation pitfalls](docs/get-started/installation.md#installation-pitfalls).

## Quick start

The installer scanned your project. To scan another, allow it and scan it with the same data
directory the server uses:

```sh
export KNOSSOS_DATA_DIR="$HOME/.knossos"
knossos allow-root /absolute/path/to/project --execute
knossos scan /absolute/path/to/project
```

Then, in a Claude Code session in that project, type `/knossos`. The pane opens on Overview:

<!-- still:overview -->

Overview: what moved since the last scan, what this session touched, and how dependencies
concentrate.

<!-- still:hubs-detail -->

Hubs: the most depended-on components, with the marked one's neighbourhood beside the list.

<!-- still:cycles -->

Cycles: each dependency cycle drawn as boxes, with the hop where it crosses a boundary marked.

<!-- still:changes-diff -->

Changes: every file changed since the session began, its dependents, the tests that reach it,
and its diff.

<!-- still:branch -->

Branch: what this branch added against its merge base, from new boundary crossings to new dead
code.

<!-- still:finder -->

The finder: `f`, a few letters of a name, and Enter opens that component.

Then ask the agent a structural question, such as "what breaks if I change `UserRepository`, and
which tests would tell me?". The routing skill sends it to the graph. Every
tab and key is in [the pane guide](docs/claude-code/pane.md), and a first scan with real output
is in [first scan](docs/get-started/first-scan.md#a-worked-example).

## What your agent gets

Four notes, each one line, each said once at the moment it helps:

| note     | fires                                                                                                                          |
| -------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Read     | after a Read of a heavily depended-on or policed file                                                                          |
| edit     | after an edit of a heavily depended-on file the Read note missed                                                               |
| turn end | after a turn that edited files, once it is scanned: the boundary violations it introduced and the tests that reach its changes |
| commit   | after a commit, on what the session's changes leave behind                                                                     |

After a commit, for example:

```text
knossos: this session's changes carry 1 changed file no test reaches (src/Kernel.php); 1 dependency cycle new since the session began (Router → Kernel). Check them before you push.
```

And the agent can ask about one file with `knossos_context` before it edits it:

```text
src/Query/ResultEnvelope.php: boundary core, PHP, 96 lines, 7 components.
Dependents: 47 files in core, tests; closest: src/Query/DashboardService.php, src/Mcp/ToolService.php, src/Query/BriefService.php, src/Cli/QueryCommand.php, src/Query/RescanService.php, and 42 more.
Rules: core may not depend on php-worker, tests.
Tests that reach it: tests/phpunit/Query/ResultEnvelopeTest.php (1 hop), tests/phpunit/Mcp/McpTest.php (2 hops).
Latest commits: d2bde05 2026-10-04 fix(query): keep meta when null.
This session has not changed it.
```

Every note, its exact wording and its limits are in [notes for the model](docs/claude-code/agent-notes.md).
Over MCP, in any client, the 33 tools answer questions such as:

- What depends, directly or transitively, on `UserRepository`?
- What are the exact call sites of `ScannerClient::scan`?
- How can a checkout request reach invoice generation?
- Which relationships cross a declared boundary policy?
- Which test files exercise the blast radius of this diff?
- Where would a refunds feature fit the existing structure?

Each group of tools has its own page, linked from the [docs index](docs/README.md), and every
schema is in the [MCP tool reference](docs/reference/mcp-tools.md).

## CLI and CI

Every query is also a CLI command, with `--json` for scripts:

```sh
knossos impact-analysis <project-id> 'App\Billing\Invoice'
knossos review-diff <project-id> --base-ref=main --policies=architecture-policies.json
knossos quality-gate <project-id> <baseline-snapshot> --budgets=knossos-budgets.json --sarif --json
knossos watch /absolute/path/to/project
```

Exit code `0` means every evaluated gate passed, `1` that a gate failed, and `2` that the result
could not be evaluated. See [CI and editor integration](docs/operate/ci-editor-integration.md),
[rules and budgets](docs/concepts/architecture-rules.md), [watch mode](docs/operate/watch-mode.md)
and the [CLI reference](docs/reference/cli.md).

## Supported languages

| Language                                         | Extraction                                                                                         | Framework enrichment                                                               |
| ------------------------------------------------ | -------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| PHP 8.3 or newer                                 | Declarations, inheritance, calls, construction, types, injection                                   | [Laravel](docs/languages/php-laravel.md), [Symfony](docs/languages/php-symfony.md) |
| TypeScript/JavaScript                            | Compiler symbol resolution, imports, calls, types, project references, Vue/Svelte/Astro components | [Next.js, React, Vue, stores, endpoints](docs/languages/typescript.md)             |
| Python 3.11 or newer                             | Standard-library AST in an isolated interpreter; manifests, packages, calls, routes                | [FastAPI, Django, Flask, Celery](docs/languages/python.md)                         |
| Rust 1.82 or newer, optional on a native install | `syn` parsing; Cargo manifests, cross-file impls, routes; never invokes cargo/rustc                | [Details and limits](docs/languages/rust.md)                                       |

Mixed repositories reconcile into one graph. Other scanners plug in as isolated worker
processes through the [scanner SDK](docs/reference/scanner-sdk.md).

## Safety model

- Scanning never installs dependencies, executes project code or boots a framework. Workers are
  supervised and resource-capped, and their output is untrusted until it passes validation.
- The allow-list is a security boundary. `serve` refuses to start without a root, and Knossos
  never writes the roots file during normal operation: only `tools/install` and
  `knossos allow-root --execute` do, so widening it stays a deliberate act on disk.
- The database is derived and rebuildable, and source mounts stay read-only.
- The Git-backed tools run `git` with repository-controlled hooks, pagers and drivers forced off.
- MCP stdio is the recommended transport. The loopback-only HTTP profile has its own
  [threat model](docs/operate/http-threat-model.md).
- A failed scan is never activated: the last complete scan stays the graph you query. See the
  [fault recovery matrix](docs/operate/recovery-matrix.md).

## Development

One versioned quality profile runs locally, in Git hooks and in CI:

```sh
tools/quality-container fast
tools/quality-container full
```

`fast` covers linting, static analysis, formatting and the whole test suite. `full` adds
security audits, coverage floors, performance budgets, mutation score and supply-chain checks.
See [quality gates](docs/contribute/quality.md), [how the mod is built](docs/contribute/mod-internals.md)
and [CONTRIBUTING.md](CONTRIBUTING.md) for the workflow, the Conventional Commit prefixes that
drive releases, and how to add a language scanner.

## Further reading

- [The documentation index](docs/README.md): which page answers which question
- [What a dependency graph finds that your coding agent misses](https://tim-schipper.nl/en/blog/dependency-graph-coding-agents)

## License

[MIT](LICENSE).

## Links

- [Tool page](https://aranea-development.nl/en/tools/knossos-mcp)
- [Model Context Protocol](https://modelcontextprotocol.io/)
- [Claude Code plugins](https://code.claude.com/docs/en/plugins)
- [Changelog](CHANGELOG.md)

---

Built by [Tim Schipper](https://tim-schipper.nl/en) and released as open source under
[Aranea Development](https://aranea-development.nl).
