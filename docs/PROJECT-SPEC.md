# Knossos MCP: Architecture Intelligence for Software Projects

Status: Draft for discussion  
Initial targets: PHP 8.4/Laravel, TypeScript/JavaScript, and Python projects  
Source: `Architecture-MCP-Project-Plan.docx`

## 1. Product summary

Knossos is a local-first MCP server that scans a software project, builds an
evidence-backed architecture graph, and exposes bounded queries that help an AI
agent reason about structure, dependencies, flows, and change impact without
repeatedly searching the whole source tree.

The graph is an index of facts inferred from source code and framework
conventions. It is not a replacement for source code. Every result must be
traceable to a file and source location, and uncertain inferences must be
labelled with their confidence and origin.

### Product promise

After one scan, an agent should be able to answer questions such as:

- What are the major modules and entry points?
- What depends directly or transitively on `UserRepository`?
- How can a checkout request reach invoice generation?
- Where would a refunds feature fit the existing architecture?
- Which dependencies cross declared or inferred boundaries?

## 2. Goals and success measures

### Goals

1. Build a deterministic, queryable model of PHP, TypeScript, Python, and mixed
   codebases.
2. Return compact architectural answers with supporting source evidence.
3. Refresh the model incrementally when files change.
4. Keep language and framework extraction behind plugin contracts.
5. Degrade honestly when dynamic dispatch, generated code, or framework runtime
   behavior prevents certainty.

### MVP success measures

- At least 95% of supported PHP, TypeScript, and Python declarations in the fixture
  projects are indexed with correct locations. Supported declarations include
  classes, interfaces, traits (PHP), enums, functions, and methods.
- At least 90% precision for supported static relationship types on labelled
  fixtures. Recall is measured and reported separately by edge type.
- A no-change rescan parses no unchanged source files.
- A one-file change produces the same final graph as a clean full scan.
- Typical lookup and one-hop dependency queries complete in under 200 ms on a
  10,000-file project on a developer laptop, excluding initial process start.
- Every returned architectural claim includes evidence or is explicitly marked
  as an inference.
- Tool responses stay within configurable result and depth limits.

These are acceptance targets, not performance guarantees for arbitrary
repositories. A benchmark fixture and machine profile will make them
reproducible.

## 3. Scope

### MVP: PHP, Laravel, TypeScript, and Python structural intelligence

- Local repositories and `stdio` transport.
- Native installation plus a Docker distribution bundling matched PHP, Node,
  Python, Composer, and SQLite runtimes.
- Composer/PSR-4, `package.json` workspace, and `tsconfig.json` project
  discovery.
- PHP declaration parsing with `nikic/php-parser` and TypeScript parsing plus
  symbol resolution with the TypeScript compiler API.
- Laravel-aware discovery for common entry points and framework relationships.
- TypeScript module, declaration, import/export, inheritance, reference,
  injection, construction, and resolvable call relationships.
- Python package/module, import, class, function/method, inheritance, call,
  decorator, stub, and syntax-diagnostic extraction through the stdlib AST.
- Mixed-repository support across PHP, TypeScript/JavaScript, and Python.
- SQLite persistence.
- Full and incremental scans.
- Component lookup, summary, dependency, bounded path, impact, and boundary
  queries.
- Read-only queries after an explicit scan.
- Structured MCP tool results suitable for both models and humans.

### Later phases

- Richer runtime flow heuristics, cycles, hotspots, and dead-code candidates.
- Semantic search and feature-location suggestions.
- Declared architecture rules and violation detection.
- Git history and change-frequency signals.
- Additional language and framework plugins, including TypeScript framework
  enrichers such as NestJS or Express where demand warrants them.
- Streamable HTTP, IDE integrations, visual exports, and live file watching.

### Non-goals for the MVP

- Proving all possible runtime call paths in dynamic PHP or TypeScript/JavaScript.
- Executing the target application, booting its Laravel container, or running
  Node package lifecycle scripts.
- Replacing an IDE language server, static analyser, or text search.
- Editing project source code.
- Inferring business meaning with a required external LLM or embedding service.
- Neo4j, a web UI, remote multi-user hosting, or continuous file watching.

## 4. Design principles

1. **Evidence first.** Nodes and edges point to the source that produced them.
2. **Deterministic core.** The same inputs and scanner versions produce the
   same graph; semantic ranking is an optional layer.
3. **Honest uncertainty.** Relationships have an origin and confidence rather
   than being presented as equally certain.
4. **Bounded answers.** Every traversal has depth, result, and time limits.
5. **Replaceable edges.** Parsers, framework enrichers, graph storage, and MCP
   transport depend on internal interfaces.
6. **Safe by default.** Scan roots are explicit and filesystem traversal cannot
   escape them.
7. **Rebuildable index.** SQLite is derived state. Deleting it and scanning
   again must restore the same current graph.

## 5. Users and primary workflows

### Primary user

A developer using an MCP-capable coding agent against a local PHP, Laravel,
TypeScript, or mixed-language project.

### Setup workflow

1. Configure an allowed project root and ignored paths.
2. Start Knossos over `stdio`.
3. Run `scan_project` once, or invoke the equivalent CLI command.
4. Query the indexed project through architecture tools.
5. Rescan after meaningful edits; only changed and affected files are rebuilt.

### Query workflow

1. Resolve a user term to one or more symbols.
2. Ask the user or return candidates when resolution is ambiguous.
3. Execute a bounded graph query.
4. Rank results using graph distance, edge confidence, and architectural role.
5. Return a concise explanation plus machine-readable nodes, edges, and source
   evidence.

## 6. Conceptual architecture

```text
Filesystem + package manifests + framework config
                       |
                Project discovery
                       |
       +---------------+----------------+----------------+
       |                                |                |
      PHP scanner worker        TypeScript worker   Python worker
       |                                |                |
       +---------------+----------------+----------------+
                       |
          Framework enrichers (Laravel MVP)
                       |
          Symbols + relationships + evidence
                       |
               Graph build/reconciliation
                       |
                  SQLite store
                       |
                  Query engine
                       |
             MCP adapter / CLI adapter
```

### Component responsibilities

- **Project discovery:** validates the root, applies ignore rules, discovers
  Composer and Node/TypeScript package boundaries and configuration, and
  fingerprints eligible files.
- **Language scanner:** analyses the applicable project/file set and emits
  language-level declarations, references, and diagnostics without writing
  storage.
- **Framework enricher:** converts framework conventions and configuration into
  additional typed nodes and edges; Laravel is the initial implementation.
- **Graph builder:** resolves symbols, deduplicates facts, assigns stable IDs,
  and reconciles per-file contributions transactionally.
- **Graph store:** persists current facts, scan metadata, and diagnostics.
- **Query engine:** performs resolution, traversal, ranking, summarisation, and
  result limiting independently of MCP.
- **MCP adapter:** validates tool inputs, maps results to MCP content, reports
  progress/errors, and contains no graph logic.

## 7. Graph model

### Node kinds

The storage model accepts plugin-defined kinds. The initial language and Laravel
plugins emit:

- Project, package, namespace, module, file
- Class, interface, trait, enum, type alias, method, function, property,
  namespace, module
- Controller, service, repository, model, event, listener, job, middleware,
  command, provider, policy, route

Framework roles are preferably represented as classifications on a language
symbol rather than duplicate nodes. A route is a distinct node because it has
its own identity and evidence outside a class declaration.

### Edge kinds

- Structural: `contains`, `declares`, `extends`, `implements`, `uses_trait`
- Dependency: `imports`, `exports`, `re_exports`, `references`, `injects`,
  `constructs`, `calls`, `returns`
- Laravel: `routes_to`, `dispatches`, `listens_to`, `handles`, `uses_middleware`,
  `binds`, `observes`
- Architectural: `belongs_to`, `depends_on`, `crosses_boundary`

`depends_on` is a derived umbrella relationship and must retain links to the
lower-level facts from which it was calculated. It should not erase edge type.

### Required fact metadata

Each node and edge includes:

- Stable ID and canonical name.
- Kind and plugin namespace.
- Owning project and optional parent symbol.
- Source file plus start/end line when applicable.
- Extraction origin: `ast`, `composer`, `config`, `framework_convention`,
  `derived`, or `user_rule`.
- Confidence: `certain`, `probable`, or `possible`.
- Scanner name and version.
- Extensible JSON attributes for plugin-specific fields.

Confidence is rule-based in the MVP. For example, an explicit `implements` edge
or compiler-resolved TypeScript import is `certain`; a container binding read
from a supported configuration pattern may be `probable`; resolution of a
dynamic method call may be `possible` or omitted.

### Stable identity

- Project ID: normalized, canonical project root hash plus an optional explicit
  project name.
- Symbol ID: project ID + language + symbol kind + canonical fully qualified
  name/signature.
- Route ID: project ID + HTTP methods + normalized URI + action identity.
- Edge ID: project ID + edge kind + source ID + target ID + evidence identity.

Paths stored for display are project-relative. Absolute paths are used only for
root validation and file access, preventing a project move from changing every
symbol identity.

## 8. SQLite storage sketch

The first implementation should favour an explicit relational schema over a
generic property graph package:

```text
projects(id, name, root_realpath, config_json, active_scan_id)
scans(id, project_id, mode, status, started_at, finished_at, scanner_set_hash)
files(id, project_id, relative_path, content_hash, size, mtime, language,
      scanner_version, last_scan_id)
nodes(id, project_id, kind, canonical_name, display_name, parent_id,
      file_id, start_line, end_line, origin, confidence, attributes_json)
edges(id, project_id, kind, source_id, target_id, file_id, start_line,
      end_line, origin, confidence, attributes_json)
diagnostics(id, project_id, scan_id, file_id, severity, code, message,
            start_line, end_line)
boundaries(id, project_id, name, matcher_json, source)
```

Indexes are required on canonical/display names, node and edge kinds, edge
source/target IDs, file path, and project ID. SQLite foreign keys and WAL mode
should be enabled. Schema migrations are versioned; scanner-version changes can
invalidate affected files without invalidating unrelated plugins.

## 9. Scanner and plugin contracts

Scanner contracts must be language-neutral. A scanner runs as a supervised
worker in the language's native runtime and does not know about SQLite or MCP.
This isolation keeps the orchestrator independent from parser ecosystems and
allows scanner crashes and resource use to be contained. It matters for
TypeScript in particular: the compiler API must
build a `Program` from `tsconfig.json` to resolve symbols correctly, so a
strictly one-file-at-a-time interface is insufficient.

Conceptual core-side contract (shown in PHP-style pseudocode only):

```php
interface ScannerPlugin
{
    public function manifest(): ScannerManifest;
    public function discover(ProjectContext $project): ScannerInputSet;

    /** @return iterable<ScanContribution> nodes, unresolved edges, diagnostics */
    public function scan(ProjectContext $project, ChangeSet $changes): iterable;
}

interface ProjectEnricher
{
    public function manifest(): ScannerManifest;

    /** @return iterable<ScanContribution> cross-file/framework facts */
    public function enrich(ProjectSnapshot $snapshot, ChangeSet $changes): iterable;
}
```

`ScannerManifest` declares scanner/version, supported languages and extensions,
configuration inputs, execution mode, capabilities, and output schema version.
Every contribution has an owner key (normally a file plus scanner, or an
enricher plus input-set hash) so it can be replaced incrementally.

### Out-of-process scanner protocol

Language scanners use newline-delimited JSON-RPC over standard input/output.
The TypeScript scanner runs in Node with the TypeScript compiler API; the PHP
scanner runs with `nikic/php-parser`; and Python runs in an isolated interpreter
using the standard-library AST. The minimal protocol includes:

- `initialize`: negotiate protocol/output schema versions and capabilities.
- `discover`: return recognized project configs and source inputs.
- `scan`: accept the project root, config, change set, and limits; stream
  contributions and diagnostics.
- `cancel`: stop an in-progress scan.
- `shutdown`: terminate cleanly.

Only protocol frames use worker `stdout`; logs use `stderr`. The core supervises
timeouts, output size, exit status, and cancellation. Scanner output is schema
validated before graph reconciliation. This protocol also becomes the public
extension seam for future Java, Go, or Rust scanners without requiring
them to run inside PHP.

Relationships may initially target an unresolved canonical symbol reference.
The graph builder resolves references after all changed files are parsed and
retains unresolved references as diagnostics or external-symbol nodes according
to configuration.

### PHP MVP extraction rules

- PSR-4 namespaces and Composer packages.
- Declarations, inheritance, interface implementation, trait use, attributes,
  type hints, constructor injection, object construction, static calls, and
  resolvable method calls.

### Laravel MVP enrichment rules

- Routes declared using common `Route::*` calls, controller arrays/classes,
  groups, prefixes, names, and middleware.
- Controllers, commands, jobs, events, listeners, middleware, providers,
  policies, models, and repositories using explicit inheritance/interfaces,
  registrations, and conservative naming conventions.
- Event/listener mappings and service-container bindings when expressed in
  supported static PHP patterns.

### TypeScript MVP extraction rules

- `tsconfig.json` discovery including `extends`, project references, path
  aliases, include/exclude rules, and JavaScript inclusion when `allowJs` is
  enabled.
- npm-compatible package and workspace boundaries from `package.json`, without
  installing packages or running scripts.
- Modules, namespaces, classes, interfaces, type aliases, enums, functions,
  methods, properties, and source locations.
- Imports, dynamic imports with static string arguments, exports/re-exports,
  inheritance, interface implementation, type references, object construction,
  and compiler-resolved call targets.
- Constructor/parameter injection represented only where explicit types and a
  supported convention or later framework enricher justify it.
- External package references as shallow external nodes; `node_modules` source
  is not scanned by default.
- `.ts`, `.tsx`, `.mts`, and `.cts`; `.js`, `.jsx`, `.mjs`, and `.cjs` only when
  enabled by the applicable TypeScript configuration.

The MVP is framework-neutral for TypeScript. React components and hooks may be
classified from explicit syntax, but framework-specific flow semantics for
NestJS, Express, Next.js, or other ecosystems require separate enrichers and
support matrices.

Unsupported dynamic expressions generate a diagnostic where useful; Knossos
must not execute arbitrary PHP/JavaScript code to resolve them.

## 10. Scanning and incremental reconciliation

### Full scan

1. Resolve and validate the requested root against configured allowed roots.
2. Load project config, ignore patterns, Composer/Node/TypeScript metadata, and
   scanner set.
3. Enumerate files without following symlinks outside the root.
4. Fingerprint eligible inputs using a content hash. Modification time and size
   may avoid unnecessary hashing but cannot be the final correctness signal.
5. Parse files and collect per-file contributions and diagnostics.
6. Run project/framework enrichers.
7. Resolve references and calculate derived edges.
8. Reconcile into SQLite in transactions and atomically mark the successful
   scan active.

### Incremental scan

1. Compare discovered files, hashes, configuration hashes, and scanner versions
   with the active snapshot.
2. Remove contributions owned by deleted files.
3. Replace contributions owned by added or changed files.
4. Re-resolve inbound and outbound unresolved references for changed symbols.
5. Rerun only enrichers whose declared inputs changed.
6. Recompute impacted derived edges and summaries.
7. Commit the new active snapshot only if reconciliation succeeds.

Correctness invariant: a completed incremental scan and a clean full scan of the
same tree produce equivalent current nodes, edges, and diagnostics.

Concurrent queries continue reading the last successful snapshot. Only one scan
per project may reconcile at a time. Cancellation or failure leaves the prior
snapshot active.

### Default exclusions

`.git`, `vendor`, `node_modules`, generated caches, build output, binary files,
and the Knossos database itself. Users may override project-relative patterns.
Composer vendor and Node package dependencies can be indexed as shallow external
symbols without scanning their implementation.

## 11. Query semantics

### Symbol resolution

Resolution order is exact canonical name, exact short/display name, prefix,
then fuzzy match. Ambiguous input returns ranked candidates rather than silently
choosing one. All symbol-accepting tools can also accept a stable symbol ID.

### Flow explanation

`explain_flow` finds bounded directed paths over an allowlist of edge kinds. It
is an explanation of plausible statically supported paths, not proof of runtime
execution. Ranking should prefer:

1. Higher-confidence edges.
2. Fewer hops.
3. Framework-semantic edges over generic references.
4. Paths with direct source evidence.

The response groups paths and explains why each hop exists.

### Impact analysis

Impact is reverse traversal from a resolved symbol. Results are grouped into:

- Direct dependants.
- Transitive dependants by distance.
- Framework entry points and boundaries reached.
- Potential test files, when test classification is available.

This reports potential static blast radius, not a guarantee that a change will
break each dependant. Callers can filter edge types, confidence, and maximum
depth.

### Boundaries

The MVP supports explicit, configurable path/namespace/module matchers. Inferred
boundaries based on Composer packages, Node workspaces, `tsconfig` project
references, and top-level namespaces/modules are reported as inferred. Boundary
violations require explicit request-scoped allow/deny dependency policies;
ambiguous inferred/explicit boundary names must be replaced by stable IDs.

### Location suggestions

The deterministic version ranks existing boundaries using related symbol and
role names plus internal dependency cohesion. It returns factor-level scores,
representative evidence, confidence, and weak-signal warnings. Optional
semantic ranking can be added later, but suggestions never claim there is only
one correct location. A provider-neutral opt-in interface can blend bounded
normalized semantic scores; unavailable or invalid providers preserve the
exact deterministic ordering and expose the fallback reason.

## 12. MCP surface

The MVP exposes tools because queries are model-controlled operations. A later
version may expose stable graph snapshots or project summaries as MCP resources.

All tools return a common envelope:

```json
{
    "project_id": "...",
    "snapshot_id": "...",
    "summary": "Human-readable concise answer",
    "data": {},
    "evidence": [],
    "warnings": [],
    "truncated": false
}
```

Recommended tool contracts:

| Tool                   | Key input                                                   | Purpose                                                                    |
| ---------------------- | ----------------------------------------------------------- | -------------------------------------------------------------------------- |
| `scan_project`         | `path`, `mode` (`auto`, `full`, or `incremental`)           | Build or refresh an allowed local project index.                           |
| `architecture_summary` | `project_id`, optional `scope`                              | Return modules, roles, entry points, and key dependencies.                 |
| `find_component`       | `project_id`, `name`, filters                               | Resolve and describe symbols with candidate handling.                      |
| `explain_flow`         | `project_id`, `from`, `to`, `max_depth`, `max_paths`        | Explain bounded, evidence-backed paths.                                    |
| `impact_analysis`      | `project_id`, `symbol`, `max_depth`, filters                | Return reverse dependency blast radius.                                    |
| `dependency_cycles`    | `project_id`, relationship/confidence filters, bounds       | Return bounded strongly connected dependency components and self-loops.    |
| `architecture_health`  | `project_id`, relationship/confidence filters, bounds       | Rank static hubs, structural hotspots, and uncertain dead-code candidates. |
| `check_architecture`   | `project_id`, boundary policies, confidence and size bounds | Report evidence-backed allow/deny dependency violations.                   |
| `list_boundaries`      | `project_id`                                                | Return explicit and inferred boundaries.                                   |
| `search_architecture`  | `project_id`, `query`, filters, `limit`                     | Search names, roles, attributes, and optionally semantics.                 |
| `suggest_location`     | `project_id`, `feature_description`, work/result bounds     | Rank existing boundaries with deterministic rationale and evidence.        |
| `change_impact`        | `project_id`, `symbol`, history/static bounds and filters   | Blend read-only recent Git file signals with reverse static impact.        |
| `export_diagram`       | `project_id`, format, optional boundary and filters         | Emit bounded deterministic Mermaid or PlantUML source.                     |

Each query defaults to small limits and exposes explicit caps. Large results
return continuation guidance rather than dumping the entire graph. Scan progress
should use MCP progress/logging capabilities when supported by the selected SDK
and client.

### MCP compatibility decision

Use `stdio` for the MVP and pin the protocol/SDK versions. The current MCP
protocol distinguishes tools, resources, and prompts and supports `stdio` plus
Streamable HTTP transports. SDK maturity and current protocol-version support
for the selected core runtime must be validated in a short technical spike
before selecting a package. The MCP adapter boundary permits replacing the SDK
without changing scanners, storage, or queries.

## 13. Configuration and safety

Example conceptual configuration:

```yaml
projects:
    - name: shop
      root: /work/shop
      ignore: [storage/**, public/build/**]
scan:
    max_file_bytes: 2000000
    follow_symlinks: false
queries:
    max_results: 100
    max_depth: 8
    timeout_ms: 5000
```

Safety requirements:

- Canonicalize paths and reject traversal outside an allowed root.
- Do not execute, include, autoload, transpile, or evaluate target-project code.
- Never install dependencies or invoke Composer/npm package scripts as part of a
  scan.
- Do not follow escaping symlinks.
- Bound file size, scan file count, parsing time, graph traversal depth, results,
  and serialized response size.
- Treat source text, comments, and names as untrusted data, not instructions.
- Store the database outside the target tree by default or explicitly ignore it.
- For Docker, mount scanned source read-only, keep graph state in a separate
  writable volume, and disable container networking during local scans.
- Write logs to `stderr` under `stdio`; `stdout` is reserved for protocol frames.
- Redact absolute roots from responses unless the client explicitly requests
  them; prefer project-relative paths.
- Remote HTTP transport requires a separate threat model, authentication,
  origin/host validation, TLS termination, and per-tenant isolation.

## 14. Diagnostics and observability

- Scan result counts: discovered, parsed, unchanged, deleted, skipped, and
  failed files; nodes/edges added and removed; elapsed time.
- Structured diagnostics with stable codes and source locations.
- Query timing, rows visited, truncation reason, and snapshot ID.
- Debug logs never share the MCP protocol output stream.
- A `doctor` CLI command validates the core runtime, PHP and Node scanner
  runtimes, SQLite writability, parser/compiler availability, configuration,
  worker protocol compatibility, and database schema.

## 15. Testing strategy

### Unit tests

- PHP declaration and relationship extraction, including namespaces, aliases,
  anonymous classes, traits, union/intersection types, attributes, and parse
  errors.
- Laravel extraction rules with positive and negative fixtures.
- TypeScript declaration and relationship extraction, including ESM/CommonJS
  interop, type-only imports, re-exports, overloads, declaration merging,
  generics, path aliases, project references, TSX, and compiler errors.
- Scanner worker handshake, streaming, malformed output, timeout, cancellation,
  and crash handling.
- Stable IDs, path validation, confidence rules, and query ranking.

### Integration tests

- Small labelled PHP/Laravel fixture, TypeScript fixture, and mixed monorepo
  fixture with expected nodes, edges, and evidence.
- Full scan persistence and every MCP tool contract.
- Add/change/delete/rename incremental cases.
- Failed and cancelled scan preserving the prior active snapshot.
- Ambiguous symbols, cycles, unresolved/external symbols, and bounded traversal.
- `stdio` framing test proving logs do not corrupt JSON-RPC output.

### Property and regression tests

- Incremental/full graph equivalence over generated edit sequences.
- Stable IDs across unchanged scans and project relocation.
- Golden graph snapshots for supported extraction patterns.
- Performance benchmark with a generated or approved open-source fixture.

## 16. Delivery plan and exit criteria

### Phase 0: decisions and walking skeleton

- Record ADRs for MCP SDK, stable identity, SQLite schema, and plugin contract.
- Build a CLI that starts over `stdio`, exposes a health/version tool, opens a
  migrated SQLite database, and passes an MCP inspector smoke test.
- Create labelled PHP/Laravel, TypeScript, and mixed-monorepo fixtures plus the
  benchmark harness.

Exit: end-to-end MCP request works and unresolved technical risks have owners.

### Phase 1: language-neutral graph and two scanners

- Implement generic discovery, graph builder, SQLite store, diagnostics, and the
  versioned out-of-process scanner protocol.
- Implement Composer/PSR-4 discovery and the PHP AST scanner.
- Implement Node workspace/TypeScript config discovery and the TypeScript
  compiler worker.
- Extract declarations and certain structural/reference edges from both
  languages.
- Add `scan_project`, `find_component`, and a basic
  `architecture_summary`.

Exit: accuracy targets pass on PHP, TypeScript, and mixed fixtures; all results
link to evidence; a worker failure cannot corrupt the active graph.

### Phase 2: framework intelligence and core queries

- Add Laravel classifications, routes, middleware, events/listeners, container
  bindings, and other supported framework relationships.
- Complete TypeScript call resolution and framework-neutral module/package flow.
- Implement `explain_flow`, `impact_analysis`, `list_boundaries`, and structured
  architecture search.
- Add ambiguity handling, confidence-aware ranking, and query bounds.

Exit: the example questions have useful, auditable answers on the fixture app.

### Phase 3: incremental operation and hardening

- Add hash-based change detection, per-file reconciliation, dependency
  invalidation, cancellation, locking, and atomic active snapshots.
- Complete security limits, observability, `doctor`, packaging, and client setup
  documentation.

Exit: incremental/full equivalence passes; benchmark and safety limits meet the
MVP targets.

### Phase 4: advanced analysis

- Cycles, hubs/hotspots, dead-code candidates, architecture policies, and
  violation detection.
- Deterministic feature-location suggestions, then optional semantic ranking.
- Evaluate TypeScript framework enrichers, resources, Streamable HTTP, visual
  export, Git signals, and the next language plugin independently.

## 17. Risks and mitigations

| Risk                                                                      | Mitigation                                                                                                                                     |
| ------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Dynamic dispatch and service-container behavior create false certainty.   | Conservative extraction, confidence labels, unresolved diagnostics, and no target-code execution.                                              |
| `calls` edges explode graph size and noise.                               | Store only resolved calls initially; make method-level call indexing configurable and aggregate for summaries.                                 |
| Incremental invalidation becomes more complex than full scanning.         | Own facts by file/enricher, version contributions, prove equivalence against full scans.                                                       |
| Framework conventions blur symbol kind and role.                          | Keep language kind separate from one-or-more framework classifications.                                                                        |
| MCP or its selected SDK changes couple into the core.                     | Thin adapter, pinned versions, protocol conformance tests, and SDK spike.                                                                      |
| Native language tooling creates a multi-runtime installation.             | Ship a versioned TypeScript worker, validate it with `doctor`, document runtime compatibility, and consider bundled executables after the MVP. |
| TypeScript compiler analysis can consume substantial memory.              | Respect `tsconfig` boundaries, reuse incremental programs, supervise worker memory/time, and avoid scanning dependencies by default.           |
| Generic graph abstraction adds complexity before a second backend exists. | Start with a small repository/query interface backed directly by SQLite.                                                                       |
| “Semantic” answers hallucinate business intent.                           | Keep deterministic search as baseline and require evidence for optional semantic ranking.                                                      |
| Large repositories produce oversized agent context.                       | Server-side ranking, scopes, filters, pagination/continuations, and hard response caps.                                                        |

## 18. Decisions needed before implementation

1. **Core runtime and product form:** PHP CLI with a Node TypeScript worker,
   TypeScript CLI with a PHP parser worker, or another orchestrator?
   Recommendation: preserve the source plan's PHP 8.4 core for now, but decide
   this with a walking-skeleton spike. Whichever core wins, scanned projects
   should not need to install or boot Knossos.
2. **MCP SDK:** which maintained SDK for the selected core passes required protocol, transport,
   cancellation, progress, and testability checks? Do not select solely by API
   convenience.
3. **Project registration:** allow ad hoc `scan_project(path)` calls, or require
   roots to be preconfigured? Recommendation: require an allowed-root policy,
   with an explicit opt-in for ad hoc local paths.
4. **Graph granularity:** enable method nodes and resolved call edges by default?
   Recommendation: index methods, but make high-volume call edges configurable
   after measuring graph size and query value.
5. **Support matrix:** which Laravel and TypeScript versions, module-resolution
   modes, and configuration idioms define the supported MVP surface?
6. **Boundary source:** configuration only in the MVP, or also infer from
   package manifests, project references, and top-level namespaces/directories?
   Recommendation: support all and label the source clearly.
7. **Database placement and project identity:** per-project database or a shared
   catalogue? Recommendation: one catalogue with isolated project IDs for the
   CLI, while keeping the repository interface compatible with per-project
   storage.
8. **Licensing and distribution:** source license, Composer/npm/bundled install,
   multi-runtime version compatibility, supported operating systems, and
   release/update mechanism.

## 19. Definition of MVP done

The MVP is complete when a developer can install Knossos, register a PHP,
Laravel, TypeScript, or mixed project, scan it over a local MCP connection, and
obtain bounded, source-linked answers for component lookup, architecture
summary, dependencies, flow, impact, and boundaries. Both language scanners are
first-class, tested deliverables. The graph refreshes incrementally and is
equivalent to a full rebuild; unsupported dynamic behavior is surfaced as
uncertainty or diagnostics; the server does not execute scanned code or access
paths outside its configured scope.
