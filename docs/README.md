# Knossos documentation

Every document describes shipped behavior. Anything a scan cannot prove is
labelled as heuristic, bounded, or explicitly unsupported in the page that
covers it.

## Start here

| Document                                                      | Read it for                                                      |
| ------------------------------------------------------------- | ---------------------------------------------------------------- |
| [Installation](get-started/installation.md)                   | Docker and native setup, MCP client registration, allowed roots. |
| [MCP tool reference](reference/mcp-tools.md)                  | All 33 tools: generated schemas, defaults, and annotations.      |
| [CLI reference](reference/cli.md)                             | Generated command and option contract.                           |
| [Project configuration](get-started/project-configuration.md) | `knossos.json` ignores, limits, boundaries, policies, budgets.   |
| [Running in Docker](operate/container.md)                     | Image guarantees, mounts, compose profiles.                      |

## Capabilities

Each page covers one group of tools, in both its CLI and MCP form, with its
limits stated. The groups match the tool tables in the
[project README](../README.md#tools).

- [Finding and reading components](concepts/finding-components.md):
  the project catalogue, component dossiers, usage sites, summaries,
  boundaries, and diagram export.
- [Structure analysis](concepts/structure-analysis.md): impact analysis,
  flow tracing, dependency cycles, architecture health, and where new code
  belongs.
- [Reviewing a change](concepts/change-review.md): one-call review, plus
  changed-file impact, test impact, and Git-weighted risk on their own.
- [Declared rules and budgets](concepts/architecture-rules.md): boundary
  policies and reviewable regression gates.
- [Scan history](concepts/history.md): retained snapshots, architectural
  changelogs, and trend metrics with release notes.
- [Dead-code candidates](concepts/dead-code-candidates.md): what
  `architecture_health` reports as unreferenced, what it reports as reachable
  only from tests, and everything it excludes first.
- [The graph and its evidence](concepts/graph-and-evidence.md)
- [Agent integration](agents/agent-integration.md): the paste-ready
  orientation brief, task-shaped evidence bundles, and durable annotations.
- [Session brief](claude-code/session-brief.md): the path-addressed,
  five-state orientation text a Claude Code session sees at start.
- [Notes for the model](claude-code/agent-notes.md): the facts the mod tells the
  model at the moment they help, and the `knossos_context` tool.
- [Claude Code mod](claude-code/pane.md): the blast-radius band,
  edit notes, policy notes and architecture pane inside a Claude Code session.
- [The routing skill](claude-code/skill.md): which questions that
  session brings back to the graph, and which it answers by reading the tree.

## Guides

- [Installation and MCP configuration](get-started/installation.md)
- [First scan](get-started/first-scan.md)
- [Checked-in project configuration](get-started/project-configuration.md)
- [The agent orientation plugin](claude-code/plugin.md)
- [CI and editor integration](operate/ci-editor-integration.md)
- [Opt-in watch mode](operate/watch-mode.md)
- [Portable graph bundles](operate/graph-bundles.md)

## Language and framework support

- [PHP and Laravel](languages/php-laravel.md)
- [PHP and Symfony](languages/php-symfony.md)
- [TypeScript and JavaScript](languages/typescript.md)
- [Python](languages/python.md)
- [Rust](languages/rust.md)

## Reference

- [MCP tool reference](reference/mcp-tools.md): generated from live tool definitions.
- [CLI reference](reference/cli.md): generated from `bin/knossos help`.
- [Response envelopes](reference/response-envelopes.md): the shared result
  shape every tool returns, plus verbosity, size budgets, staleness, and
  next steps.
- [Language API reference](reference/api.md): generated from enforced interface docblocks.
- [Scanner worker protocol v1](reference/scanner-protocol-v1.md)
- [Scanner and enricher SDK](reference/scanner-sdk.md)

Generated pages are rebuilt by `php tools/generate-reference.php`; edit the
source help text and schemas rather than the Markdown.

## Operations

- [Running in Docker](operate/container.md)
- [Project and database maintenance](operate/maintenance.md)
- [Fault recovery matrix](operate/recovery-matrix.md)
- [Troubleshooting and migrations](operate/troubleshooting-and-migrations.md)
- [Streamable HTTP transport and threat model](operate/http-threat-model.md)
- [Supply-chain and release assurance](operate/supply-chain.md)

## Development

- [Quality gates](contribute/quality.md)
- [Coverage policy](contribute/coverage.md)
- [Maintainability ratchets](contribute/maintainability.md)
- [Property, fuzz, differential, and mutation testing](contribute/adversarial-testing.md)
- [Performance budgets](contribute/performance-budgets.md)
- [How the mod is built](contribute/mod-internals.md)

## Decisions and examples

- [Architecture decision records](adr/): the recorded reasons behind the
  runtime, transport, and enrichment boundaries.
- [CI and editor examples](examples/): GitHub Actions, GitLab CI, and VS Code
  task recipes referenced by the integration guide.
