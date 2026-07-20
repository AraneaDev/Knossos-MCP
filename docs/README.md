# Knossos documentation

Every document describes shipped behavior. Anything a scan cannot prove is
labelled as heuristic, bounded, or explicitly unsupported in the page that
covers it.

## Start here

| Document                                                 | Read it for                                                       |
| -------------------------------------------------------- | ----------------------------------------------------------------- |
| [Installation](guides/installation.md)                   | Docker and native setup, MCP client registration, `--allow-root`. |
| [Project configuration](guides/project-configuration.md) | `knossos.json` ignores, limits, boundaries, policies, budgets.    |
| [Running in Docker](operations/container.md)             | Image guarantees, mounts, compose profiles.                       |
| [CLI reference](reference/cli.md)                        | Generated command and option contract.                            |
| [MCP tool reference](reference/mcp-tools.md)             | Generated tool schemas and annotations.                           |

## Guides

- [Installation and MCP configuration](guides/installation.md)
- [Checked-in project configuration](guides/project-configuration.md)
- [CI and editor integration](guides/ci-editor-integration.md)
- [Opt-in watch mode](guides/watch-mode.md)
- [Portable graph bundles](guides/graph-bundles.md)

## Capabilities

Each page documents one query surface, its CLI and MCP forms, and its limits.

- [Project catalogue](features/project-catalog.md) — stable project IDs and freshness states.
- [Component inspection](features/component-inspection.md) — single-call component dossiers.
- [Architecture context](features/architecture-context.md) — bounded task-oriented evidence bundles.
- [Declared architecture policies](features/architecture-policies.md) — boundary dependency rules.
- [Architecture quality budgets](features/quality-budgets.md) — reviewable regression gates.
- [Architecture trends](features/architecture-trends.md) — historical metrics and release notes.
- [Retained snapshots](features/snapshots.md) — immutable history across successful scans.
- [Snapshot diff](features/snapshot-diff.md) — evidence-backed architectural changelogs.
- [Changed-file impact](features/changed-files-impact.md) — explicit and Git-discovered change sets.
- [Git change signals](features/git-change-impact.md) — time-aware, explicitly heuristic risk.
- [Diagram source export](features/diagram-export.md) — deterministic Mermaid and PlantUML.
- [Semantic location ranking](features/semantic-ranking.md) — optional, opt-in, with exact fallback.

## Language and framework support

- [PHP and Laravel](languages/php-laravel.md)
- [PHP and Symfony](languages/php-symfony.md)
- [TypeScript and JavaScript](languages/typescript.md)
- [Python](languages/python.md)

## Reference

- [CLI reference](reference/cli.md) — generated from `bin/knossos help`.
- [MCP tool reference](reference/mcp-tools.md) — generated from live tool definitions.
- [Language API reference](reference/api.md) — generated from enforced interface docblocks.
- [Scanner worker protocol v1](reference/scanner-protocol-v1.md)
- [Scanner and enricher SDK](reference/scanner-sdk.md)

Generated pages are rebuilt by `php tools/generate-reference.php`; edit the
source help text and schemas rather than the Markdown.

## Operations

- [Running in Docker](operations/container.md)
- [Project and database maintenance](operations/maintenance.md)
- [Fault recovery matrix](operations/recovery-matrix.md)
- [Troubleshooting and migrations](operations/troubleshooting-and-migrations.md)
- [Streamable HTTP transport and threat model](operations/http-threat-model.md)
- [Supply-chain and release assurance](operations/supply-chain.md)

## Development

- [Quality gates](development/quality.md)
- [Coverage policy](development/coverage.md)
- [Maintainability ratchets](development/maintainability.md)
- [Property, fuzz, differential, and mutation testing](development/adversarial-testing.md)
- [Performance budgets](development/performance-budgets.md)

## Decisions and audits

- [Architecture decision records](adr/) — the recorded reasons behind the
  runtime, transport, and enrichment boundaries.
- [Audits](audits/) — dated findings from completed review passes; historical
  records, not current contracts.
- [CI and editor examples](examples/) — GitHub Actions, GitLab CI, and VS Code
  task recipes referenced by the integration guide.
