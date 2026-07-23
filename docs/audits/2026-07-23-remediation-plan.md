# Remediation Plan — 2026-07-23 Comprehensive Audit

Addresses the ~95 findings in `2026-07-23-comprehensive-logic-audit.md`. Work is split into **9 file-disjoint batches** so up to 5 agents can run concurrently without editing the same file. Each batch agent works test-first (project TDD norm), runs a targeted `phpunit --filter` (or vitest/pytest) for its area, and returns a summary + patch. The main loop applies patches sequentially, runs the full `composer check` + workers checks, and commits **one commit per batch** on `fix/self-test-findings` (or a fresh `fix/audit-remediation`).

## Execution model

- **Orchestration:** `Workflow`, capped at **5 concurrent agents**.
- **Wave 1 (5 agents):** Batches 1–5 — the core `src/` surface, highest value.
- **Wave 2 (4 agents):** Batches 6–9 — workers, CLI/runtime, bundle, tests/CI.
- Between waves the main loop verifies (`composer check`, phpstan, cs-fixer, vitest) and commits.
- No git worktrees: `vendor/`/`node_modules/` are untracked, so a fresh worktree can't run the suite. Batches are file-disjoint instead, and each agent runs only its own narrow test filter (project tests use per-test temp SQLite, so concurrent narrow runs are safe).

## Cross-batch contract (cancellation, spans 3 batches by file)

One logical finding — "user cancellation misclassified as worker failure" — is split by file so no file is shared:
- **Batch 4** edits `LanguageScanRunner` catch → translate `WorkerException('WORKER_CANCELLED')` to `ScanCancelledException`.
- **Batch 3** edits `StdioServer` → return no response for a cancelled request; `ToolService::refreshIfStale` rethrows `ScanCancelledException`.
- **Batch 5** edits `ScannerProtocolSession` → keep the `request_id` type end-to-end.

Each agent touches only its own files; the shared contract is stated in every prompt.

---

## Batches

### Batch 1 — Persistence performance & transactions  *(foundational)*
Files: `migrations/011_add_missing_indexes.sql` (new), `src/Store/SqliteGraphRepository.php`, `SqliteConnection.php`, `MigrationRunner.php`
- **H** Add `nodes(parent_id)`, `nodes(file_id)`, `classifications(node_id)`, `boundary_memberships(node_id)` indexes (the 30 s→0.23 s fix).
- **M** `archiveActiveSnapshot`: `COUNT(*)` guard before fetch; move JSON encode outside the write txn; skip on unchanged graph.
- **M** `transaction()` → `BEGIN IMMEDIATE` for write paths.
- **L** Nested `transaction()` → SAVEPOINT (or document the hazard).
- **L** No-transaction migration bookkeeping recorded inside the migration's own txn.

### Batch 2 — Query layer
Files: `src/Query/GraphTopologyQueryService.php`, `AbstractArchitectureQueryService.php`, `ComponentQueryService.php`, `ProjectCatalogQueryService.php`, `ChangeImpactQueryService.php`, `ArchitectureContextService.php`, `StalenessProbe.php`, `ArchitecturePolicyQueryService.php`, `ArchitectureQueryService.php`
- **H** `quality_gate` fails/indeterminate when `checkArchitecture` truncated (or exact count past limit).
- **H** `changedFilesImpact` shared deadline; batch per-dependant `roles()`/`node()`.
- **M** Kosaraju: discard components on pass-1 timeout.
- **M** `impactEdges`/`flowEdges` `LIMIT 501` + `per_node_edge_limit` truncation signal.
- **M** Per-cycle truncation → envelope flag; collect cycle membership pre-slice.
- **M** `snapshot_diff`/`architecture_trends` table-by-table diff, hash compare.
- **L** ×7: `inheritedMethodContext` transitive; `limit+1` collectors; `explainFlow` reason list; search `, n.id` tiebreaker; `path_confidence` per-distance max; quality-gate metric parity; context truncation tracked structurally; staleness `unverified` state; `explainFlow` self-flow.

### Batch 3 — MCP transport & tooling
Files: `src/Mcp/ToolService.php`, `StdioServer.php`, `HttpEndpoint.php`, `HttpSessionStore.php`, `PromptService.php`, `ResultEnricher.php`, `ResourceService.php`, `bin/http-router.php`
- **M** HTTP `handle()` wrap in try/catch → `-32603`; router backstop.
- **M** Bounded stdio buffering in `nextLine()`/`pollCancellation()`.
- **M** Session-exhaustion guard (require token or loopback; evict oldest-uninitialized).
- **M** Router wires `gitWorkingTree:` (mirror `ServeCommand`).
- **M** `review_diff` prompt → `working_tree: true`; reorder `base_ref` checks; schema note.
- **L** ×10: unknown-tool/invalid-arg → `-32602`; `refresh_if_stale` gated + post-validation; cancellation response suppression; `max_chars` evidence/1-element; `scan_project` schema defaults; empty `budgets {}`; generic error messages; `initialize`-notification 202; sliding session expiry; distinct lifecycle error code; `JSON_INVALID_UTF8_SUBSTITUTE` at StdioServer encode.

### Batch 4 — Scan orchestration & discovery
Files: `src/Scan/ProjectScanService.php`, `ProjectWriterLock.php`, `ProjectWriterLease.php`, `LanguageWorkerPool.php`, `LanguageScanRunner.php`, `src/Discovery/ProjectDiscoverer.php`, `IgnoreMatcher.php`, `src/Scan/ContributionCacheService.php`
- **H** No-change fast path includes explicit `boundaries`/`name` in its config hash (or bypasses fast path when they change).
- **M** Discovery per-entry try/catch → `DISCOVERY_FILE_UNREADABLE` + continue.
- **M** TOCTOU cache: worker returns hash of parsed bytes; rescan on mismatch.
- **M** `IgnoreMatcher` gitignore semantics (or documented + reject unsupported constructs).
- **M** Thread `CancellationToken` into discovery.
- **M** SQLITE_BUSY → `ScanBusyException`.
- **M** Writer-lease renewal before reconcile + steal detection.
- **M** `LanguageScanRunner`: cancelled `WorkerException` → `ScanCancelledException`.
- **L** ×3: lease `try/finally`; dead-worker liveness probe + respawn; case-normalized project identity.

### Batch 5 — Worker channel, supervision & watch
Files: `src/Scanner/Worker/WorkerProcessSupervisor.php`, `NdjsonRpcChannel.php`, `ScannerProtocolSession.php`, `src/Watch/WatchService.php`, `WatchScanAttempt.php`
- **M** `send()` select-driven, deadline-honoring, drains stdout/stderr (deadlock fix).
- **M** Chunk the file list below the frame limit (or side-channel it).
- **M** Crash mid-line → `WORKER_EXITED` immediately; no busy-spin.
- **M** Kill by process group; re-enumerate before each signal pass; non-Linux support.
- **L** ×7: minimal worker env; watch fingerprint before initial scan; fingerprint `(path,size,mtime)` cache; retry classification by diagnostic code; `request_id` type end-to-end; generator `try/finally` drain; verify PID identity before SIGKILL.

### Batch 6 — Language workers (PHP / TS / Python)
Files: `workers/php/src/*`, `workers/php/bin/worker`, `workers/typescript/src/*`, `workers/typescript/bin/worker.js`, `workers/python/bin/worker.py`
- **H** PHP `bin/worker` ini hardening (`display_errors=stderr`, `memory_limit`) + AST depth pre-check.
- **H** PHP `write()` `JSON_INVALID_UTF8_SUBSTITUTE`.
- **H** TS unify declaration vs reference canonicalization.
- **M** ×4: Python parse bytes (BOM/PEP263); src-layout source-root stripping; batch-independent import targets; TS+Python per-file AST memory release; per-file error isolation ×3.
- **L** ×6: TS anon `:character`; PHP var-type invalidation + `probable`; TS edge-attr merge; TS `readdirSync` try/catch; Python `mod`/`__init__` collision; PHP `unset($request)` per loop; Laravel anon id scheme.

### Batch 7 — CLI, runtime, config, boundary, git
Files: `src/Cli/CliOptionParser.php`, `CliCommandRouter.php`, `CliCommandContext.php`, `Command/QueryCommand.php`, `Command/MaintenanceCommand.php`, `CliHelpRenderer.php`, `src/Runtime/RuntimeFactory.php`, `DoctorService.php`, `src/Discovery/RootGuard.php`, `src/Configuration/ProjectConfigurationLoader.php`, `src/Boundary/BoundaryInference.php`, `src/Classification/TestModuleRule.php`, `src/Git/ProcessGitHistoryProvider.php`, `ProcessGitWorkingTreeProvider.php`
- **M** Anchor explicit `namespace_prefix` with `\`.
- **M** `git log` `-c core.quotePath=false` (or `-z`).
- **M** `JSON_INVALID_UTF8_SUBSTITUTE` at `CliCommandContext::output`.
- **M** Doctor PHP-version lower bound; Doctor probe deadline/select (no pipe deadlock).
- **L** ×6: skip stale allow-roots; CLI option allowlist + `--flag=false` + `--` handling; route-before-DB-open + `getcwd()===false` guard; `check-architecture` exit 1 on violations; `TestModuleRule` segment anchoring; reject duplicate/both-matcher boundaries.

### Batch 8 — Bundle & maintenance data-integrity
Files: `src/Bundle/PortableGraphImporter.php`, `GraphBundleDecoder.php`, `GraphBundleService.php`, `BundleIdMapBuilder.php`, `src/Reconciliation/GraphReconciler.php`, `src/Maintenance/DatabaseMaintenanceService.php`, `src/Store/StableId.php`
- **M** Bundle decode: lower uncompressed cap + token-density bound before `json_decode`.
- **M** Validate bundle `start_line`/`end_line` as nullable positive ints; timestamps regex; hashes hex-shape.
- **L** ×4: export read transaction; `parent_id` acyclicity check; duplicate-import TOCTOU inside `BEGIN IMMEDIATE`; StableId re-declaration warning + drop the unreachable throw.
- `cleanupStaleScans` left untouched here — its fix is cross-cutting; see Batch 10.

### Batch 9 — Tests, CI & docs
Files: `Dockerfile`, `infection.json5`, `.github/workflows/mutation.yml`, `composer.json`, `phpunit.xml`, `README.md`, `tools/pcov-report.php`, `tools/quality`, `tests/phpunit/Mcp/FaultInjectionTest.php`, new `tests/python/…`, new HTTP-router integration test
- **H** Non-root user in the quality stage so permission-error tests run.
- **M** Add pytest suite for `worker.py` + wire into `tools/quality`.
- **M** Boot `php -S … bin/http-router.php` integration test (401/403/200); `pcov-report.php` treats unexecuted files as 0%.
- **M** Rename `composer check` → `check:quick` (or delegate to `tools/quality fast`).
- **M** Revert mutation floor to the measured **53** and add a **weekly `schedule:`** to `mutation.yml` (re-measure and ratchet honestly over time).
- **L** ×3: collapse/rename phpunit suites; README badges → workflow status; `FaultInjectionTest` wall-clock bound.

### Batch 10 — Persist failed/cancelled scans  *(sequential, run solo after Waves 1–2)*
Files: `src/Scan/ProjectScanService.php`, `src/Store/SqliteGraphRepository.php`, `src/Reconciliation/GraphReconciler.php`, `src/Maintenance/DatabaseMaintenanceService.php`
Cross-cutting because it changes the reconcile transaction boundary (commit the scan row before reconciliation; mark it failed/cancelled in a catch), which makes `cleanupStaleScans` load-bearing. Done by the main loop test-first, after Batches 1/4/8 have committed, so it builds on their final shape rather than racing them.

---

## Decisions (resolved 2026-07-23)

- **Incremental reconciliation rework:** **deferred** to its own tracked effort. Batch 1 ships the acute relief (indexes + snapshot memory + `BEGIN IMMEDIATE`). `deleteFactsByOwner` stays in place with a warning note, not wired up.
- **Mutation floor:** **revert to 53** + add a weekly schedule (Batch 9).
- **`cleanupStaleScans`:** **persist failed/cancelled scans** so the tool becomes meaningful (Batch 10, sequential).
