# Knossos executable implementation plan

Status: Active  
Spec: `docs/PROJECT-SPEC.md`  
Task states: `[ ]` not started, `[~]` in progress, `[x]` complete

## Working rules

- A task starts only when its listed dependencies are complete.
- A task is complete only when its acceptance criteria and verification pass.
- Each scanner fact must be deterministic, source-linked, and owned by a
  replaceable contribution key.
- Protocol, database, and public tool changes require versioned contracts.
- Fixture expectations are reviewed as product behavior, not regenerated
  blindly from implementation output.

## Phase 1: language-neutral graph and two scanners

Goal: scan PHP and TypeScript projects into the same evidence-backed graph and
serve the first useful architecture queries.

### [x] P1-T01 — Bootstrap core and scanner contracts

Completed: 2026-07-17

Dependencies: none

Deliverables:

- PHP core package skeleton, CLI entry point, autoloading, and quality scripts.
- Reproducible Docker image containing the supported PHP, Node, Composer, and
  SQLite runtime, with documented read-only source mounts.
- Recorded core-runtime decision and runtime support policy.
- Versioned, language-neutral scanner worker protocol.
- Typed manifest, contribution, node, edge, evidence, and diagnostic value
  objects; request framing is specified for the worker-supervisor task.
- Contract tests that run without network services.

Acceptance criteria:

- `composer validate --strict` passes.
- Every PHP source file passes syntax validation.
- `bin/knossos version` returns a machine-readable version on request.
- The Docker image builds and runs the same version command without using host
  PHP or Node installations.
- Protocol DTO tests reject malformed data and round-trip valid data.
- No scanner contract imports SQLite or MCP implementation types.

Verification: `composer check` and `docker build -t knossos-mcp:dev .`

Completion evidence: native checks pass with 6 contract tests; the pinned image
builds and returns `{"name":"knossos","version":"0.1.0-dev"}`.

### [x] P1-T02 — SQLite schema and graph repository

Completed: 2026-07-17

Dependencies: P1-T01

Deliverables:

- Versioned migrations for projects, scans, files, nodes, edges, diagnostics,
  and boundaries.
- SQLite connection configuration with foreign keys and WAL mode.
- Transactional repository interfaces and implementation.
- Stable ID service for projects, symbols, routes, and edges.

Acceptance criteria:

- Migrations apply to an empty database and are idempotently detected.
- CRUD and indexed adjacency queries pass integration tests.
- A failed write transaction leaves the prior snapshot unchanged.
- Query plans use indexes for name lookup and edge traversal fixtures.

Verification: `composer test -- --group=store`

Completion evidence: 6 store tests cover idempotent migrations, WAL and foreign
keys, graph CRUD/traversal, rollback, stable IDs, and indexed query plans; the
full 12-test suite passes.

### [x] P1-T03 — Project discovery and input fingerprinting

Completed: 2026-07-17

Dependencies: P1-T01

Deliverables:

- Allowed-root validation, symlink containment, ignore rules, and file limits.
- Composer/PSR-4, package workspace, and TypeScript configuration discovery.
- Content fingerprinting and scanner/config input hashes.
- Discovery diagnostics and fixture projects.

Acceptance criteria:

- Traversal and escaping symlinks are rejected.
- Default exclusions omit dependencies, VCS data, output, and Knossos state.
- Mixed monorepos produce the expected language project/input sets.
- No project code, package manager, or lifecycle script is executed.

Verification: `composer test -- --group=discovery`

Completion evidence: 4 discovery tests cover mixed-language/package inputs,
JSONC, stable hashes, default/custom exclusions, file limits, allowed roots,
and escaping symlinks; the full 16-test suite passes.

### [x] P1-T04 — Scanner worker supervisor

Completed: 2026-07-17

Dependencies: P1-T01, P1-T03

Deliverables:

- NDJSON JSON-RPC process transport implementing initialize, discover, scan,
  cancel, and shutdown.
- Schema validation, streaming contributions, time/output limits, stderr logs,
  and process cleanup.
- Fake compliant, malformed, slow, and crashing workers for tests.

Acceptance criteria:

- Capability and protocol-version mismatches fail before scanning.
- Cancellation, timeout, malformed output, and crashes return typed diagnostics.
- Worker failure cannot mutate the active graph.
- Concurrent stdout/stderr cannot corrupt protocol framing.

Verification: `composer test -- --group=worker`

Completion evidence: 5 worker tests cover handshake/discovery/scan/cancel/
shutdown, version mismatch, malformed JSON, crashes, unexpected responses,
timeouts, stdout/stderr limits, and contribution validation; all 21 tests pass.

### [x] P1-T05 — PHP scanner worker

Completed: 2026-07-17

Dependencies: P1-T03, P1-T04

Deliverables:

- Worker package using `nikic/php-parser` with pinned dependencies.
- Composer/PSR-4-aware declaration, inheritance, trait, type reference,
  construction, and resolvable call extraction.
- Owned contributions with evidence, confidence, and diagnostics.
- Labelled PHP fixture and golden expectations.

Acceptance criteria:

- Supported PHP declarations meet the accuracy target in the product spec.
- Invalid files produce diagnostics without aborting other inputs.
- The worker never includes, autoloads, or evaluates scanned PHP files.
- Output is stable across repeated scans.

Verification: PHP scanner test suite plus core protocol conformance suite.

Completion evidence: 3 PHP scanner tests cover the labelled declaration/edge
set, Composer discovery, parse recovery, no-execution behavior, deterministic
output, traversal rejection, and file limits. The full 24-test suite and a
read-only, network-disabled Docker worker smoke test pass.

### [x] P1-T06 — TypeScript scanner worker

Completed: 2026-07-17

Dependencies: P1-T03, P1-T04

Deliverables:

- Node worker with a pinned TypeScript compiler dependency.
- `tsconfig` extends/references/paths support and workspace awareness.
- Declaration, module, import/export/re-export, inheritance, type reference,
  construction, and compiler-resolved call extraction.
- Labelled ESM, CommonJS, TSX, alias, and project-reference fixtures.

Acceptance criteria:

- Supported TypeScript declarations meet the accuracy target in the spec.
- Type-only and runtime dependencies remain distinguishable.
- External dependencies are shallow nodes; `node_modules` is not scanned.
- Compiler errors become diagnostics while recoverable facts remain available.

Verification: Node worker suite plus core protocol conformance suite.

Completion evidence: 3 TypeScript scanner tests cover configs/workspaces,
project references without prebuilding, aliases, ESM/CommonJS, TSX, type-only
imports, re-exports, overload/declaration merging, external packages, compiler
diagnostics, deterministic output, path/size limits, and no execution. The full
27-test suite and a read-only, network-disabled Docker smoke test pass.

### [x] P1-T07 — Graph reconciliation and symbol resolution

Completed: 2026-07-17

Dependencies: P1-T02, P1-T04, P1-T05, P1-T06

Deliverables:

- Contribution validation, stable identity assignment, deduplication, and
  unresolved-reference handling.
- Full-scan reconciliation with atomic active snapshots.
- Cross-scanner graph assembly for mixed repositories.

Acceptance criteria:

- Repeated full scans produce equivalent graphs and stable IDs.
- A worker or reconciliation failure preserves the last active snapshot.
- Ambiguous and external targets are retained without false resolution.
- Every stored non-derived fact has source evidence and scanner provenance.

Verification: `composer test -- --group=reconciliation`

Completion evidence: 3 reconciliation tests cover cross-scanner resolution,
explicit unresolved nodes, provenance/evidence, diagnostics, stable repeated
full scans, atomic activation, and failure preservation. All 30 tests pass.

### [x] P1-T08 — First MCP/CLI query surface

Completed: 2026-07-17

Dependencies: P1-T02, P1-T07

Deliverables:

- MCP SDK compatibility spike and ADR; pinned selected SDK.
- `stdio` server adapter plus CLI equivalents.
- `scan_project`, `find_component`, and `architecture_summary` tools.
- Common result envelope, bounds, ambiguity candidates, and evidence output.

Acceptance criteria:

- MCP Inspector smoke test passes without stdout log contamination.
- Tool input schemas reject unsafe paths and invalid limits.
- Queries return snapshot IDs and bounded, project-relative evidence.
- PHP, TypeScript, and mixed fixtures answer the Phase 1 example questions.

Verification: protocol integration suite and recorded Inspector smoke command.

Completion evidence: the native 32-test suite covers real mixed-language scan,
snapshot queries, bounded project-relative evidence, lifecycle framing, strict
arguments, allowed-root rejection, and stdout isolation. Official Inspector
0.21.2 `tools/list` passed inside the bundled Node 24 image. The image also
completed a network-disabled scan from a read-only source mount. Smoke command:

```sh
docker run --rm -e npm_config_cache=/tmp/npm-cache --entrypoint npx \
  knossos-mcp:dev -y @modelcontextprotocol/inspector@0.21.2 --cli \
  php /opt/knossos/bin/knossos serve --allow-root=/opt/knossos \
  --db=/data/inspector.sqlite --method tools/list
```

Phase 1 exit gate: both scanners meet fixture accuracy targets, all query claims
are evidence-linked, mixed projects share one graph, and failures cannot corrupt
the active snapshot.

## Phase 2: framework intelligence and core queries

### [x] P2-T01 — Classification and role system

Completed: 2026-07-17

Dependencies: Phase 1

- Separate language kinds from framework roles and support multiple roles.
- Add rule provenance and explicit/probable/possible confidence policies.
- Verify deterministic classification and negative fixtures.

Completion evidence: migration 002 adds independently indexed, multi-role
classification facts without changing language node kinds. Rules carry stable
IDs, origin, confidence, attributes, and evidence; reconciliation validates
targets and activates roles atomically with the graph. Classification and
mixed-query suites cover deterministic ordering, explicit and convention
roles, negative kinds, multiple roles, provenance, evidence, and rollback.

### [x] P2-T02 — Laravel enricher

Completed: 2026-07-18

Dependencies: P2-T01

- Extract routes/groups/middleware, controllers, jobs, events/listeners,
  providers, bindings, policies, models, commands, and supported conventions.
- Publish a Laravel-version and idiom support matrix.
- Verify every inferred relation has evidence and confidence.

Completion evidence: Composer-gated static enrichment supports literal route
methods/actions, fluent groups/names/middleware, dispatch, listener maps,
container bindings, policy maps, observers, explicit framework types, app-path
roles, and conservative naming roles. A support matrix documents Laravel
10–12 idioms and exclusions. Positive and negative fixtures verify framework
gating, resolution, evidence, confidence tiers, and dynamic-expression
diagnostics without booting Laravel. All 36 tests pass.

### [x] P2-T03 — Flow query engine

Completed: 2026-07-18

Dependencies: P2-T01, P2-T02

- Implement bounded path search, edge allowlists, confidence-aware ranking, and
  explanations for every hop.
- Add `explain_flow` to MCP and CLI.
- Verify cycles, path limits, time limits, and truncation behavior.

Completion evidence: `explain_flow` is available through the transport-neutral
query service, CLI, and MCP schema. It performs ambiguity-safe resolution,
simple directed path enumeration over an allowlist, confidence thresholds,
confidence-first deterministic ranking, per-hop explanations/evidence, and
depth/path/time/visit caps. Synthetic cycle/ranking tests and a real Laravel
route-to-event flow pass; deterministic clock injection verifies deadline
truncation. All 37 tests pass.

### [x] P2-T04 — Impact analysis

Completed: 2026-07-18

Dependencies: P2-T01

- Implement bounded reverse traversal grouped by distance, entry point,
  boundary, and confidence.
- Add `impact_analysis` to MCP and CLI.
- Verify direct/transitive results and conservative blast-radius wording.

Completion evidence: `impact_analysis` is exposed through query, CLI, and MCP
layers with reverse edge allowlists, confidence filters, ambiguity candidates,
shortest-distance direct/transitive groups, entry-point roles, evidence, and
depth/result/time/visit caps. Tests cover reverse cycles, confidence groups,
entry points, filtering, truncation, deadlines, and conservative wording. All
38 tests pass.

### [x] P2-T05 — Boundaries and architecture search

Completed: 2026-07-18

Dependencies: P2-T01

- Add configured and inferred boundaries for Composer packages, workspaces,
  project references, paths, namespaces, and modules.
- Add `list_boundaries` and structured `search_architecture`.
- Verify explicit/inferred provenance, filters, pagination, and response caps.

Completion evidence: migration 003 adds snapshot-owned boundary membership.
Composer packages, Node workspaces/packages, TypeScript projects, top-level PHP
namespaces, and TypeScript modules are inferred; MCP/CLI scans accept safe
explicit path or namespace matchers. `list_boundaries` and
`search_architecture` provide provenance filters, structured kind/role/
boundary/confidence filters, bounded samples, evidence, and offset pagination.
Impact results group reached nodes by boundary. All 39 tests, Docker Laravel
scan, and the seven-tool official Inspector smoke pass.

Phase 2 exit gate: the spec's example questions receive useful, auditable,
bounded answers on PHP/Laravel, TypeScript, and mixed fixtures.

## Phase 3: incremental operation and hardening

### [x] P3-T01 — Change detection and contribution invalidation

Completed: 2026-07-18

Dependencies: Phase 2

- Detect added, changed, deleted, and renamed inputs using hashes.
- Replace facts by owner and re-resolve affected references.
- Prove incremental/full equivalence over generated edit sequences.

Completion evidence: migration 004 persists validated, versioned scanner
contributions by owner/file/content/config hash. Auto scans replay unchanged
facts, scan only invalidated owners, discard deleted owners, and atomically
replace graph plus cache. A generated edit sequence verifies zero-parse
no-change scans, one-file changes, re-resolution, rename as delete/add, stable
cache size, and exact incremental/full graph equivalence. All 40 tests pass.

### [x] P3-T02 — Incremental language and framework analysis

Completed: 2026-07-18

Dependencies: P3-T01

- Reuse TypeScript incremental programs and rerun only impacted enrichers.
- Version scanner/config inputs and invalidate only affected contributions.
- Meet the no-change and one-file-change acceptance targets.

Completion evidence: long-lived MCP scan services retain supervised workers;
the TypeScript worker reuses prior compiler programs through `oldProgram`, and
validated contribution replay bypasses both workers on no-change scans.
Language-specific config hashes prevent Composer changes from invalidating
TypeScript and Node/tsconfig changes from invalidating PHP/Laravel. Tests cover
one-file reuse, isolated config invalidation, framework fact regeneration, and
scanner-version invalidation. All 41 tests pass.

### [x] P3-T03 — Concurrency, cancellation, and recovery

Completed: 2026-07-18

Dependencies: P3-T01

- Enforce one writer per project while queries use the active snapshot.
- Implement end-to-end cancellation and orphan-worker cleanup.
- Verify crash recovery and database consistency under fault injection.

Completion evidence: migration 005 and leased writer guards enforce one scan
per project, reclaim stale locks, and leave WAL readers on the active snapshot.
Cancellation tokens are checked across discovery, workers, and reconciliation;
the supervisor polls and terminates cancelled children, CLI SIGINT is wired,
and stdio cancellation notifications are contained as tool errors. Tests cover
busy locks, stale recovery, reader availability, worker cleanup, active
snapshot preservation, and lock cleanup. All 42 tests pass.

### [x] P3-T04 — Limits, diagnostics, and observability

Completed: 2026-07-18

Dependencies: P3-T02, P3-T03

- Enforce scan/query time, memory, file, depth, row, and response limits.
- Add stable diagnostic codes and structured scan/query metrics.
- Verify malicious paths, oversized files, protocol floods, and graph bombs.

Completion evidence: scan metrics expose elapsed time, peak memory, discovered,
parsed, and replayed files; bounded traversals expose visited states and stable
truncation reasons. Worker runtime memory, time, stdout/stderr, frame, file,
depth, row, and response caps are enforced. MCP errors carry stable structured
codes and oversized input/output tests prove flood containment. Existing safety
suites cover traversal, symlinks, oversized files, cycles, and result bombs.
All 43 tests pass.

### [x] P3-T05 — Packaging, doctor, and user documentation

Completed: 2026-07-18

Dependencies: P3-T04

- Package the core and version-matched PHP/TypeScript workers; harden and
  publish the Docker distribution introduced in P1-T01.
- Implement `doctor` for runtimes, extensions, workers, SQLite, and config.
- Document installation and MCP client configuration on supported platforms.

Completion evidence: the hardened, unprivileged image bundles PHP 8.4, Node
24, Composer, SQLite, and explicitly versioned workers with memory caps,
health checks, and OCI metadata. `doctor` validates runtimes, extensions,
worker protocol compatibility, SQLite integrity/migrations, and writable data.
Installation, container, Laravel, and MCP client configuration guides are
published. All 44 tests pass; the final image passes `doctor`, scans a
read-only project with networking disabled, and interoperates with the pinned
official MCP Inspector 0.21.2.

Phase 3 exit gate: passed. Incremental/full equivalence and analyzer reuse are
covered by tests, bounded metrics are recorded in scan responses, and the
versioned image is reproducibly built and verified on the bundled runtimes.

## Phase 4: advanced analysis

Each task is independently releasable after Phase 3:

### [x] P4-T01 — Dependency cycles and strongly connected components

Completed: 2026-07-18

Dependencies: Phase 3 exit gate

- Compute deterministic strongly connected components over configurable,
  confidence-filtered dependency relationships without loading an unbounded
  graph.
- Report multi-node cycles and self-loops with member, relationship, boundary,
  confidence, and source evidence.
- Expose bounded `dependency_cycles` MCP and CLI surfaces with explicit time,
  graph-size, and result truncation reasons.
- Test SCC correctness, filters, deterministic ordering, validation, and caps.

Completion evidence: iterative Kosaraju traversal computes deterministic SCCs
without recursive stack risk, covers self-loops, filters by supported dependency
kind and confidence, and bounds graph loading, time, members, internal edges,
evidence, and result counts with explicit truncation reasons. Query, CLI, and
the eighth MCP tool return boundary-aware evidence envelopes. Correctness,
filter, ordering, validation, timeout, and graph/result-cap tests pass as part
of the 45-test suite; the updated image builds successfully.

### [x] P4-T02 — Hubs, hotspots, and dead-code candidates with uncertainty

Completed: 2026-07-18

Dependencies: P4-T01

- Compute bounded in/out dependency degree and rank highly connected hubs.
- Rank static complexity hotspots from connectivity, cross-boundary reach, and
  cycle participation without presenting them as change-frequency metrics.
- Identify conservative unreferenced-code candidates while excluding known
  entry points and labelling framework/dynamic-resolution uncertainty.
- Expose evidence, scoring factors, filters, limits, and deterministic tests
  through CLI and MCP.

Completion evidence: `architecture_health` is available through query, CLI,
and the ninth MCP tool. It ranks deterministic in/out degree hubs, scores
explicitly static hotspots with cross-boundary and SCC factors, excludes known
entry points from unreferenced-code candidates, and labels dynamic/framework
uncertainty. Graph, time, evidence, and result caps are explicit; batched role
and boundary lookups avoid SQLite variable limits. Filter, score, ordering,
uncertainty, validation, timeout, and truncation tests pass in the 46-test suite.

### [x] P4-T03 — Declared architecture policies and violation detection

Completed: 2026-07-18

Dependencies: P4-T02

- Define strict boundary policy declarations with stable IDs, source boundary,
  allowed and forbidden targets, relationship filters, and confidence floor.
- Resolve boundary IDs/names deterministically and reject ambiguous or unknown
  declarations before evaluation.
- Report bounded, evidence-backed violating relationships without claiming
  runtime enforcement; expose MCP and CLI inputs.
- Test allowlists, denylists, unassigned targets, confidence/kind filters,
  ambiguity, deterministic ordering, and caps.

Completion evidence: strict request-scoped policy declarations support stable
IDs, unambiguous boundary IDs/names, allowlists, denylists, `@unassigned`, and
per-policy dependency kinds. The tenth MCP tool and bounded JSON-file CLI
report deterministic relationship, component, boundary, reason, confidence,
and source evidence. Tests cover allow/deny semantics, unassigned nodes,
confidence/kind filters, unknown/ambiguous declarations, validation, time,
edge, and result limits in the 47-test suite; usage is documented.

### [x] P4-T04 — Deterministic `suggest_location` ranking and evaluation set

Completed: 2026-07-18

Dependencies: P4-T03

- Tokenize a feature description deterministically and rank existing
  boundaries using name/member/role relevance and internal dependency cohesion.
- Return multiple candidates with factor-level rationale, representative source
  evidence, confidence, and explicit weak-signal warnings.
- Provide equivalent CLI/MCP surfaces with bounded candidate/member/edge work.
- Check in a small language-neutral evaluation set and assert stable expected
  top-boundary outcomes before adding optional semantic ranking.

Completion evidence: the eleventh MCP tool and equivalent CLI tokenize bounded
feature descriptions and deterministically rank up to 1,000 existing
boundaries using inspectable name, member, role, and cohesion factors. Results
carry related members, source evidence, confidence, weak-signal warnings, and
member/edge/time/result truncation reasons. A checked-in language-neutral
evaluation set asserts stable Backend and Billing outcomes; validation and cap
tests pass in the 48-test suite.

### [x] P4-T05 — Optional semantic ranking with deterministic fallback

Completed: 2026-07-18

Dependencies: P4-T04

- Define a provider-neutral semantic ranker interface that receives only the
  feature description and bounded candidate text, never project execution.
- Keep deterministic ranking as the default; opt in explicitly and blend
  bounded semantic scores with the existing inspectable factors.
- Fall back to the exact deterministic ordering on unavailable, invalid,
  timing-out, or failing providers and report why in warnings/metadata.
- Test successful reranking, score validation, provider failure, unavailable
  configuration, and deterministic equivalence.

Completion evidence: a provider-neutral `SemanticRanker` receives bounded
candidate text and deadlines only when explicitly requested. Complete finite
scores in `[0,1]` add a visible bounded factor; unavailable, incomplete,
invalid, late, and failing providers retain byte-for-byte equivalent candidate
ordering/scores and expose bounded fallback metadata. The packaged runtime has
no implicit external provider. Success, unavailable, malformed, exception, and
mode validation tests pass in the 49-test suite; integration is documented.

### [x] P4-T06 — Git change signals and time-aware impact analysis

Completed: 2026-07-18

Dependencies: P4-T05

- Add a bounded, read-only Git history provider using argument-array process
  execution, disabled optional locks, output caps, and timeouts.
- Aggregate recent commit count, distinct authors, and last-change timestamp by
  indexed relative path without persisting repository history.
- Combine those signals with reverse static impact distance/confidence into an
  explicitly heuristic risk ranking with source evidence and caveats.
- Bundle/check Git in Docker and test parsing, unavailable/non-repository
  behavior, time/output limits, deterministic ranking, and MCP/CLI contracts.

Completion evidence: the twelfth MCP tool and CLI run read-only `git log` via
argument arrays with optional locks disabled, time/output caps, normalized path
validation, and bounded commit windows. Recent file commit/author/last-change
signals blend with reverse impact through visible weights and degrade to static
results for unavailable/non-repository roots. Fake and real temporary-repository
tests cover aggregation, ranking, fallback, validation, and output caps in the
50-test suite. The rebuilt image bundles Git, passes doctor, and its 12-tool
surface passes official Inspector 0.21.2 `tools/list`.

### [x] P4-T07 — Mermaid/PlantUML export

Completed: 2026-07-18

Dependencies: P4-T06

- Deterministically render bounded active-graph nodes and relationships as
  Mermaid flowchart or PlantUML component source without invoking renderers.
- Support optional unambiguous boundary scope, confidence/relationship filters,
  stable aliases, escaped labels, direction, and explicit truncation metadata.
- Expose diagram source and representative evidence through CLI/MCP; test both
  syntaxes, escaping, scope, filters, determinism, validation, and caps.

Completion evidence: the thirteenth MCP tool and raw-output CLI emit stable
Mermaid flowchart or PlantUML component source using safe local aliases and
format-specific escaped labels. Unambiguous boundary scoping, direction,
relationship/confidence filters, evidence, and node/edge truncation are
supported without invoking a renderer. Both syntaxes, scope, escaping,
determinism, validation, filters, and caps pass in the 51-test suite.

### [x] P4-T08 — Evaluate TypeScript framework enrichers

Completed: 2026-07-18

Dependencies: P4-T07

- Evaluate NestJS, Next.js, and Express against current primary documentation,
  static recognizability, architectural value, false-positive risk, version
  churn, and the no-project-execution constraint.
- Record an ADR with supported facts, confidence/evidence rules, fixture plan,
  and a ranked implement/defer decision.
- Implement the highest-value bounded static enricher when its conventions are
  sufficiently explicit; otherwise produce an executable follow-on task rather
  than heuristic framework claims.

Completion evidence: ADR 0003 evaluates NestJS, Next.js, and Express against
current official conventions and records ranked decisions and executable defer
criteria. TypeScript worker 0.3.0 now recognizes aliased `@nestjs/common`
decorators, certain module/controller/provider roles, literal HTTP routes and
`routes_to`, plus static `@Module()` arrays using existing evidence-backed edge
kinds. It never imports/boots the app and skips dynamic metadata. Worker and
end-to-end persistence fixtures pass in the 52-test suite.

### [x] P4-T09 — Streamable HTTP threat model and implementation

Completed: 2026-07-18

Dependencies: P4-T08

- Pin the current Streamable HTTP protocol contract and document DNS rebinding,
  origin validation, authentication, session fixation, request/response flood,
  cancellation, idle timeout, CSRF, proxy, and bind-address risks.
- Implement a local-first HTTP transport over the same `ToolService`, with
  loopback default, strict Origin/Host policy, bearer authentication for
  non-loopback use, bounded bodies/responses/sessions, and graceful shutdown.
- Verify initialize/tool calls, JSON responses, unsupported SSE/session flows,
  auth/origin rejection, caps, concurrency, cancellation behavior, and stdio
  parity against the pinned Inspector/client contract.

Completion evidence: migration 006 and a hashed, expiring, capacity-bounded
session store support the pinned 2025-11-25 lifecycle. The local-first endpoint
reuses `ToolService`, requires exact Host/Origin policy, optionally authenticates
every request with constant-time Bearer comparison, rejects fixation, bounds
bodies/responses, and supports authenticated session deletion. SSE/GET and
in-flight HTTP cancellation are explicitly unsupported and threat-modelled.
Security/lifecycle/cap tests pass in the 53-test suite, and a real loopback PHP
server returned a valid initialize response and server-minted session.

### [x] P4-T10 — Python scanner using the public worker protocol: packages

modules, imports, classes/functions, inheritance, calls, decorators, and
optional framework enrichment without executing project code.

Dependencies: P4-T09

- Bundle a pinned supported Python runtime and a zero-target-dependency stdlib
  AST worker with the same initialize/discover/scan/cancel/shutdown contract.
- Discover `pyproject.toml`, Python packages, source roots, and `.py`/`.pyi`
  files while honoring global containment, file, process, and output limits.
- Emit evidence-backed modules, packages, classes, functions/methods, imports,
  inheritance, calls, decorators, and statically resolvable cross-file targets;
  keep unresolved/external facts explicit and confidence-labelled.
- Add fixtures for packages, relative imports, aliases, async/decorated code,
  syntax errors, determinism, malicious paths, mixed-language reconciliation,
  incremental caching, doctor, and Docker operation.

Completion evidence: the dependency-free `knossos.python@0.1.0` worker uses
isolated stdlib AST parsing for `.py`/`.pyi` inputs and never imports target
code. Discovery, package/module/declaration/import/inheritance/call/decorator
facts, syntax diagnostics, containment limits, persistence, classification,
boundaries, doctor checks, and incremental cache reuse pass in the 56-test
suite. Python 3.11–3.13 is part of the native and Docker runtime contract.

## Phase 5: code, documentation, and release quality gates

Run this phase after the Python scanner so every shipped language is covered.
Tools are pinned in lockfiles/container layers and invoked through the bundled
development image; host installations are optional.

### [x] P5-T01 — Quality-tool applicability inventory and configuration

Dependencies: Phase 4

- Inventory shipped PHP, TypeScript/JavaScript, Python, Markdown, JSON/YAML,
  shell, Dockerfile, Composer/npm/Python dependency, and GitHub workflow files.
- Configure applicable formatters, linters, static analyzers, schema/lockfile
  validators, secret/large-file checks, and dependency audits. The expected
  baseline includes PHP-CS-Fixer and PHPStan; ESLint and Prettier; Ruff and
  mypy; markdownlint; ShellCheck; Hadolint; YAML/JSON/workflow validation; and
  native Composer/npm/Python integrity and audit commands.
- Record every omitted category with a concrete not-applicable rationale; do
  not add blanket exclusions or suppressions merely to make the baseline pass.

### [x] P5-T02 — Remediate and enforce a clean repository baseline

Dependencies: P5-T01

- Run all formatters, linters, analyzers, documentation/link checks, audits,
  unit/integration tests, image build, doctor, and MCP Inspector contract smoke.
- Fix findings or document narrowly scoped suppressions with ownership and
  rationale; produce a single `quality` command with machine-readable failure.
- Keep fast and full profiles deterministic and verify them in a clean image.

### [x] P5-T03 — Enforced pre-commit and pre-push workflows

Dependencies: P5-T02

- Add pinned pre-commit hooks for staged-file formatting, syntax, secrets,
  large files, line endings, and language/doc linters.
- Add a pre-push/full hook for static analysis, tests, dependency integrity,
  container build/doctor, and MCP schema/Inspector checks.
- Provide one-command hook installation and a container-backed fallback;
  verify hooks reject intentionally invalid code/docs and accept the clean tree.

### [x] P5-T04 — CI/release parity and maintenance documentation

Dependencies: P5-T03

- Make CI and release gates call the same versioned quality entrypoints as local
  hooks, with dependency/tool caches that do not weaken validation.
- Document tool versions, upgrade cadence, suppressions, local troubleshooting,
  generated-file policy, and the procedure for updating lockfiles/baselines.
- Exit gate: all applicable checks pass locally, in hooks, and in a clean CI
  container; no release artifact can bypass the full quality profile.

Completion evidence: pinned Composer, npm, Python, Hadolint, ShellCheck, and
pre-commit tooling is bundled in the `quality` image. The baseline required
formatting 58 PHP files and exposed nine PHP analyzer findings, all repaired
without a PHPStan baseline. `tools/quality` powers local hooks and CI; the
container-backed fast profile passes syntax, formatting, static analysis,
repository hygiene, dependency integrity, and the 56-test suite. The full
profile adds audits, Inspector, clean image build, and doctor verification.

## Phase 6: coverage expansion and test hardening

Run after Phase 5 so coverage collection uses the same pinned quality image and
cannot diverge between local hooks and CI.

### [x] P6-T01 — Coverage instrumentation and honest baseline

Dependencies: Phase 5 exit gate

- Add pinned PHP and JavaScript/TypeScript coverage collection plus Python
  coverage for the scanner, with source maps and merged machine-readable
  Cobertura/LCOV artifacts where supported.
- Measure line and branch coverage separately for core, transports, scanners,
  enrichers, storage/migrations, CLI, and safety/error paths; publish a baseline
  report identifying untested risk rather than immediately excluding files.
- Exclude only vendor, generated, fixture, and intentionally unreachable entry
  wrappers with narrow documented rationale.

### [x] P6-T02 — High-risk gap closure

Dependencies: P6-T01

- Prioritize path containment, parsers, protocol/schema rejection, worker crash
  and cancellation, atomic reconciliation, incremental invalidation, locks,
  HTTP auth/session/origin limits, Git process limits, and framework edge cases.
- Add unit, integration, mutation-oriented boundary cases, and deterministic
  fixtures; avoid tests that execute target-project code or merely touch lines
  without asserting behavior.
- Cover every stable error/diagnostic code and every documented fallback or
  truncation reason at least once.

### [x] P6-T03 — Reach and enforce the coverage target

Dependencies: P6-T02

- Reach at least 90% aggregate line coverage across first-party executable code,
  with per-runtime/per-component floors preventing a well-tested area from
  hiding an untested scanner or transport. Track branch coverage and set a
  realistic ratcheted branch floor from the measured baseline.
- Fail the full quality/CI profile on coverage regression, missing coverage
  artifacts, or a floor violation; permit threshold changes only through a
  reviewed configuration change with report evidence.
- Verify the gate by introducing controlled uncovered code and by lowering a
  component below its floor, then restore and record the clean result.

### [x] P6-T04 — Test-suite maintainability and operating guide

Dependencies: P6-T03

- Split fast/unit, integration, container, Inspector, and coverage profiles;
  remove flakes, bound time/resources, and make fixtures hermetic.
- Document local/container coverage commands, HTML and CI artifact locations,
  threshold policy, adding regression tests, and approved exclusion procedure.
- Exit gate: all tests and quality checks pass, aggregate line coverage is at
  least 90%, component floors pass, and results reproduce in a clean container.

Completion evidence: PCOV, V8/c8, and coverage.py run under the pinned quality
image and emit JSON, LCOV, Cobertura, and HTML reports where supported. Tests
now cover every MCP tool dispatch, malformed and oversized stdio frames,
worker shutdown/coverage flushing, and Python protocol, parser, import,
inheritance, call, path, limit, and error branches. The hermetic 59-test suite
passes with PHP at 90.25% line coverage, TypeScript/JavaScript at 93.64% lines
and 80.69% branches, and Python at 98%. Each runtime has an independently
enforced 90% floor; JavaScript's measured branch baseline is ratcheted at 79%.
The full hook/CI profile enforces these floors and publishes mounted reports.

Phase 6 exit gate: passed. Coverage policy, reports, exclusions, regression
workflow, and threshold-change procedure are documented in `docs/COVERAGE.md`.

## Phase 7: daily-use project and component workflows

Prioritize the questions developers ask on every session before expanding the
analysis catalogue. Every surface remains bounded, deterministic, and available
through equivalent MCP and CLI commands.

### [x] P7-T01 — Discoverable project catalogue and freshness

Dependencies: Phase 6 exit gate

- Add bounded `list_projects` MCP and `list-projects` CLI surfaces so clients
  can recover stable project IDs without rescanning or inspecting SQLite.
- Return active snapshot status, scan mode/timestamps, graph/file/diagnostic
  counts, and an explicit freshness signal; hide absolute roots by default.
- Support deterministic pagination and validate caps, empty databases, stale
  roots, incomplete scans, and multi-project ordering.
- Exit gate: the fourteenth MCP tool, CLI parity, docs, tests, and coverage
  floors pass in the pinned quality image.

Completion evidence: `list_projects` and `list-projects` expose stable IDs,
active/latest scan metadata, six explicit freshness states, graph counts, and
deterministic pagination while hiding roots by default. Empty/unscanned,
unavailable-root, active, in-progress, pagination, validation, MCP dispatch,
and CLI JSON behavior are covered. The 61-test suite passes and PHP aggregate
line coverage rises to 90.42% with all runtime floors green.

### [x] P7-T02 — Component dossier and bounded neighborhood

Dependencies: P7-T01

- Add `inspect_component` by stable ID or unambiguous name, returning source
  evidence, roles, boundaries, attributes, parent/children, and bounded incoming
  and outgoing relationships in one model-friendly response.
- Make ambiguity explicit and add independent relationship/evidence caps rather
  than silently choosing a candidate.
- Cover external placeholders, missing files, recursive containers, confidence
  filters, truncation, and deterministic ordering.

Completion evidence: `inspect_component` and `inspect-component` accept stable
IDs or names and return identity, attributes, roles, boundaries, parent,
children, evidence, and independently bounded incoming/outgoing relationships.
Ambiguous and missing names remain explicit; confidence filters and stable
truncation reasons are tested. The fifteenth MCP definition, CLI parity, static
analysis, 62-test suite, and coverage gates pass; PHP line coverage is 90.55%.

### [x] P7-T03 — Changed-file and working-tree impact

Dependencies: P7-T02

- Accept bounded project-relative file lists or an opt-in read-only Git working
  tree diff, map them to components, and compute a conservative reverse impact
  set with tests and entry points grouped separately.
- Distinguish directly changed, statically impacted, dynamically uncertain, and
  unresolvable paths; never execute hooks or target-project code.
- Provide MCP/CLI parity, base-ref validation, rename/deletion handling, byte and
  process limits, deterministic fixtures, and evidence-backed warnings.

Completion evidence: `changed_files_impact` and `changed-files-impact` accept
up to 50 normalized explicit paths or an opt-in read-only Git comparison. The
Git adapter resolves base refs, disables optional locks, includes untracked
default-worktree files, reports renames, and enforces process/time/byte caps.
Direct, impacted, entry-point, and unresolved groups remain distinct. Real and
fake Git fixtures, path/mode validation, MCP dispatch, PHPStan, the 63-test
suite, and all coverage floors pass with PHP line coverage at 90.35%.

### [x] P7-T04 — Architecture context bundle for coding agents

Dependencies: P7-T03

- Produce a token-budgeted `architecture_context` bundle from a task description
  or changed files: project summary, likely boundaries, component dossiers,
  policies, impact, and relevant evidence.
- Allocate explicit per-section budgets, report omitted/truncated sections, and
  retain deterministic fallback when optional semantic ranking is unavailable.
- Add golden responses for bug-fix, feature, refactor, and mixed-language tasks.

Completion evidence: `architecture_context` and `architecture-context` compose
project summary, deterministic location ranking, explicit changed-file impact,
and bounded component dossiers under declared per-section and total character
budgets. Included, truncated, omitted, and not-requested states are explicit;
the response reports its serialized size and never executes target code. MCP
dispatch, CLI parity, deterministic/budget/error tests, PHPStan, the 64-test
suite, and all coverage floors pass with PHP line coverage at 90.44%.

### [x] P7-T05 — Safe project lifecycle and database maintenance

Dependencies: P7-T04

- Add project removal, stale-scan cleanup, SQLite checkpoint/optimize, backup,
  and integrity commands with dry-run defaults and explicit destructive intent.
- Keep destructive MCP annotations accurate, serialize maintenance with writer
  leases, and make backups atomic and restorable.
- Test confirmation, active-reader behavior, interrupted maintenance, path
  containment, recovery, and idempotency.

Completion evidence: `remove_project`, `cleanup_stale_scans`, and
`maintain_database` have CLI equivalents and conservative MCP annotations.
Deletion and write maintenance default to previews; writer leases serialize
execution, referenced scans remain protected, and failed maintenance releases
partial leases. File-backed backups use SQLite's online copy, strip transient
leases, reject traversal and overwrites, publish atomically, and reopen with a
passing integrity check. PHPStan, CLI/MCP integration, the 65-test suite, and
all coverage floors pass at 90.44% PHP lines, 93.64% JavaScript/TypeScript
lines with 80.69% branches, and 98% Python.

## Phase 8: architecture evolution and regression control

### [x] P8-T01 — Retained immutable scan snapshots

- Evolve storage so a configurable bounded history survives activation while
  preserving the current atomic read path and migration compatibility.
- Record scanner/config fingerprints, completeness, timing, and retention
  metadata; add pruning without orphaned facts.

Completion evidence: migration 007 adds immutable versioned snapshot archives
without changing the normalized active-read path. Rescans capture the prior
active graph atomically; `snapshot_retention` is configurable from 0–20 through
CLI/MCP scans, defaults to five, and prunes both archives and unreferenced scan
records. Per-table/final-byte caps produce explicit incomplete metadata rather
than partial facts. `list_snapshots` and `list-snapshots` expose fingerprints,
timing, sizes, counts, active/retained state, and completeness. Migration,
compatibility, stable ordering, retention, MCP dispatch, PHPStan, and the
65-test suite pass; PHP line coverage is 90.41%.

### [x] P8-T02 — Snapshot diff and architectural changelog

- Compare two snapshots by stable facts and report added/removed/moved/changed
  components, relationships, roles, boundaries, diagnostics, and confidence.
- Detect rename candidates conservatively, label heuristics, and cap all work.

Completion evidence: `snapshot_diff` and `snapshot-diff` compare retained scan
IDs or the active graph across components, relationships, roles, boundaries,
memberships, diagnostics, and confidence. Added, removed, semantically changed,
and moved components remain distinct. Unique exact kind/display-name rename
matches are labelled possible with their heuristic. A global output cap,
deterministic ordering, unavailable/incomplete archive errors, CLI/MCP parity,
PHPStan, the 66-test suite, and the coverage floor pass at 90.63% PHP lines.

### [x] P8-T03 — Quality budgets and regression gates

- Define checked-in budgets for new cycles, boundary violations, diagnostic
  severity, hub growth, unreferenced candidates, and allowed public-surface
  changes; emit machine-readable failures and SARIF where mappings are sound.
- Support baseline creation/update as an explicit reviewed operation, never an
  automatic suppression.

Completion evidence: `quality_gate` and `quality-gate` evaluate only declared
limits for new static cycles, policy violations, diagnostic severity, maximum
degree growth, unreferenced candidates, and conservative public-surface
changes. Results are machine-readable and fail the CLI exit status; SARIF is
emitted only for boundary/diagnostic findings with sound mappings. Baseline
proposal mode is explicitly requested, marked review-required, and never
applied or written automatically. Validation, pass/fail, policy, SARIF,
proposal, MCP dispatch, PHPStan, and coverage tests pass at 90.54% PHP lines.

### [x] P8-T04 — Trends and release notes

- Report bounded time-series architecture metrics and generate evidence-linked
  Markdown/JSON change summaries suitable for pull requests and releases.
- Test clock behavior, missing history, retention boundaries, and stable output.

Completion evidence: `architecture_trends` and `architecture-trends` expose a
chronological, bounded series of retained and active snapshots with component,
relationship, role, boundary, diagnostic, cycle, hub, and unreferenced metrics.
Incomplete retained snapshots remain explicit timeline points without claiming
metrics, and scanner/config fingerprints preserve provenance. An optional
release baseline produces deterministic structured changes and Markdown with
explicit truncation. Retention-boundary, missing/incomplete history, ordering,
limit validation, release-note, CLI/MCP dispatch, PHPStan, and the 66-test suite
pass; pinned PHP line coverage is 90.55% and the MCP surface now contains 24
tools.

## Phase 9: framework intelligence and extension contracts

### [x] P9-T01 — Symfony and generic PHP framework enrichment

- Extract controllers/routes, services/autowiring, events/subscribers,
  Messenger handlers, console commands, and configuration facts statically.
- Keep generic PHP facts when framework configuration is dynamic or unsupported.

Completion evidence: Composer metadata enables a non-booting Symfony AST
collector for class/method attributes. It emits class-prefixed routes, commands,
service aliases, explicit autowiring, event listeners/subscribers, and Messenger
handlers with source evidence; dynamic route/command/event inputs yield stable
diagnostics. A Symfony classification rule records controller, route handler,
command, service, event, subscriber, and message-handler roles while the generic
PHP graph remains intact. The support matrix states static coverage and YAML/XML
and runtime-container limits. Positive and dynamic fixtures, automatic project
detection, persistence, PHP CS Fixer, PHPStan, and all 67 tests pass.

### [x] P9-T02 — Django, FastAPI, and Python dependency enrichment

- Add static URL/router, view, model, dependency, middleware, task, and settings
  facts without importing modules or evaluating decorators.
- Cover aliases, nested routers, class-based views, async handlers, and dynamic
  fallbacks with explicit uncertainty.

Completion evidence: Python worker protocol 0.2 adds import-free AST enrichment
for FastAPI application/router HTTP decorators, sync/async handlers, dependency
injection, router mounts, and middleware; Django URL patterns, function and
class-based views, models, middleware, and static settings; and task decorators.
Aliases resolve across the scan, absolute-import resolution is corrected, and
edge contributions deduplicate on their persisted identity. Dynamic Django and
FastAPI paths emit `PY_DYNAMIC_ROUTE_PATH` rather than guesses. Framework roles
are classified with evidence, nested routers remain bounded explicit mounts,
Ruff, mypy, persistence checks, and all 68 tests pass.

### [x] P9-T03 — TypeScript application breadth

- Deepen Next.js route/layout/server-action facts and add bounded React/Vue
  component, hook/composable, state, and client-to-route relationships.
- Preserve compiler-based resolution and label convention-derived facts.

Completion evidence: the TypeScript compiler worker now layers probable,
framework-convention roles for Next.js App Router pages, layouts, HTTP handlers,
and server actions; React components/hooks; Vue TypeScript components/composables;
and common state factories. Compiler-resolved hook usage and static Fetch/Axios
endpoint calls add explicit graph edges. Repeated edges conform to storage
identity while mixed type/value imports retain both variants. A seven-file
fixture verifies route paths, roles, endpoint methods, classification
persistence, ESLint/Prettier, and all 69 tests pass. Vue SFC parsing remains an
explicit documented limit rather than a heuristic claim.

### [x] P9-T04 — Versioned scanner/enricher SDK and conformance kit

- Publish schemas, fixture builders, golden protocol cases, and a conformance
  runner for third-party isolated scanners/enrichers.
- Add capability negotiation and reject unsupported versions before scanning.

Completion evidence: SDK v1 publishes JSON Schema 2020-12 manifest and
contribution contracts, the full lifecycle/trust specification, a typed PHP
fixture builder, and language-neutral golden cases. The executable
`tools/scanner-conformance` validates initialization, explicitly required
capabilities, discovery, empty scans, contributions, and shutdown through the
production supervisor. Protocol/output mismatches and missing capabilities fail
before scan paths with stable codes. Builder/decoder round trips, schema/golden
alignment, positive conformance, negative capability negotiation, PHPStan, and
all 70 tests pass. Pinned aggregate coverage remains above every runtime floor:
PHP 90.57%, TypeScript/JavaScript 94.62% lines and 79.27% branches, and Python
96%.

Phase 9 exit gate: PHP/Symfony, Python/Django/FastAPI, and TypeScript application
frameworks produce evidence-backed static facts without booting target code,
and external scanner authors have a versioned, executable compatibility kit.

## Phase 10: automation, configuration, and interchange

### [x] P10-T01 — Checked-in project configuration

- Add a documented versioned `knossos.json`/JSONC schema for boundaries,
  policies, ignores, limits, framework hints, retention, and quality budgets.
- Define CLI/MCP override precedence and diagnose unknown or unsafe settings.

Completion evidence: Knossos automatically loads an exclusive root
`knossos.json` or JSONC file under a published JSON Schema 2020-12 v1 contract.
It validates and fingerprints bounded ignores, scan limits, explicit boundaries,
framework hints, snapshot retention, architecture policies, and quality budgets.
Explicit CLI/MCP values override checked-in values, which override safe defaults;
an explicit empty boundary list is meaningful. Unknown keys, versions,
frameworks, traversal/absolute matchers, oversized files, and malformed values
fail before discovery with stable `PROJECT_CONFIG_*` prefixes. Scan output
reports non-secret configuration provenance. JSONC, framework activation,
ignored files, persistence, cache invalidation inputs, explicit overrides,
unknown keys, unsafe paths, PHPStan, and all 71 tests pass.

### [x] P10-T02 — Watch and daemon scan orchestration

- Add debounced incremental rescans with bounded queues, coalescing, overflow to
  full scan, graceful shutdown, and observable status; keep watch opt-in.

Completion evidence: opt-in `knossos watch` performs an initial atomic scan and
portable bounded polling over validated language/configuration fingerprints.
Path-keyed queues coalesce repeated changes during debounce; normal batches use
incremental contribution replay, while overflow discards unbounded detail and
promotes the next scan to full mode. JSON lifecycle events expose ready, scan,
overflow, error, and stopped states; result metrics report polls, scan modes,
coalescing, overflow, and pending work. SIGINT/SIGTERM cancel gracefully, config
and ignore limits remain authoritative, and event history is capped. Tests cover
incremental edits, multi-file overflow recovery, cancellation, invalid limits,
PHPStan, and all 72 tests pass.

### [x] P10-T03 — Portable graph bundle

- Export/import a versioned deterministic compressed bundle with manifest,
  checksums, schema compatibility, redaction modes, and atomic validation.
- Never import absolute roots, executable payloads, or unbounded archives.

Completion evidence: `export-bundle` and `import-bundle` implement canonical
JSON gzip interchange under a published v1 schema. Manifests carry payload
SHA-256, format/redaction version, deterministic source timestamp, byte count,
and fact count; repeated exports are byte-identical. None/path/strict modes can
redact evidence paths, attributes, diagnostics, and owner metadata. Import caps
compressed/decompressed bytes and facts, whitelists structure, verifies checksum
and counts, rejects traversal/dangling references, remaps all identities, uses a
synthetic non-absolute root, and activates only after one successful transaction.
Bundles never contain source, commands, caches, database pages, or executable
payloads. Tests cover determinism, graph equivalence, all redaction levels,
tampering, duplicate import, malformed gzip, rollback, and all 73 tests pass.

### [x] P10-T04 — CI and editor integration recipes

- Provide stable exit codes, SARIF/Markdown outputs, GitHub/GitLab examples, and
  editor/task recipes using the same container and policy gates.

Completion evidence: the CLI automation contract now documents distinct
success, evaluated-gate failure, and execution-error statuses. Ready-to-adapt
GitHub Actions and GitLab CI recipes build the pinned runtime, scan a read-only
workspace without networking, persist reviewed graph history separately,
preserve quality-gate status, and publish JSON, SARIF, and deterministic
Markdown. The GitHub recipe uploads SARIF to code scanning while the GitLab
recipe correctly retains it as an artifact rather than claiming compatibility
with GitLab's separate SAST schema. VS Code tasks use the same containerized
quality profile and scan isolation. The integration guide documents explicit
baseline adoption, cache trust boundaries, and report extraction.

Phase 10 exit gate: checked-in configuration, bounded watch mode, portable
redacted graph interchange, and CI/editor automation are implemented and
documented with deterministic behavior and validation coverage.

## Phase 11: maximum reliability, scale, and supply-chain quality

### [x] P11-T01 — Reproducible benchmark corpus and performance budgets

- Add generated small/medium/large mixed-language corpora, cold/incremental
  benchmarks, memory/SQLite/query budgets, and regression artifacts in CI.

Completion evidence: a deterministic generator creates balanced PHP,
TypeScript, and Python dependency corpora at 18, 90, and 300 source files and
reports a content digest. The isolated runner measures cold and one-file
incremental scans, an architecture summary query, full CLI/scanner process-tree
RSS, and SQLite size against versioned JSON budgets. It cleans generated data,
writes a structured report under `coverage/benchmarks`, fails on regression,
and runs in the pinned full quality profile; CI uploads it with the other
quality reports. The initial local reference run passed every small, medium,
and large budget.

### [x] P11-T02 — Property, fuzz, differential, and mutation testing

- Fuzz JSON-RPC, paths, parsers, config, migrations, and graph invariants; add
  property tests for determinism/atomicity and differential full-vs-incremental
  scans. Enforce a reviewed mutation-score floor on critical PHP logic.

Completion evidence: fixed-seed tests generate hundreds of normalized and
hostile paths, JSONC strings, project configurations, scanner contributions,
JSON-RPC message shapes, and stable identifiers with bounded, stable failure
assertions. Five seeded edit rounds prove incremental graph output identical to
a subsequent full scan. A non-destructive isolated mutation runner exercises
eight reviewed semantic mutants for the critical path validator, publishes a
structured report, and enforces a versioned 90% floor in the pinned full
profile; the initial mutation score is 100%. Reproduction and review rules are
documented, and discovered cases become checked-in deterministic regressions.

### [x] P11-T03 — Concurrency, fault injection, and recovery matrix

- Exercise worker crashes, partial writes, disk-full/locked SQLite, cancellation,
  signals, corrupted caches, stale leases, and process-tree cleanup under load.
- Publish the supported recovery behavior and stable diagnostics for each fault.

Completion evidence: fault injection now covers SQLite page exhaustion and
recovery, competing database locks, corrupt derived-cache rebuilds, cancelled
workers with spawned children, plus the existing crash, timeout, output flood,
transaction rollback, cancellation, stale lease, and backup integrity cases.
Linux supervision snapshots and terminates the complete worker descendant tree
before it can detach. CLI failures expose stable diagnostic families for
worker, busy, cancelled, discovery, storage, argument, and runtime errors. The
published recovery matrix states observable codes, preserved state, retry or
restore behavior, and platform limits for every supported fault class.

### [x] P11-T04 — Supply-chain and release hardening

- Generate SBOMs for runtime and quality images, scan dependencies/images,
  verify pinned downloads, produce provenance, sign release artifacts, and test
  installation/upgrade/rollback from clean environments.

Completion evidence: the full pinned profile emits separate CycloneDX runtime
and development SBOMs, fails on fixed HIGH/CRITICAL runtime vulnerabilities and
Dockerfile misconfigurations, and retains the broader quality-image report for
review. Trivy and Cosign downloads have immutable versions and verified
SHA-256s. Provenance binds the runtime and quality image IDs to the Dockerfile
and dependency locks; an ephemeral encrypted key signs it and the gate verifies
the Sigstore bundle offline without release secrets. The runtime excludes npm
after the TypeScript worker is installed, eliminating an otherwise unshipped
package-manager vulnerability surface. A clean named-volume lifecycle passes
install/doctor, read-only mixed scan, idempotent upgrade/migration, atomic
backup restore, and post-rollback architecture queries.

### [x] P11-T05 — Documentation and API contract excellence

- Generate MCP/CLI/schema reference from source, validate every command example,
  check internal/external links, and maintain task-oriented tutorials and
  troubleshooting with versioned migration guides.
- Add per-component coverage floors for transports, storage, discovery, query,
  and each scanner; publish complexity, duplication, mutation, benchmark, and
  documentation-quality reports without weakening the existing global floors.

Completion evidence: deterministic CLI and MCP references are generated from
the live application help and tool schemas and fail the fast profile when
stale. Documentation checks validate internal targets, local shell entrypoints,
secure external-link syntax, and all ten external references in the networked
full profile. The troubleshooting and migration guide covers stable diagnostic
families, safe recovery, transactional schema upgrades, backups, rollback, and
versioned protocol/configuration changes. A cross-language maintainability
report publishes file size, decision density, and normalized duplication
baselines alongside mutation and benchmark artifacts. PHP now captures its
isolated scanner subprocess and enforces nine component floors; TypeScript and
Python retain independent scanner floors. All 83 deterministic tests pass and
fresh aggregate coverage is 90.18% PHP, 94.62% TypeScript/JavaScript lines with
79.27% branches, and 96% Python.

Phase 11 exit gate: all functional, coverage, mutation, fuzz, benchmark,
documentation, supply-chain, clean-install, upgrade, and recovery gates pass in
pinned reproducible environments with reviewed exceptions only.

## Phase 12: maintainability, performance, and maximal useful coverage

### [x] P12-T01 — Typed public contracts and documentation blocks

- Audit PHP, TypeScript, and Python public APIs for precise native types and
  useful PHPDoc, TSDoc/JSDoc, and docstrings; document invariants, ownership,
  exceptions, units, and non-obvious generics without repeating signatures.
- Generate public API reference material and fail CI when exported contracts
  are undocumented, stale, or inconsistent with static analysis.

Completion evidence: all 37 exported PHP interfaces and isolated TypeScript and
Python worker contracts now require descriptive summaries; the TypeScript
worker surface additionally declares JSDoc parameter and result shapes, Python
protocol entrypoints carry docstrings, and the repository transaction preserves
its generic callback/result type. A generated language API reference joins the
generated CLI and MCP references, and stale output or missing contract
documentation fails the fast profile. PHPStan, ESLint, strict mypy, Ruff, API
documentation checks, and all 85 tests pass.

### [x] P12-T02 — Complexity, duplication, and dead-code ratchets

- Publish per-language complexity, duplication, oversized-method, dependency,
  and dead-code reports with reviewed baselines and no automatic suppression.
- Refactor the highest-risk code into cohesive typed units while preserving
  protocols, stable diagnostics, and deterministic graph output.

Completion evidence: the cross-language report now records file decision
density, PHP per-function complexity/length, direct dependency fanout,
normalized duplicate windows, and prerequisite dead/unreachable-code gates.
ESLint enforces TypeScript/JavaScript complexity and function length; Ruff
enforces Python McCabe, branch, and statement limits; PHP metrics and all shared
budgets are checked-in monotonic ratchets. Extracting the bounded typed Git
process runner removed duplicated timeout, byte-limit, pipe, error, and cleanup
logic, reducing cross-file duplicate windows from 24 to 18 and total decision
points despite adding the reusable unit. Provider behavior, PHPStan, and Git
integration tests pass without protocol or diagnostic changes.

### [x] P12-T03 — Profiling-driven performance improvements

- Profile discovery, worker transport, reconciliation, SQLite writes/queries,
  and serialization on the reproducible corpora; optimize measured hot paths
  and record before/after time, memory, query, and database-size results.
- Add targeted performance regression tests for every accepted optimization
  and require semantic equivalence with the existing differential suite.

Completion evidence: scan envelopes and benchmark artifacts now break down
configuration, discovery, planning, PHP/TypeScript/Python scanners, analysis,
and reconciliation in milliseconds. The profile identifies TypeScript compiler
startup as the dominant fixed cost and snapshot-aware reconciliation as the
largest scaling core stage. Reconciliation now reuses prepared SQLite
statements for all hot fact writes; a targeted test locks that behavior while
store, reconciliation, seeded full-vs-incremental differential, and 85 total
tests preserve semantics. The new reference run measured 1.37/1.42/1.51-second
cold and 1.30/1.36/1.57-second incremental small/medium/large scans, versus the
earlier 6.15/3.26/3.12 and 2.87/2.83/3.18 observations. All timing/storage and
medium/large memory budgets were tightened monotonically with runner variance
retained.

### [x] P12-T04 — Component and changed-line coverage ratchets

- Enforce per-component line and branch floors for transport, storage,
  discovery, reconciliation, query, configuration, maintenance, and all three
  scanners; require 100% coverage for feasible changed executable lines.
- Raise aggregate coverage toward the maximum meaningful level with boundary,
  error, cleanup, and property tests, excluding only generated or provably
  unreachable code through explicit reviewed configuration.

Completion evidence: malformed configuration matrices, every PHP worker request
boundary, stdio cancellation/pending-frame paths, and prepared-statement reuse
now have behavior-level regression coverage. The 85-test suite raises PHP from
90.18% to 91.04%, discovery/configuration from 84.73% to 87.53%, the PHP scanner
from 89.12% to 90.68%, and transport from 87.65% to 92.18%. Checked-in floors
now ratchet PHP aggregate coverage to 91%, TypeScript/JavaScript to 94.6% lines
and statements, 79.2% branches, and 97% functions, and Python to 96%. All nine
PHP component floors moved to or retained their strongest reproducible value;
generated/vendor code remains excluded and no new first-party exclusion was
introduced.

### [x] P12-T05 — Final maintainability and performance audit

- Run the complete functional, static, documentation, complexity, duplication,
  mutation, fuzz, benchmark, supply-chain, and coverage profiles from a clean
  pinned environment and resolve every unreviewed warning or regression.
- Publish final component metrics, remaining justified limitations, and the
  workflow for keeping every ratchet monotonic.

Completion evidence: the clean pinned full profile passes PHPStan, PHP CS Fixer,
ESLint, Prettier, Markdownlint, Ruff, mypy, ShellCheck, Hadolint, generated API/
CLI/MCP reference checks, internal and external documentation checks, all 85
tests, Composer/npm audits, MCP Inspector interoperability, runtime doctor,
clean install/upgrade/verified rollback, Trivy vulnerability gates, SBOM and
provenance generation, offline signature verification, all performance budgets,
100% mutation score, and the ratcheted three-language coverage gates. The final
benchmark records small/medium/large cold scans at 2.41/2.49/2.49 seconds and
incremental scans at 2.56/2.53/2.76 seconds on this runner, within the tightened
budgets. Remaining uncovered paths are predominantly platform/process failures,
defensive cleanup, or parser variants; they remain visible in component reports
rather than hidden behind exclusions.

Phase 12 exit gate: public contracts are precisely typed and usefully
documented, measured hot paths improve without semantic drift, maintainability
ratchets have no unreviewed exceptions, and meaningful executable behavior is
covered as completely as practical across PHP, TypeScript, and Python.
