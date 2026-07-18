# Knossos MCP

Knossos builds a local, evidence-backed architecture graph for PHP, Laravel,
Symfony, TypeScript, JavaScript, Python, and mixed repositories. It exposes scanning, component
lookup, summaries, flow explanation, impact analysis, boundaries, and
architecture search through MCP stdio and equivalent CLI commands. Advanced
analysis also reports bounded dependency cycles/strongly connected components,
structural hubs/hotspots, and uncertainty-labelled dead-code candidates.
It can also check declared boundary policies and rank existing boundaries for a
described feature with inspectable deterministic factors. Read-only Git history
can be blended into static impact as explicitly heuristic change-risk signals.
The active bounded graph can be exported as deterministic Mermaid or PlantUML
source without bundling or invoking a renderer.

MCP stdio remains the default transport. A constrained local-first Streamable
HTTP JSON profile and its deployment limits are documented in the [HTTP threat
model](docs/HTTP-THREAT-MODEL.md).
The Python worker uses the standard-library AST in an isolated interpreter and
never imports target modules. See [Python support](docs/PYTHON-SUPPORT.md) for
the supported facts and static-analysis limits.
Symfony attribute and service analysis is documented in
[Symfony support](docs/SYMFONY-SUPPORT.md).
Compiler-backed Next.js, React, Vue, state, and client endpoint signals are
documented in [TypeScript application support](docs/TYPESCRIPT-APPLICATION-SUPPORT.md).

The recommended distribution is Docker: it pins PHP 8.4, Node 24, Python, Composer,
SQLite, the PHP parser, and TypeScript compiler so the host project does not
need those runtimes. See [installation](docs/INSTALLATION.md), [container
operation](docs/CONTAINER.md), and the [project specification](docs/PROJECT-SPEC.md).
Boundary dependency rules are documented in [architecture
policies](docs/ARCHITECTURE-POLICIES.md).
Development quality gates and hook installation are documented in
[quality gates](docs/QUALITY.md).
Persisted project discovery and freshness states are documented in the
[project catalogue](docs/PROJECT-CATALOG.md).
Single-call component dossiers are documented in
[component inspection](docs/COMPONENT-INSPECTION.md).
Explicit and Git-discovered change-set analysis is documented in
[changed-file impact](docs/CHANGED-FILES-IMPACT.md).
Bounded, task-oriented evidence bundles for coding agents are documented in
[architecture context](docs/ARCHITECTURE-CONTEXT.md).
Dry-run project cleanup, integrity checks, and atomic SQLite backups are
documented in [maintenance](docs/MAINTENANCE.md).
Bounded immutable history across successful scans is documented in
[retained snapshots](docs/SNAPSHOTS.md).
Evidence-backed architectural changelogs are documented in
[snapshot diff](docs/SNAPSHOT-DIFF.md).
Reviewable regression gates are documented in
[architecture quality budgets](docs/QUALITY-BUDGETS.md).
Historical metrics and generated change summaries are documented in
[architecture trends](docs/ARCHITECTURE-TRENDS.md).
Third-party worker contracts, schemas, fixtures, and conformance testing are
documented in the [scanner SDK](docs/SCANNER-SDK.md).
Versioned checked-in ignores, boundaries, limits, framework hints, policies,
and budgets are documented in [project configuration](docs/PROJECT-CONFIGURATION.md).
Bounded incremental polling and graceful daemon operation are documented in
[watch mode](docs/WATCH-MODE.md).
Deterministic, checksummed, redacted architecture interchange is documented in
[portable graph bundles](docs/GRAPH-BUNDLES.md).
Stable automation exit codes, SARIF/Markdown reports, and ready-to-adapt
GitHub, GitLab, and editor recipes are documented in [CI and editor
integration](docs/CI-EDITOR-INTEGRATION.md).
Reproducible mixed-language corpus benchmarks and enforced runtime, memory,
query, and storage limits are documented in [performance
budgets](docs/PERFORMANCE-BUDGETS.md).
Deterministic property/fuzz corpora, differential scans, and the enforced
critical-path mutation score are documented in [adversarial
testing](docs/ADVERSARIAL-TESTING.md).
Documented behavior for worker, cancellation, lock, disk, cache, transaction,
and database-integrity failures is in the [fault recovery
matrix](docs/RECOVERY-MATRIX.md).
Runtime/development SBOMs, fixed-vulnerability gates, verified provenance
signing, and clean install/upgrade/rollback checks are documented in
[supply-chain and release assurance](docs/SUPPLY-CHAIN.md).
The generated command and protocol contracts are available in the [CLI
reference](docs/CLI-REFERENCE.md), [MCP tool reference](docs/MCP-REFERENCE.md),
and [language API reference](docs/API-REFERENCE.md).
Operational diagnosis and safe database, protocol, and configuration upgrades
are covered by the [troubleshooting and migration guide](docs/TROUBLESHOOTING-AND-MIGRATIONS.md).
Monotonic complexity, function-size, dependency, and duplication gates are
documented in [maintainability ratchets](docs/MAINTAINABILITY.md).

Quick check:

```sh
docker build -t knossos-mcp:dev .
docker run --rm knossos-mcp:dev doctor --json
```

Scan source with networking disabled and a read-only mount:

```sh
docker run --rm --network none \
  --mount type=bind,source=/absolute/project,target=/workspace,readonly \
  --mount type=volume,source=knossos-data,target=/data \
  knossos-mcp:dev scan /workspace --json
```

List persisted project IDs for later architecture queries without exposing
absolute roots:

```sh
docker run --rm \
  --mount type=volume,source=knossos-data,target=/data \
  knossos-mcp:dev list-projects --json
```
