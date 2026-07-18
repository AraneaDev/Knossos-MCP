# Decompose Plan

**Date:** 2026-07-18  
**Scope:** First-party runtime code in `src/`, `workers/php/src/`, `workers/typescript/src/`, and `workers/python/`; tests, fixtures, dependencies, coverage output, and generated artifacts excluded from findings.  
**Test suite status at scan time:** PASS — `tools/quality-container fast` completed with zero test, lint, static-analysis, formatting, or maintainability failures.  
**History limitation:** Hot-file history could not be measured because the mounted `.git` directory is not a usable Git repository.

## Executive Summary

- 15 verified findings: 2 critical, 6 high, 7 medium, 0 low, 0 info.
- Two product-scale failures are hidden by the green fixture suite: ordinary repeated PHP calls violate edge uniqueness, and the fixed five-second worker deadline aborts a valid self-scan.
- The largest structural risks are `ArchitectureQueryService` (2,954 lines), the CLI `Application::run()` dispatcher (462 lines, complexity 126), scan orchestration (25 dependencies), and framework logic concentrated in scanner collectors.
- Per-file LOC is not stored or exposed through MCP. Finding 15 adds the requested queryable metric with a concrete persistence and tool contract.
- No production code was modified during this scan.

## Findings

### 1. [CRITICAL] Occurrence-level edge IDs conflict with relation-level database uniqueness

**Status:** [DONE] 2026-07-18  
**Location:** `src/Reconciliation/GraphReconciler.php:284`, `src/Store/SqliteGraphRepository.php:289`, `migrations/001_initial_graph.sql:73`, `workers/php/src/FactCollector.php:368`  
**Category:** Failing Logic

**Evidence:**

- The PHP worker emits every relation occurrence. Reconciliation includes evidence location in each stable edge ID, but the database permits only one edge per `(project, kind, source, target, owner)` and `saveEdge()` handles conflicts only by `id`.
- Scanning the 62 PHP files under `src/` found 493 duplicate relation groups and 1,197 extra occurrences. `Application::run -> integer` alone occurs 54 times.
- A one-file project containing only `src/Application.php` reproduces `UNIQUE constraint failed: edges.project_id, edges.kind, edges.source_id, edges.target_id, edges.owner_key`.

```php
$evidenceKey = sprintf('%s:%d:%d:%s', $path, $start, $end, $owner);
$id = StableId::edge($projectId, $edge->kind, $sourceId, $targetId, $evidenceKey);

// Database schema:
UNIQUE (project_id, kind, source_id, target_id, owner_key)
```

**Decomposition Proposal:**

- Decide and document edge cardinality in `GraphRepository`: occurrence-level or relation-level.
- Prefer occurrence-level storage because evidence is part of the stable ID: migrate the unique key to include evidence identity (or rely on stable `id`), then update bundle/snapshot contracts.
- If relation-level storage is intended, add `EdgeOccurrenceMerger` before persistence and preserve all evidence locations explicitly rather than silently discarding them.
- Add reconciliation tests with repeated calls, constructs, and imports from one source symbol.

**Why this matters:** Ordinary PHP projects cannot complete a scan; the active graph transaction rolls back.

---

### 2. [CRITICAL] Fixed five-second request deadline aborts valid project scans

**Status:** [DONE] 2026-07-18  
**Location:** `src/Scanner/Worker/WorkerLimits.php:9`, `src/Scanner/Worker/ProcessScannerClient.php:122`, `src/Scan/ProjectScanService.php:121`  
**Category:** Failing Logic

**Evidence:**

- `WorkerLimits` defaults the whole language scan request to 5,000 ms, and production creates all three clients without overriding it.
- Project configuration exposes file count and byte limits, but no worker deadline or batching control.
- A full self-scan fails with `WORKER_TIMEOUT` after about 6.5 seconds. Running the TypeScript worker directly over the same 22 files and 8 configs succeeds in about 10.5 seconds; one large TypeScript file already takes about 2.2 seconds.
- Direct scanner tests use explicit 10–20 second limits, so the production default is not covered at representative scale.

```php
public function __construct(
    public int $requestTimeoutMs = 5_000,
    public int $maxLineBytes = 1_000_000,
    public int $maxOutputBytes = 20_000_000,
) {}

$client = new ProcessScannerClient(['node', '--max-old-space-size=512', $worker]);
```

**Decomposition Proposal:**

- Add a bounded `WorkerExecutionPolicy` shared by configuration, CLI, MCP, and `ProjectScanService`.
- Prefer chunked language scan requests with a per-chunk deadline; retain a bounded configurable ceiling for compiler-backed project setup.
- Expose effective worker limits in scan result metadata and add a production-path test whose valid workload exceeds five seconds.

**Why this matters:** The advertised large-project scan path deterministically fails on a modest real repository.

---

### 3. [HIGH] `ArchitectureQueryService` owns unrelated query domains and algorithms

**Status:** [DONE] 2026-07-18  
**Location:** `src/Query/ArchitectureQueryService.php` (class `ArchitectureQueryService`)  
**Category:** God Class

**Evidence:**

- 2,954 total lines, about 2,813 substantive lines; 20 public query operations and 33 private helpers.
- Responsibilities span project/snapshot catalog, component lookup, topology algorithms, policy evaluation, location ranking, Git-backed change impact, context assembly, diagram rendering, and search.
- It is the central CLI and MCP query dependency. No architecture document identifies this class as an intentional façade; ADR 0002 assigns that role to `ToolService`.

```php
public function __construct(
    private PDO $pdo,
    private ?Closure $clock = null,
    private ?SemanticRanker $semanticRanker = null,
    private ?GitHistoryProvider $gitHistory = null,
    private ?GitWorkingTreeProvider $gitWorkingTree = null,
) {}
```

**Decomposition Proposal:**

- Extract `ProjectCatalogQueryService` for projects, snapshots, diffs, trends, and quality metrics.
- Extract `ComponentQueryService` for find, inspect, and search.
- Extract `GraphTopologyQueryService` for summary, cycles, health, flow, and impact.
- Extract `ArchitecturePolicyQueryService`, `ChangeImpactQueryService`, `ArchitectureContextService`, and `DiagramExportService` for their named domains.
- Keep a temporary compatibility façade only while callers migrate.

**Why this matters:** Independent query changes share one 2,954-line blast radius and cannot be tested or evolved by domain.

---

### 4. [HIGH] CLI `Application::run()` is a 462-line command system

**Status:** [DONE] 2026-07-18  
**Location:** `src/Application.php:24` (class `Application`, method `run`)  
**Category:** God Class / Bloated Function

**Evidence:**

- 658 file lines; `run()` is 462 lines with cyclomatic complexity 126, the repository maximum.
- It parses and validates arguments, constructs services, manages signals, performs bundle file I/O, dispatches every CLI command, renders output, and maps exceptions to exit codes.
- `docs/MAINTAINABILITY.md` explicitly names the CLI dispatcher as a refactoring target.

```php
$command = array_shift($arguments) ?? 'help';
try {
    [$positionals, $options] = $this->parse($arguments);
    if ($command === 'version' || $command === '--version') {
        $this->output(...);
        return 0;
    }
```

**Decomposition Proposal:**

- Extract `CliCommandRouter` and handlers grouped as `ScanCommand`, `WatchCommand`, `BundleCommand`, `QueryCommand`, `MaintenanceCommand`, and `ServeCommand`.
- Extract `CliOptionParser`, `CliInputLoader`, `CliHelpRenderer`, and `CliErrorRenderer`.
- Retain `Application::run()` as bootstrap -> parse -> route -> render.

**Why this matters:** Adding or changing one command edits a high-complexity hot path used by every CLI invocation.

---

### 5. [HIGH] `ProjectScanService` combines scan planning, language execution, analysis, and presentation

**Status:** [DONE] 2026-07-18  
**Location:** `src/Scan/ProjectScanService.php` (class `ProjectScanService`)  
**Category:** God Class / Coupling Smell

**Evidence:**

- 501 total lines, about 453 substantive lines; `scan()` spans 264 lines.
- Direct dependency fanout is 25, the checked-in repository maximum.
- The PHP, TypeScript, and Python blocks repeat initialize -> partition -> replay -> scan -> cache -> metrics while the same method also handles configuration, discovery, locks, classification, boundaries, reconciliation, and response formatting.
- ADR 0001 intentionally places orchestration in core, but it does not require language planning, cache policy, analysis assembly, and presentation to remain in one class.

```php
public function scan(...): ResultEnvelope {
    $configuration = ProjectConfigurationLoader::load($root, $this->allowedRoots);
    // discovery and lock planning
    // PHP, TypeScript, and Python worker pipelines
    // classification, boundaries, reconciliation, metrics, response
}
```

**Decomposition Proposal:**

- Extract `ScanPlanner`, `ContributionCacheService`, and `LanguageScanRunner` with declarative language descriptors.
- Extract `LanguageWorkerPool` for client lifecycle and execution policy.
- Extract `ScanAnalysisPipeline` and `ScanResultFactory`.
- Keep `ProjectScanService` as the writer-lease and workflow coordinator.

**Why this matters:** Scanner-specific changes currently amplify through the central write path and have already hidden the production timeout defect.

---

### 6. [HIGH] `ProcessScannerClient` combines protocol, framing, limits, and OS supervision

**Status:** [DONE] 2026-07-18  
**Location:** `src/Scanner/Worker/ProcessScannerClient.php` (class `ProcessScannerClient`)  
**Category:** God Class

**Evidence:**

- 477 total lines, about 406 substantive lines; 22 methods.
- Responsibilities include scanner protocol validation, JSON-RPC IDs and errors, NDJSON framing, pipe polling and byte accounting, timeout/cancellation, process startup, `/proc` descendant discovery, and POSIX termination.
- ADR 0001 requires supervised isolated workers, but these are separable protocol and operating-system concerns.

```php
private string $stdoutBuffer = '';
private string $stderrBuffer = '';
private int $stdoutBytes = 0;
private int $stderrBytes = 0;
private int $nextId = 1;
private ?ScannerManifest $manifest = null;
```

**Decomposition Proposal:**

- Extract `WorkerProcessSupervisor` for process/pipes/descendants/signals.
- Extract `NdjsonRpcChannel` for framing, buffers, and stream caps.
- Extract `ScannerProtocolSession` for request IDs, manifest/capability validation, contributions, and RPC errors.
- Preserve `ProcessScannerClient` as the public `ScannerClient` adapter.

**Why this matters:** Resource policy and protocol behavior are tightly entangled in the client used by every language scan.

---

### 7. [HIGH] TypeScript `FactCollector` mixes core compiler facts with four framework enrichers

**Status:** [DONE] 2026-07-18  
**Location:** `workers/typescript/src/scanner.js:156` (class `FactCollector`)  
**Category:** God Class

**Evidence:**

- The collector spans 679 lines, about 651 substantive lines, with 23 methods/accessors.
- It handles declarations/containment, modules/imports/exports, calls/types/symbol resolution, React/Next/Vue/state/HTTP enrichment, NestJS enrichment, and fact identity/deduplication.
- It is instantiated for every compiler program. Documentation supports one compiler traversal, not one implementation class.

```js
class FactCollector {
    constructor(root, sourceFile, checker) {
        this.nodesById = new Map();
        this.edgesByKey = new Map();
        this.container = [];
        this.nestImports = new Map();
    }
}
```

**Decomposition Proposal:**

- Extract `FactAccumulator`, `TypeScriptLanguageFactCollector`, `TypeScriptApplicationEnricher`, and `NestJsFactEnricher`.
- Keep one visitor coordinator and one compiler traversal; enrichers receive shared compiler context and the accumulator.
- Move `declaration`, `callExpression`, application-role, and Nest-specific subflows into the matching component.

**Why this matters:** Generic compiler extraction and fast-changing framework conventions currently share one large, high-complexity edit surface.

---

### 8. [HIGH] `LaravelFactCollector` combines unrelated Laravel feature domains

**Status:** [DONE] 2026-07-18  
**Location:** `workers/php/src/LaravelFactCollector.php` (class `LaravelFactCollector`)  
**Category:** God Class

**Evidence:**

- 434 total lines, about 384 substantive lines, and 28 methods.
- Separate domains include route/group state, container bindings, event/job/observer dispatch, and provider listener/policy maps.
- Non-route handlers do not need most of the mutable route-group state. `docs/LARAVEL-SUPPORT.md` describes these as distinct supported areas.

```php
private function route(Expr\MethodCall|Expr\StaticCall $node): void
{
    $descriptor = $this->routeDescriptor($node);
    if ($descriptor === null) {
        return;
    }
    [$method, $args, $modifiers, $evidence] = $descriptor;
}
```

**Decomposition Proposal:**

- Extract `LaravelRouteFactCollector`, `LaravelContainerFactCollector`, `LaravelDispatchFactCollector`, and `LaravelProviderMapFactCollector`.
- Retain a thin visitor coordinator that forwards nodes and merges facts during one parser traversal.
- Address Finding 11 inside the route-specific extraction, after structure is isolated or in the same approved change.

**Why this matters:** Unrelated Laravel features share state and regression scope; a route bug is currently buried inside the same collector.

---

### 9. [MEDIUM] Python `Collector` combines core AST extraction with FastAPI, Django, and Celery

**Status:** [DONE] 2026-07-18  
**Location:** `workers/python/bin/worker.py:99` (class `Collector`)  
**Category:** God Class

**Evidence:**

- The collector spans 355 lines, about 329 substantive lines, with 27 methods.
- It owns generic imports/classes/functions/calls plus FastAPI routes/dependencies/middleware, Django models/views/settings/URLs, Celery roles, and fact accumulation.
- It is reachable for every valid Python file.

```python
class Collector(ast.NodeVisitor):
    def __init__(self, relative, tree, declarations):
        self.nodes = {}
        self.edges = {}
        self.aliases = {}
        self.containers = []
        self.framework_objects = {}
```

**Decomposition Proposal:**

- Extract `PythonFactAccumulator`, `PythonAstFactCollector`, `FastApiFactEnricher`, `DjangoFactEnricher`, and `PythonFrameworkRoleEnricher`.
- Retain one `ast.NodeVisitor` coordinator and pass nodes to the relevant enrichers.

**Why this matters:** Core language correctness and framework heuristics cannot currently evolve or be tested independently.

---

### 10. [MEDIUM] Bundle import combines archive validation and per-table persistence

**Status:** [DONE] 2026-07-18  
**Location:** `src/Bundle/GraphBundleService.php:95` (method `import`)  
**Category:** Bloated Function

**Evidence:**

- 113 lines with complexity 98.
- The method decompresses, validates schema/checksum/limits, counts facts, maps stable IDs, validates and inserts every table, repairs parents, owns the transaction, and formats the result.
- The bundle boundary itself is cohesive, but decoding/validation and database application are clean extraction seams.

```php
$json = @gzdecode($compressed, self::MAX_UNCOMPRESSED_BYTES);
$bundle = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
$manifest = $this->object($bundle['manifest'], 'manifest');
$payload = $this->object($bundle['payload'], 'payload');
$this->knownKeys($manifest, [...], 'manifest');
$this->knownKeys($payload, [...], 'payload');
```

**Decomposition Proposal:**

- Extract `GraphBundleDecoder::decodeAndValidate`, `BundleIdMapBuilder`, and `PortableGraphImporter`.
- Give the importer named per-table methods while keeping one outer transaction in `GraphBundleService`.

**Why this matters:** A highly complex security-sensitive validator and mutation pipeline is difficult to test by phase.

---

### 11. [MEDIUM] Laravel `Route::match()` parsing returns before match-specific indexing

**Status:** [DONE] 2026-07-18  
**Location:** `workers/php/src/LaravelFactCollector.php:99` (method `route`)  
**Category:** Failing Logic

**Evidence:**

- Generic URI parsing reads argument 0 before the `match` branch; for `Route::match`, argument 0 is the method array, so the method returns immediately.
- Trigger: `Route::match(['GET', 'POST'], '/checkout', Handler::class)`.
- No current fixture covers `Route::match()`.

```php
$uri = $this->string($args[0]->value ?? null);
if ($uri === null) {
    $this->diagnostic('LARAVEL_DYNAMIC_ROUTE_URI', ...);
    return;
}
if ($method === 'match') {
    $uri = $this->string($args[1]->value ?? null);
}
```

**Decomposition Proposal:**

- In `LaravelRouteFactCollector`, normalize each route method into a `LaravelRouteDescriptor` before validating URI/action.
- Parse `match` as methods at index 0, URI at 1, action at 2; add literal and dynamic fixtures.

**Why this matters:** Valid Laravel routes disappear from the architecture graph and receive a misleading diagnostic.

---

### 12. [MEDIUM] Same-named nested Python functions silently merge

**Status:** [DONE] 2026-07-18  
**Location:** `workers/python/bin/worker.py:255` (method `Collector.function`)  
**Category:** Failing Logic

**Evidence:**

- Class methods are container-qualified, but all non-method functions use only `module + node.name`, even when nested in another function.
- `add_node()` uses `setdefault`, so the first same-named nested function keeps its evidence while later definitions silently point at it.
- Existing fixtures contain no nested definitions.

```python
if self.containers and self.containers[-1][2] == "class":
    kind, canonical = "method", f"{parent_canonical}::{node.name}"
else:
    parent_id, kind, canonical = self.current(), "function", f"{self.module}.{node.name}"
local_id = ref(kind, canonical)
```

**Decomposition Proposal:**

- Move canonical-name construction into `PythonSymbolIdentity`.
- Qualify local functions by the complete callable-container path, or emit an explicit local-function kind with stable lexical identity.
- Add two outer functions containing same-named inner helpers and assert distinct nodes/evidence.

**Why this matters:** The graph asserts a false shared function and loses source evidence without a diagnostic.

---

### 13. [MEDIUM] HTTP session capacity and lifecycle transitions are non-atomic

**Status:** [DONE] 2026-07-18  
**Location:** `src/Mcp/HttpSessionStore.php:14`, `src/Mcp/HttpEndpoint.php:99`  
**Category:** Failing Logic / Coupling Smell

**Evidence:**

- Capacity enforcement performs expiry deletion, count, and insert as separate autocommit statements.
- Initialization performs `exists()` and `markInitialized()` separately; expiry or concurrent deletion between them raises an uncaught runtime exception rather than a protocol 404/409.
- HTTP tests are sequential, while the threat model allows deployment behind a controlled multi-worker runtime and claims sessions are capacity-limited.

```php
$this->pdo->prepare('DELETE FROM http_sessions ...')->execute(...);
$count = (int) $this->pdo->query('SELECT COUNT(*) FROM http_sessions')->fetchColumn();
if ($count >= $this->maxSessions) {
    throw new RuntimeException(...);
}
$statement->execute([...]);
```

**Decomposition Proposal:**

- Add `HttpSessionRepository::claim()` using one transaction/atomic capacity check and insert.
- Replace exists-then-update with one conditional state transition returning a typed outcome.
- Map expired/missing/capacity/busy outcomes explicitly in `HttpEndpoint`; add two-connection concurrency tests.

**Why this matters:** Concurrent requests can exceed the configured capacity or escape the documented HTTP error contract.

---

### 14. [MEDIUM] Transient rescan failures terminate watch mode and drop pending work

**Status:** [DONE] 2026-07-18  
**Location:** `src/Watch/WatchService.php:91` (method `run`)  
**Category:** Failing Logic

**Evidence:**

- Fingerprint errors are caught and emitted, but the subsequent scan attempt has no error boundary.
- A worker timeout, disappearing file, or transient storage failure after `scan_started` exits the watcher before `error` or `stopped`, and pending changes are lost.
- `docs/WATCH-MODE.md` documents `error` and `stopped` lifecycle events; tests cover only successful scans, overflow, and cancellation.

```php
$emit(['event' => 'scan_started', 'mode' => $mode, 'changes' => count($pending)]);
$last = $this->scanner->scan($root, mode: $mode, cancellation: $cancellation);
++$scans;
$emit(['event' => 'scan_completed', ...]);
$pending = [];
```

**Decomposition Proposal:**

- Extract `WatchScanAttempt` with typed retryable, cancelled, and terminal outcomes.
- On retryable failure, emit `error`, retain/coalesce pending paths, and retry with bounded backoff; always emit `stopped` on termination.
- Add worker-timeout and storage-failure watch tests.

**Why this matters:** The long-running mode is least resilient precisely when scanners or storage are transiently unhealthy.

---

### 15. [MEDIUM] Per-file LOC is neither persisted nor queryable through MCP

**Status:** [DONE] 2026-07-18  
**Location:** `src/Discovery/DiscoveredFile.php`, `migrations/001_initial_graph.sql:31`, `src/Query/ArchitectureQueryService.php:556`, `src/Mcp/ToolService.php`  
**Category:** Data / Contract Gap

**Evidence:**

- `DiscoveredFile` carries path, language, byte size, mtime, and hash but no line count.
- The `files` table persists byte `size` only. `architectureSummary()` groups files by language, and no MCP definition exposes per-file metrics.
- The quality-only artifact `coverage/quality/maintainability.json` has LOC for selected first-party files, but it is not project graph data and cannot be queried for scanned projects.

```sql
CREATE TABLE files (
    relative_path TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    size INTEGER NOT NULL,
    mtime INTEGER NOT NULL,
    language TEXT NOT NULL
);
```

**Decomposition Proposal:**

- Add a migration for non-negative `line_count` on `files`; include it in snapshots and portable bundles.
- Extend discovery with a bounded single-pass `FileFingerprint` that computes SHA-256 and physical LOC without executing project code.
- Add `FileMetricsQueryService` with path/language filters, `sort_by` (`path` or `line_count`), order, limit, and offset.
- Expose it as read-only MCP tool `file_metrics`; include exact path, language, byte size, LOC, and snapshot identity. Add the equivalent CLI command to preserve the documented CLI/MCP parity.
- Test empty files, final lines with and without a newline, CRLF, large bounded files, pagination, filtering, snapshots, and bundles.

**Why this matters:** File size is already collected, but the requested structural metric cannot be inspected or ranked through the product API.

## Dismissed Findings (False Positives or Subsumed Candidates)

| Candidate                                               | Why Dismissed                                                                                                                                  | Evidence                                                             |
| ------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| `src/Mcp/ToolService.php` as a god class                | Intentional transport-neutral façade and authoritative schema registry                                                                         | ADR 0002; generated `docs/MCP-REFERENCE.md`                          |
| `src/Reconciliation/GraphReconciler.php` as a god class | One atomic normalization/persistence pipeline; only the edge-cardinality defect survives as Finding 1                                          | One public `reconcile()` workflow                                    |
| `src/Store/SqliteGraphRepository.php` as a god class    | Cohesive implementation of one `GraphRepository` persistence contract                                                                          | `src/Store/GraphRepository.php`                                      |
| Generic PHP `FactCollector`                             | Large visitor, but methods share generic PHP AST state and one extraction domain                                                               | 440 total / about 372 substantive lines                              |
| `SymfonyFactCollector`                                  | Under 300 substantive lines and cohesive Symfony enrichment                                                                                    | 309 total / about 259 substantive lines                              |
| `ProjectDiscoverer`                                     | Cohesive bounded traversal; extraction could obscure safety invariants                                                                         | 330 total / about 285 substantive lines                              |
| `ProjectConfigurationLoader::load()`                    | High complexity, but one ordered schema-validation pipeline with no distinct runtime domain                                                    | 86 lines; configuration DTO output                                   |
| Long topology/query methods                             | `dependencyCycles`, `architectureHealth`, `checkArchitecture`, and `suggestLocation` are real extraction seams but duplicate broader Finding 3 | All reside in `ArchitectureQueryService`                             |
| Long TypeScript collector methods                       | `declaration`, `callExpression`, application-role, and Nest methods duplicate broader Finding 7                                                | All reside in `FactCollector`                                        |
| `HttpEndpoint::handle()` and `StdioServer::handle()`    | Intentional protocol adapter boundaries; no inconsistent response shape found                                                                  | ADR 0002 and HTTP threat model                                       |
| `WatchService::run()` as merely bloated                 | Poll/debounce state is cohesive; only its missing scan error boundary survives as Finding 14                                                   | `docs/WATCH-MODE.md`                                                 |
| Writer-lease leak after scan failure                    | `ProjectWriterLease::__destruct()` releases the lease; both reproduction databases had zero lock rows                                          | `src/Scan/ProjectWriterLease.php:15`                                 |
| Circular imports, ambient globals, layer violations     | No harmful instance was confirmed in first-party runtime code                                                                                  | Manual dependency/import scan; self-scan blocked by Findings 1 and 2 |

## Summary Table

|   # | Severity | Location                              | Category                     | Status          |
| --: | -------- | ------------------------------------- | ---------------------------- | --------------- |
|   1 | CRITICAL | `GraphReconciler` / edge schema       | Failing Logic                | DONE 2026-07-18 |
|   2 | CRITICAL | `WorkerLimits` / `ProjectScanService` | Failing Logic                | DONE 2026-07-18 |
|   3 | HIGH     | `ArchitectureQueryService.php`        | God Class                    | DONE 2026-07-18 |
|   4 | HIGH     | `Application.php`                     | God Class / Bloated Function | DONE 2026-07-18 |
|   5 | HIGH     | `ProjectScanService.php`              | God Class / Coupling         | DONE 2026-07-18 |
|   6 | HIGH     | `ProcessScannerClient.php`            | God Class                    | DONE 2026-07-18 |
|   7 | HIGH     | `workers/typescript/src/scanner.js`   | God Class                    | DONE 2026-07-18 |
|   8 | HIGH     | `LaravelFactCollector.php`            | God Class                    | DONE 2026-07-18 |
|   9 | MEDIUM   | `workers/python/bin/worker.py`        | God Class                    | DONE 2026-07-18 |
|  10 | MEDIUM   | `GraphBundleService::import()`        | Bloated Function             | DONE 2026-07-18 |
|  11 | MEDIUM   | `LaravelFactCollector::route()`       | Failing Logic                | DONE 2026-07-18 |
|  12 | MEDIUM   | `Collector.function()`                | Failing Logic                | DONE 2026-07-18 |
|  13 | MEDIUM   | `HttpSessionStore.php`                | Failing Logic / Coupling     | DONE 2026-07-18 |
|  14 | MEDIUM   | `WatchService::run()`                 | Failing Logic                | DONE 2026-07-18 |
|  15 | MEDIUM   | File persistence and MCP              | Data / Contract Gap          | DONE 2026-07-18 |
