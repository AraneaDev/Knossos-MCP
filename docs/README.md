# Knossos documentation

Each page answers one question. Every page describes behaviour at the code it
ships with, and anything a scan cannot prove is labelled as heuristic, bounded
or unsupported on the page that covers it. The [project README](../README.md)
is the short version.

## Get started

- [Installation](get-started/installation.md): how do I install Knossos and
  register it with Claude Code, and what goes wrong?
- [First scan](get-started/first-scan.md): how do I scan a project, check the
  result, and what does an answer look like?
- [Project configuration](get-started/project-configuration.md): how do I tell
  Knossos what to skip, where the boundaries are, and which rules and budgets
  to check?

## Claude Code

- [The plugin](claude-code/plugin.md): how do I install, point, update and
  remove the Claude Code plugin?
- [The pane](claude-code/pane.md): what do the band, each tab and each overlay
  of the pane show, and which keys drive them?
- [Notes for the model](claude-code/agent-notes.md): what does the mod tell the
  model, when, in which words, and what does `knossos_context` answer?
- [Session brief](claude-code/session-brief.md): what does a session see when
  it starts, and how is each verdict chosen?
- [The routing skill](claude-code/skill.md): which questions does the skill
  send to the graph, and which does it keep away?

## Agents

- [Codex and other MCP clients](agents/clients.md): how do I register Knossos
  with Codex or another MCP client, and add the routing skill to Codex?
- [Agent integration](agents/agent-integration.md): which tools orient an agent
  before it greps, how does it get an evidence bundle for one task, and how does
  it record a judgment for later sessions?

## Concepts

- [The graph and its evidence](concepts/graph-and-evidence.md): what does a
  scan store, and how far can I trust each fact?
- [Finding components](concepts/finding-components.md): how do I find a
  project, a component, its relations and every place a symbol is used?
- [Structure analysis](concepts/structure-analysis.md): what depends on this,
  how does A reach B, where are the cycles and hubs, and where does new code
  belong?
- [Dead-code candidates](concepts/dead-code-candidates.md): what does
  `architecture_health` report as unreferenced or reached only by tests, and
  what does it exclude first?
- [Reviewing a change](concepts/change-review.md): what does this diff put at
  risk, and which tests reach it?
- [Declared rules and budgets](concepts/architecture-rules.md): how do I make
  architectural intent something a build can fail on?
- [Scan history](concepts/history.md): what changed between two scans, and how
  have the metrics moved?

## Languages

- [PHP and Laravel](languages/php-laravel.md): what does the Laravel enricher
  add to a PHP scan?
- [PHP and Symfony](languages/php-symfony.md): what does the Symfony enricher
  add to a PHP scan?
- [TypeScript and JavaScript](languages/typescript.md): what does the
  TypeScript worker extract, from which files and frameworks?
- [Python](languages/python.md): what does the Python worker extract, and which
  frameworks does it recognise?
- [Rust](languages/rust.md): what does the Rust worker extract, and where does
  it stop?

## Reference

- [MCP tool reference](reference/mcp-tools.md): what does each tool take, and
  what are its defaults and annotations? Generated from the tool definitions.
- [CLI reference](reference/cli.md): which commands and options exist?
  Generated from `bin/knossos help`.
- [Response envelopes](reference/response-envelopes.md): what shape does every
  answer share, and what do verbosity, size budgets and staleness do to it?
- [Language API reference](reference/api.md): which PHP interfaces and worker
  surfaces may an extension rely on? Generated from their docblocks.
- [Scanner worker protocol v1](reference/scanner-protocol-v1.md): what does a
  scanner worker read and write on the wire?
- [Scanner and enricher SDK](reference/scanner-sdk.md): how do I add a scanner
  for another language?

Generated pages are rebuilt by `php tools/generate-reference.php`. Edit the
help text and schemas they come from, never the Markdown.

## Operate

- [Watch mode](operate/watch-mode.md): how do I keep a graph current as files
  change?
- [Running in Docker](operate/container.md): what does the image guarantee, and
  how do I mount, compose and install the plugin for it?
- [Project and database maintenance](operate/maintenance.md): how do I remove a
  project, clean up failed scans, and check, compact or back up the database?
- [Fault recovery matrix](operate/recovery-matrix.md): what happens when a scan
  or a worker fails?
- [Troubleshooting and migrations](operate/troubleshooting-and-migrations.md):
  what do I check first when something breaks, and how do upgrades migrate?
- [Streamable HTTP and its threat model](operate/http-threat-model.md): when
  may I serve over HTTP, and what does it defend against?
- [Supply chain and release assurance](operate/supply-chain.md): how is the
  release image built and verified?
- [CI and editor integration](operate/ci-editor-integration.md): how do I fail
  a build on a rule or a budget, and which exit codes and reports do I get?
- [Graph bundles](operate/graph-bundles.md): how do I move a graph between
  databases without the source?

## Contribute

- [Quality gates](contribute/quality.md): which checks run, in which lane, and
  how do I run them locally?
- [Coverage policy](contribute/coverage.md): which coverage floors apply, and
  where are they measured?
- [Maintainability ratchets](contribute/maintainability.md): which budgets keep
  the code from growing worse?
- [Adversarial testing](contribute/adversarial-testing.md): which property,
  fuzz, differential and mutation tests guard the trust boundaries?
- [Performance budgets](contribute/performance-budgets.md): how fast and how
  large may a scan and a query be?
- [How the mod is built](contribute/mod-internals.md): how is the Claude Code
  mod put together, and how is it tested?

## Decisions and examples

- [ADR 0001: PHP core with isolated language scanner workers](adr/0001-core-runtime-and-scanner-isolation.md):
  why the core is PHP and every language scanner runs as its own process.
- [ADR 0002: Isolate a current MCP stdio adapter](adr/0002-current-mcp-stdio-adapter.md):
  why the stdio server is a small adapter of its own, kept apart from the tool
  logic.
- [ADR 0003: Prioritize static NestJS enrichment](adr/0003-typescript-framework-enrichment.md):
  why NestJS got static enrichment before Next.js and Express.
- [ADR 0004: Serve MCP 2026-07-28 alongside 2025-11-25](adr/0004-protocol-2026-07-28.md):
  how one dispatcher serves two protocol revisions.
- [CI and editor examples](examples/): GitHub Actions, GitLab CI and VS Code
  task recipes, used by the integration guide.
