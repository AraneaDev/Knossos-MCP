# Comprehensive Logic Audit — Knossos-MCP

**Date:** 2026-07-23
**Branch:** `fix/self-test-findings`
**Method:** Eight parallel subsystem audits (architect + QA + security + performance lenses), every finding traced to the source and the load-bearing claims re-verified independently — including empirical reproduction of the reconciliation slowdown and the SQLite CHECK-constraint bypass.
**Scope:** All of `src/` (110 PHP files), the three language workers (`workers/php`, `workers/typescript`, `workers/python`), `bin/`, migrations, tests, CI, and docs. `vendor/`, `node_modules/`, and `.knossos/` excluded.

Knossos-MCP is a Model Context Protocol server plus CLI that scans project source trees into a SQLite architecture graph (incrementally), then answers structural queries over it (hubs, cycles, impact, boundaries, quality gates). It spawns per-language scanner subprocesses and speaks NDJSON-RPC to them.

**Headline:** The code is well-engineered and defends its classic attack surfaces well (path traversal, git-arg injection, SQL injection, bearer auth, bundle ID spoofing are all solid). No data-corruption or RCE bug was found. The real damage is concentrated in two places: a **performance cliff in reconciliation** (a dropped index makes every scan quadratic — the empirically-measured cause of the 30 s stage) and a cluster of **silent-wrong-result bugs** where a CI quality gate, an impact analysis, or a boundary set reports success while under-counting or serving stale data. The worst failures are the ones that look green.

---

## Critical

None.

---

## High

## Issue: Dropped foreign-key index makes graph teardown O(N²) — the measured cause of the 30 s reconciliation stage

### Severity
High

### Location
`migrations/010_language_scoped_node_uniqueness.sql:72-75` (index recreation); `migrations/001_initial_graph.sql:71` (original `nodes_file_idx`); `src/Store/SqliteGraphRepository.php:173-179` `clearProjectGraph`

### Description
`nodes.parent_id REFERENCES nodes(id) ON DELETE SET NULL` has never had a supporting index. Migration 001 at least created `nodes_file_idx`, but migration 010 rebuilt the `nodes` table and recreated only `nodes_project_canonical_idx`, `_display_idx`, `_kind_idx`, `_owner_idx` — silently dropping `nodes_file_idx` and still never adding a `parent_id` index. With `PRAGMA foreign_keys = ON` (`SqliteConnection.php:34`), every row deleted from `nodes` forces SQLite to run the `SET NULL` action, which without a `parent_id` index is a full scan of `nodes` per deleted row.

### Why it matters
Every scan — full or incremental — runs `clearProjectGraph` inside the write transaction (`GraphReconciler.php:71`), so the whole graph is torn down quadratically on each scan, holding the SQLite write lock for tens of seconds and starving concurrent writers into `busy_timeout` failures. Cost scales with the square of node count.

### Evidence
Reproduced against the real migrated schema: `DELETE FROM nodes WHERE project_id=…` with 30,000 nodes took **27.2 s**; after adding indexes on `parent_id` and `file_id`, the identical delete took **0.23 s** — matching the ~30 s reconciliation stage observed on an 18-file incremental scan. Confirmed in-repo: `nodes_file_idx` exists in 001 line 71, absent from 010's recreation block (lines 72-75).

### Recommended Fix
New migration: `CREATE INDEX nodes_parent_idx ON nodes(parent_id); CREATE INDEX nodes_file_idx ON nodes(file_id);` plus single-column indexes on `classifications(node_id)` and `boundary_memberships(node_id)` (the existing `(project_id, node_id)` composites can't serve a bare `node_id` FK probe). Add a migration-review rule that any table-rebuild recreates every pre-existing index.

### Confidence
High

---

## Issue: "Incremental" reconciliation rewrites the entire graph regardless of delta size

### Severity
High

### Location
`src/Reconciliation/GraphReconciler.php:46-116` `reconcile`; `src/Store/SqliteGraphRepository.php:173-179, 445-469`

### Description
`reconcile()` handles `mode === 'incremental'` identically to a full scan: `archiveActiveSnapshot` (dumps the whole previous graph), `clearProjectGraph` (deletes all seven fact tables), rewrites every file row, batch-reinserts all nodes/edges, then loops row-by-row over every classification, boundary membership, and diagnostic, and `replaceContributionCache` deletes-and-reinserts one row per (scanner, file) for the whole project — all in one write transaction. The only shortcut is `noChangeFastPath`, which requires *zero* changed files. `deleteFactsByOwner`, the primitive for targeted incremental deletion, has no callers (dead code).

### Why it matters
An 18-file change on a large project rewrites hundreds of thousands of rows, re-JSON-encodes every attribute payload and cache entry, and holds the global write lock the whole time. Even with the index fix above, reconciliation stays linear in project size rather than delta size — so incremental scans never actually get the incremental win they advertise.

### Evidence
`reconcile()` branches on mode only to pass it through; the teardown/rebuild path is unconditional. `deleteFactsByOwner` grep-verified to have zero callers outside the interface.

### Recommended Fix
Implement true incremental reconciliation: diff owner keys/file sets, call `deleteFactsByOwner` (extended to classifications/boundary memberships and made cascade-safe — see Low finding) for removed/changed owners, upsert only changed owners' facts, and update `contribution_cache` per changed row via `INSERT … ON CONFLICT`. Skip or asynchronously perform `archiveActiveSnapshot` when the delta is near-empty.

### Confidence
High

---

## Issue: `quality_gate` boundary_violations budget silently passes above 100 real violations

### Severity
High

### Location
`src/Query/ProjectCatalogQueryService.php:271-274` `qualityGate`; `src/Query/ArchitecturePolicyQueryService.php:150-154` `checkArchitecture`

### Description
`qualityGate` computes `boundary_violations` as `count($policyResult->data['violations'])` from a `checkArchitecture(..., limit: 100)` call, but never inspects `$policyResult->truncated`. `checkArchitecture` stops collecting at `count >= limit` and sets `truncated`; it is further bounded by `maxEdges = 20_000` and `timeoutMs = 1000`, both of which silently undercount on large graphs. Budgets are validated up to 100,000.

### Why it matters
With 5,000 real violations and a budget of 150, `actual` reports 100 and the gate **passes**. Any `boundary_violations` budget ≥ 100 is dead, and edge/time truncation shrinks the count further. A CI quality gate that silently passes on the worst regressions is the exact failure mode a gate exists to prevent.

### Evidence
`qualityGate` reads `count(...->data['violations'])` with `limit: 100` hardcoded and no `truncated` check (verified in source).

### Recommended Fix
Fail (or mark the check `indeterminate`) when `$policyResult->truncated` is true; better, have `checkArchitecture` return an exact `violation_count` that keeps counting past the collection limit and compare the budget against that.

### Confidence
High

---

## Issue: `changed_files_impact` runs an unbounded fan-out of per-component impact analyses with no shared deadline

### Severity
High

### Location
`src/Query/ChangeImpactQueryService.php:162-177` `changedFilesImpact`; `src/Query/GraphTopologyQueryService.php:560-596` `impactAnalysis`

### Description
`changedFilesImpact` loops over up to 1,000 direct components and calls the full `impactAnalysis` for each, and every call establishes its **own fresh** `deadline = now() + timeoutMs*1e6` (`timeoutMs` user-settable up to 5,000). Each `impactAnalysis` issues one edge query per visited node plus a `node()` and `roles()` query per accepted dependant (~3× limit queries per call). `architectureContext` inherits this via its `changedFilesImpact` call.

### Why it matters
Worst case ≈ 1,000 × 5 s = ~83 minutes of wall time and ~300,000 SQLite queries from a single MCP tool call, with no overall deadline and no way for `timeoutMs` to bound the request. A single tool call can hang the server.

### Evidence
`foreach (array_slice($direct, 0, 1000) as $node) { $impact = $this->topologyQueries->impactAnalysis(..., $timeoutMs); }` with a per-call deadline (verified in source; `impactAnalysis` recomputes `$deadline` on entry).

### Recommended Fix
Compute one shared deadline for the whole request and pass the remaining time into each `impactAnalysis` (as `architectureHealth` already does at `GraphTopologyQueryService.php:272`); batch the per-dependant `roles()`/`node()` lookups after the BFS.

### Confidence
High

---

## Issue: No-change fast path ignores explicit `boundaries`/`name` arguments, serving stale boundary data

### Severity
High

### Location
`src/Scan/ProjectScanService.php:87-93, 118-126, 137-164`; `src/Mcp/ToolService.php:813`

### Description
The `scan_project` tool accepts a `boundaries` argument passed straight into `ProjectScanService::scan(...)`. The no-change fast path triggers on an incremental scan with zero added/changed/deleted files, comparing only `input_hash`, `configuration_hash`, `snapshot_retention`, `dead_code_suppressions`, and `scanner_set_hash`. Explicit boundary overrides passed as an argument (not via `knossos.json`, so `configuration_hash` is unchanged) appear nowhere in that comparison, and `saveProject` never persists them — so they can never be detected. The freshly computed analysis (which *does* incorporate the explicit boundaries) is discarded when the fast path returns. Same for the `name` rename parameter.

### Why it matters
A caller re-scanning an unchanged tree with new explicit boundaries gets a success envelope while `list_boundaries` and every boundary-keyed policy/health check keep serving the previous boundary set — silent wrong results, and quality gates evaluate boundary violations against stale definitions.

### Evidence
`projectConfig()` (lines 118-126) hashes only the four config fields; the `boundaries`/`name` arguments are absent from the comparison and from persistence (verified in source).

### Recommended Fix
Include a hash of the effective explicit boundaries (and the project name) in `projectConfig`, or bypass the fast path whenever `$explicitBoundaries !== null` or `$name` differs from stored state.

### Confidence
High

---

## Issue: PHP worker dies uncatchably on deeply nested source and pollutes the NDJSON channel

### Severity
High

### Location
`workers/php/bin/worker` (no ini hardening); `workers/php/src/PhpScanner.php:36-49`; `workers/php/src/WorkerServer.php:17-32`

### Description
A scanned repo is untrusted input. `PhpScanner::scan` runs php-parser's mutually-recursive traversal, so recursion depth equals AST depth. A ~2 MB file of deeply nested expressions (`$x = ((((…))))`) exhausts the stack or `memory_limit`; in PHP 8.3 both raise **fatal errors, not catchable Throwables**, so `WorkerServer`'s `catch (Throwable)` never fires. `bin/worker` sets no `display_errors=stderr`, no `memory_limit`, no stack-size policy.

### Why it matters
One adversarial or pathological PHP file kills the worker mid-scan **and** injects a non-JSON `Fatal error: …` line into the NDJSON-RPC channel, corrupting framing for the parent.

### Evidence
Empirically verified with `php -n`: a memory-exhaustion fatal printed to **stdout** (not stderr), was not caught by an enclosing `try/catch (\Throwable)`, and exited 255.

### Recommended Fix
In `bin/worker`: `ini_set('display_errors', 'stderr'); ini_set('memory_limit', '512M');`. Pre-scan AST depth iteratively with a cap and emit a per-file diagnostic instead of recursing into a pathological tree, so one file can't take down the batch.

### Confidence
High

---

## Issue: Invalid UTF-8 in scanned source aborts the entire PHP scan batch

### Severity
High

### Location
`workers/php/src/WorkerServer.php:189` `write`

### Description
`write()` does `fwrite(STDOUT, json_encode($message, JSON_THROW_ON_ERROR | …))` with no `JSON_INVALID_UTF8_SUBSTITUTE`. PHP identifiers and string literals legally carry raw bytes ≥ 0x80 (e.g. an ISO-8859-1 class name, or `Route::get("\xFF…")`) into `canonical_name`/`attributes`. `json_encode` throws "Malformed UTF-8" on the first such fact; the exception propagates past the per-file loop and fails the whole request after an arbitrary number of contributions were already streamed.

### Why it matters
One mixed-encoding file — common in legacy PHP — silently discards facts for every file in the batch.

### Evidence
`JSON_THROW_ON_ERROR` present, `JSON_INVALID_UTF8_SUBSTITUTE` absent; the throw escapes the per-file loop to `run()`'s catch (verified in source). Mirrors the confirmed CLI-layer non-UTF-8 JSON encode failure at `CliCommandContext.php:63`.

### Recommended Fix
Add `JSON_INVALID_UTF8_SUBSTITUTE` to the encode flags in `write()` (degrades one bad name to U+FFFD, keeps the channel valid) and/or sanitize captured names per fact.

### Confidence
High

---

## Issue: TypeScript reference canonical names diverge from declaration canonical names — call/extends/constructs edges dangle

### Severity
High

### Location
`workers/typescript/src/scanner.js:272-291` `declaration` vs `:839-852` `canonicalForDeclaration`

### Description
Declared node IDs are built from the **container stack** (class/interface/module/function/method only). Reference targets (for `calls`, `constructs`, `extends`) are built by `canonicalForDeclaration`, which climbs **all** parents and collects a name from any node with a `.name` — including `VariableDeclaration` and `PropertyAssignment`. So `const api = { handlers: { run() {} } }` declares `ts:method:src/x.ts::run` but a call edge targets `…#api.handlers::run`; `const A = class {}` declares `…#{anonymous}@3` but `new A()` targets `…#A.{anonymous}@3`.

### Why it matters
`calls`/`constructs`/`extends` edges to any member reached through a named variable or property assignment never join the emitted node, so the architecture graph systematically loses these relationships in idiomatic JS/TS (object-literal APIs, class expressions, const-defined hooks) — the graph is quietly incomplete exactly where modern TS code concentrates.

### Evidence
Two code paths build canonicals differently; `declarationName` matches `Identifier`/`StringLiteral`/`NumericLiteral` names on any node, and `canonicalForDeclaration` collects them across all ancestors (verified in source).

### Recommended Fix
Unify canonicalization: either restrict `canonicalForDeclaration` to `containerDeclaration` ancestors (mirroring the container stack), or build declaration canonicals via `canonicalForDeclaration` too.

### Confidence
High

---

## Issue: Infection MSI floor (76%) was never validated and contradicts the only full-src measurement (53.34%)

### Severity
High (process/quality-assurance integrity)

### Location
`infection.json5:36-56` (`minMsi: 76`, `minCoveredMsi: 76`); `.github/workflows/mutation.yml:6-8`

### Description
The config comment records a full-src measurement (8,782 mutants, 4,640 killed → MSI **53.34%**, "never set above what was measured"), then documents ratcheting the floor 53 → 60 → 65 → **76** based on per-file samples of ~10 files, closing with "A full-src Infection run (~80 min) in CI will validate this floor." That validation never ran, and mutation testing is `workflow_dispatch`-only — wired into no profile or schedule.

### Why it matters
The floor enforces nothing (mutation never runs in CI), and the number violates the project's own ratchet rule; the next dispatch will almost certainly fail its own gate. ~4,000 previously-escaped mutants are of unknown status. The mutation infrastructure is currently decorative.

### Evidence
`infection.json5` comment and `minMsi: 76` verified; `mutation.yml` `on: workflow_dispatch` only (verified in source).

### Recommended Fix
Run the full-src Infection job and set `minMsi` to the measured result rounded down, or revert to 53 until a full run lands; add a `schedule:` trigger so the floor is actually exercised.

### Confidence
High

---

## Issue: Permission-error test paths are skipped everywhere the suite runs (root container)

### Severity
High (test-coverage integrity)

### Location
`Dockerfile` (quality stage `USER root`, line 91, never dropped); `tests/phpunit/Runtime/DoctorServiceTest.php`, `Store/MigrationRunnerTest.php`, `Discovery/ProjectDiscovererTest.php` (multiple `markTestSkipped`)

### Description
The quality image runs `composer test` as root, and the local dev environment is root too. Seven-plus tests guard with `markTestSkipped('Cannot test permission errors when running as root.')`. Skips don't fail CI, so the gap is invisible.

### Why it matters
Doctor's data-dir-not-writable diagnostics, MigrationRunner's unreadable-migration handling, and ProjectDiscoverer's permission-denied handling are entirely unverified everywhere the suite actually runs — and several of those error paths are exactly where other findings in this audit live (e.g. discovery aborting on an unreadable entry).

### Evidence
`Dockerfile:91 USER root`; skip sites verified in the three test files.

### Recommended Fix
Add a non-root user to the quality stage and run `composer test` under it (root is only needed for the Trivy/docker-socket steps), or re-run the affected groups via `runuser`/`su nobody` inside `tools/quality`.

### Confidence
High

---

## Medium

## Issue: Blocking stdin write can deadlock the MCP server; request timeout not enforced while sending

### Severity
Medium

### Location
`src/Scanner/Worker/NdjsonRpcChannel.php:44-53` `send()`; `WorkerProcessSupervisor.php:48`

### Description
stdin is set blocking, and `send()` loops `@fwrite` with no deadline, no writability `stream_select`, and never drains the worker's stdout/stderr while writing. A request line may be up to `maxLineBytes` = 1 MB — far above the ~64 KB pipe capacity — and scan requests embed the full file list. If the worker blocks writing >64 KB to its own stdout while the parent blocks writing a >64 KB request, both hang forever; `request_timeout_ms` and the cancellation callback are dead during send.

### Why it matters
A single large scan request can permanently hang the server with no timeout escalation.

### Evidence
`send()` has no `stream_select`/deadline; the deadline from `beginRequest()` is consulted only in `readMessage()` (verified in source).

### Recommended Fix
Make stdin non-blocking and implement `send()` as a select-driven loop honoring the same deadline, polling cancellation, and draining stdout/stderr between partial writes.

### Confidence
High

---

## Issue: Scan requests over 1 MB hard-fail — large repos hit a functional ceiling far below the advertised 100k-file limit

### Severity
Medium

### Location
`src/Scanner/Worker/NdjsonRpcChannel.php:40-42` `send()`; `src/Scan/LanguageScanRunner.php:52-56`

### Description
The scan request embeds every to-scan relative path in one NDJSON frame; `send()` throws `WORKER_REQUEST_TOO_LARGE` above `maxLineBytes` (1 MB). Discovery permits 100,000 files; at ~40-60 bytes per encoded path, ~15-25k changed files already breach 1 MB, with no chunking.

### Why it matters
Full/first scans of large repositories deterministically fail for that language, and any request between 64 KB and 1 MB is the exact window exposed to the send() deadlock above.

### Evidence
`WORKER_REQUEST_TOO_LARGE` thrown on `strlen($line) > maxLineBytes`; the request carries `'files' => $paths` for all paths (verified in source).

### Recommended Fix
Batch `files` into multiple requests below a safe frame size (or pass the list via a temp-file path), and separate request-framing limits from response-framing limits.

### Confidence
High

---

## Issue: Stdio input buffering is unbounded on two paths, defeating the `maxLineBytes` cap

### Severity
Medium

### Location
`src/Mcp/StdioServer.php:235-245` `nextLine()`, `:259-263` `pollCancellation()`

### Description
The oversized-frame "discard" loop appends each 8 KB chunk to the buffer before discarding (buffering an entire >1 MB newline-free line, O(n²) on `str_contains`); `pollCancellation()` appends chunks during long `tools/call` operations with no cap at all, and `pendingLines` is uncapped.

### Why it matters
A single malformed or malicious stdin stream can OOM the server — precisely what `maxLineBytes` was meant to prevent.

### Evidence
Discard loop does `$this->inputBuffer .= $discard`; `pollCancellation` does `$this->inputBuffer .= $chunk` with no size guard (verified in source).

### Recommended Fix
In the discard loop, keep only the post-newline tail; in `pollCancellation`, enter a discard-until-newline state once buffered bytes exceed `maxLineBytes`, and cap `pendingLines`.

### Confidence
High

---

## Issue: Uncaught exceptions in the HTTP transport produce raw 500s instead of JSON-RPC errors

### Severity
Medium

### Location
`src/Mcp/HttpEndpoint.php:94-95, 141-144`; `bin/http-router.php:59`

### Description
`HttpEndpoint::handle()` calls `$server->handle($message)` with no try/catch on either the initialize or post-session path, and the router has none either. `StdioServer::handle()` only guards the `tools/call` branch, so a PDOException from `resources/list` or a JsonException from non-UTF-8 tool output escapes as a raw PHP 500 — unlike the stdio transport, which catches Throwable and answers `-32603`.

### Why it matters
Any DB hiccup or non-UTF-8 payload during resources/prompts handling fatals the request with a non-JSON-RPC 500 (potentially leaking absolute paths/SQL if `display_errors` is on), diverging from the stdio transport.

### Evidence
Both `$server->handle()` call sites lack try/catch (verified in source).

### Recommended Fix
Wrap the `handle()` calls in try/catch Throwable, returning a `-32603` JSON-RPC error; add a backstop catch in `bin/http-router.php`.

### Confidence
High

---

## Issue: Unauthenticated HTTP session-slot exhaustion via repeated `initialize`

### Severity
Medium

### Location
`src/Mcp/HttpEndpoint.php:90-106`; `src/Mcp/HttpSessionStore.php:35-36`; `bin/http-router.php:40-41`

### Description
The bearer token is optional and absent by default. Every `initialize` POST creates a session with no rate limit and no client binding; `create()` hard-fails at 1,000 rows, TTL 1,800 s. The Host check is satisfiable by any client, and the Origin check is skipped when the header is omitted.

### Why it matters
Anyone who can reach the port fills 1,000 slots in seconds, giving all legitimate `initialize` calls a 503 for up to 30 minutes, refillable indefinitely — mitigated only by an operator-controlled loopback bind (docs show a `0.0.0.0` Docker bind).

### Evidence
Optional token, unbounded `create()` on every initialize, capacity error at 1,000 (verified in source).

### Recommended Fix
When `bearerToken === null`, refuse non-loopback peers (or require the token for session creation); evict oldest-uninitialized sessions instead of hard-failing; rate-limit `create()`.

### Confidence
High

---

## Issue: HTTP transport omits the working-tree git provider — `changed_files_impact` working-tree mode and the `review_diff` prompt always fail over HTTP

### Severity
Medium

### Location
`bin/http-router.php:49` (no `gitWorkingTree:`) vs `src/Cli/Command/ServeCommand.php:40-44`

### Description
The HTTP router builds `ArchitectureQueryService` with only `gitHistory:`, while `ServeCommand` (stdio) also wires `gitWorkingTree:`. `changedFilesImpact` throws "Working-tree change discovery is unavailable" when the provider is null, yet `tools/list` over HTTP advertises the identical schema (with `working_tree`/`base_ref`) and the `review_diff` prompt directs clients into this call.

### Why it matters
A whole advertised tool mode and the `review_diff` workflow deterministically error on the HTTP transport only, with no schema hint of the limitation.

### Evidence
Router construction omits `gitWorkingTree:` (verified in source, contrasted with ServeCommand which includes it).

### Recommended Fix
Add `gitWorkingTree: new \Knossos\Git\ProcessGitWorkingTreeProvider()` to the router, mirroring ServeCommand.

### Confidence
High

---

## Issue: `review_diff` prompt instructs a tool call that always throws

### Severity
Medium

### Location
`src/Mcp/PromptService.php:78-92`; `src/Query/ChangeImpactQueryService.php:114-133`

### Description
With a base ref, the prompt says `pass base_ref: "%s"` and "Call changed_files_impact". An agent following it calls `changed_files_impact {project_id, base_ref}`. In `changedFilesImpact`, `if ($workingTree === ($files !== []))` is `false === false` → throws "Provide either files or working_tree, but not both" *before* the clearer "base_ref requires working_tree" check is reached. The schema never documents that `base_ref` requires `working_tree: true`.

### Why it matters
The canned workflow ships an error path: agents get a misleading error, burn a round-trip, and (per the previous finding) still fail over HTTP even after correcting it.

### Evidence
Prompt text and the check ordering in `changedFilesImpact` (verified in source).

### Recommended Fix
Change the prompt to `pass working_tree: true and base_ref: "%s"`, document the coupling in the schema, and reorder the checks so a lone `base_ref` yields the specific message.

### Confidence
High

---

## Issue: User cancellation during a worker scan is misclassified as a worker failure

### Severity
Medium

### Location
`src/Scanner/Worker/ScannerProtocolSession.php:101-104`; `src/Scan/LanguageScanRunner.php:63-64`; `src/Watch/WatchScanAttempt.php:44-53`; `src/Mcp/StdioServer.php:134`

### Description
Once `iterator_to_array($client->scan(...))` begins, cancellation is detected inside the session and throws `WorkerException('WORKER_CANCELLED')` — the runner's `throwIfCancelled()` is never reached, and its `catch (Throwable)` rethrows unchanged. `WatchScanAttempt` catches only `ScanCancelledException` for the CANCELLED outcome, so a WorkerException becomes RETRYABLE; `StdioServer` maps only `ScanCancelledException` to `KNOSSOS_SCAN_CANCELLED`.

### Why it matters
A deliberately cancelled scan is reported as a worker failure (generic MCP error; "retryable" in watch mode), and the whole worker pool is torn down, penalizing the next scan.

### Evidence
`WORKER_CANCELLED` thrown from the generator; callers branch only on `ScanCancelledException` (verified in source).

### Recommended Fix
In `LanguageScanRunner`'s catch (or `ProjectScanService`), translate a cancelled-state WorkerException into `ScanCancelledException` with the original as previous.

### Confidence
High

---

## Issue: Writer lock is a fixed 1-hour lease with no renewal — crash lockout, and a lost-update window on long scans

### Severity
Medium

### Location
`src/Scan/ProjectWriterLock.php:14, 22-23`; `src/Scan/ProjectWriterLease.php:20-28`; `src/Scan/ProjectScanService.php:73`

### Description
`acquire` deletes rows older than `now - 3600` then inserts; nothing refreshes `acquired_at` during a scan and there's no pid/heartbeat check. A SIGKILL/OOM leaves the row, locking out that project for up to an hour; a legitimately long scan (>1 h) has its row expired-deleted by a second scan, and both then run `reconcile` believing they're exclusive — if the older commits last, the graph silently reverts. `release()` deleting 0 rows is never detected.

### Why it matters
Availability loss after crashes plus a silent lost-update where a stale scan overwrites a fresher one with both callers receiving success.

### Evidence
Fixed 3600 s expiry, no renewal, `release()` return value unchecked (verified in source).

### Recommended Fix
Renew the lease before reconcile via `UPDATE … SET acquired_at = ? WHERE owner_token = ?`, aborting if 0 rows matched (lease stolen); verify token ownership inside the write transaction; store pid+host for informed recovery.

### Confidence
High

---

## Issue: Deferred BEGIN in `transaction()` — read-then-write upgrade under WAL fails non-retryably

### Severity
Medium

### Location
`src/Store/SqliteGraphRepository.php:20-39` `transaction`

### Description
`transaction()` uses `PDO::beginTransaction()` (deferred BEGIN); the reconcile transaction reads first (`findProject`, snapshot SELECTs), establishing a read snapshot before its first write. In WAL, if another connection commits between that read and the first write, SQLite returns SQLITE_BUSY immediately on upgrade and the busy handler is *not* invoked for snapshot-upgrade conflicts. `ProjectWriterLock` only serializes same-project writers; other projects, HTTP session bookkeeping, and the lock table share the DB. The authors already use `BEGIN IMMEDIATE` in `ProjectWriterLock` but not here.

### Why it matters
Concurrent activity can abort an entire scan with a spurious "database is locked" after all scanner work is done.

### Evidence
`beginTransaction()` used for the write path; first statements are reads (verified in source).

### Recommended Fix
Use `BEGIN IMMEDIATE` (with manual COMMIT/ROLLBACK) for write transactions.

### Confidence
High

---

## Issue: Concurrent scans surface raw "database is locked" instead of `ScanBusyException`

### Severity
Medium

### Location
`src/Scan/ProjectWriterLock.php:20, 32-35`; `src/Store/SqliteConnection.php:35`

### Description
`acquire` converts only SQLSTATE `23000`/UNIQUE into `ScanBusyException`. A second process scanning any project while another's reconcile transaction (which can exceed the 5 s `busy_timeout`) holds the WAL write lock gets SQLITE_BUSY (`HY000`, "database is locked") rethrown raw.

### Why it matters
Spurious, mislabeled failures under mild cross-project concurrency; clients can't distinguish "retry later" from real corruption.

### Evidence
Only `23000`/UNIQUE mapped to `ScanBusyException` (verified in source).

### Recommended Fix
Treat SQLITE_BUSY (`HY000` + "database is locked"/"busy") as `ScanBusyException`; consider retrying `BEGIN IMMEDIATE` with backoff.

### Confidence
High

---

## Issue: Bundle import decodes untrusted JSON before any size/shape validation — memory-amplification DoS

### Severity
Medium

### Location
`src/Bundle/GraphBundleDecoder.php:23-32` `decodeAndValidate`

### Description
The decoder bounds compressed (10 MB) and uncompressed (50 MB) *bytes*, then runs `json_decode` on the full document before any shape or `MAX_FACTS` validation. A 10 MB gzip expanding to 50 MB of dense scalar tokens decodes to ~25 M PHP zvals (~1 GB+ of arrays) before validation runs. An OOM here is a fatal engine error.

### Why it matters
A single untrusted bundle can OOM-kill the long-running server.

### Evidence
`json_decode(...)` precedes `validateTables`/fact counting (verified by code order).

### Recommended Fix
Cap uncompressed size far lower (e.g. 8 MB) and/or bound token density before `json_decode`; import in a bounded-memory subprocess.

### Confidence
Medium

---

## Issue: Bundle importer stores untrusted non-integer values in integer columns (CHECK bypassed via SQLite affinity)

### Severity
Medium

### Location
`src/Bundle/PortableGraphImporter.php:60, 75, 103, 113` (line numbers), `:22, 40, 48` (timestamps/hashes)

### Description
`start_line`/`end_line` pass straight from the untrusted bundle with no type validation (unlike `size`, which uses `nonNegative`). `CHECK (start_line IS NULL OR start_line >= 1)` does not stop TEXT: in SQLite cross-type ordering TEXT > INTEGER, so `'evil' >= 1` is true. `finished_at` is any unbounded string; `content_hash`/`scanner_set_hash` use bare `(string)` casts (an array becomes `"Array"`).

### Why it matters
Imported line columns violate their implicit `int|null` contract; downstream consumers doing arithmetic or `(int)` casts (diagram export, source excerpts, `snapshot_diff`) produce wrong output or type errors. No cross-project overwrite (IDs are remapped), so this is data-quality, not privilege.

### Evidence
Empirically verified against the real schema: `INSERT … start_line='evil'` succeeds with `typeof = text`.

### Recommended Fix
Validate `start_line`/`end_line` as nullable positive ints, `finished_at` against an ISO-8601 regex with a length cap, and route hashes through a hex-shape check.

### Confidence
High

---

## Issue: `archiveActiveSnapshot` materializes up to 1.4M rows in memory inside the write transaction, every scan

### Severity
Medium

### Location
`src/Store/SqliteGraphRepository.php:141-161`

### Description
For each of 7 tables it `SELECT * … LIMIT 200001` and `fetchAll()`s before checking the over-limit condition; all 7 dumps are held simultaneously, then JSON-encoded to a string up to 50 MB (checked only after encoding). This runs at the top of every reconcile transaction, including 18-file incrementals.

### Why it matters
Multi-hundred-MB memory spikes per scan; on large projects `memory_limit` can kill the scan mid-transaction, and it adds seconds of held-lock latency.

### Evidence
`fetchAll()` precedes the `count > 200_000` check; `self::json($payload)` size checked post-encode (verified in source).

### Recommended Fix
`SELECT COUNT(*)` per table before fetching, stream rows / incrementally build JSON, skip archiving on an unchanged graph, and move the encode outside the write transaction.

### Confidence
High

---

## Issue: Kosaraju SCC returns merged, false cycles when the deadline fires mid pass-1

### Severity
Medium

### Location
`src/Query/AbstractArchitectureQueryService.php:141-201` `stronglyConnectedComponents`

### Description
Pass 1 aborts with `break 2`, leaving a partial, invalid finish ordering; pass 2 still runs reverse-graph DFS over it. Kosaraju's correctness requires the complete decreasing finish order — with a truncated one, reverse DFS from an early-finished node can sweep multiple distinct SCCs (and non-cycle nodes) into one "component". `timed_out`→`truncated` marks the result incomplete but nothing signals it may be *wrong*, and `architectureHealth` feeds these into `cycle_participant` scoring.

### Why it matters
`dependencyCycles` can report unrelated nodes as a single cycle with member lists and evidence; hotspot scoring inherits the error.

### Evidence
`break 2` on timeout leaves `$finish` partial; pass 2 unconditionally follows (verified in source).

### Recommended Fix
On pass-1 timeout, discard component output (`components: []`, `timed_out: true`); never run pass 2 over a partial finish order.

### Confidence
High

---

## Issue: `impactEdges`/`flowEdges` hard `LIMIT 500` silently drops a hub's edges with `truncated=false`

### Severity
Medium

### Location
`src/Query/GraphTopologyQueryService.php:715, 730`

### Description
Both per-node edge queries end with `LIMIT 500` and no limit+1 detection; `impactAnalysis` then reports `truncated=false` and `truncation_reason=null` when a node has >500 inbound edges of the selected kinds.

### Why it matters
For exactly the high-in-degree hubs where impact analysis matters most, dependants beyond the top-500-confidence edges are silently missing while the envelope claims completeness — contradicting the "conservative blast radius" warning.

### Evidence
`LIMIT 500` with no overflow signal (verified in source).

### Recommended Fix
Fetch `LIMIT 501`; when 501 rows return, set `truncated=true` with a `per_node_edge_limit` reason (or paginate the per-node scan).

### Confidence
High

---

## Issue: Per-cycle member/edge truncation never sets the envelope flag; health mislabels participants in SCCs >100 members

### Severity
Medium

### Location
`src/Query/GraphTopologyQueryService.php:146-173` `dependencyCycles`; `:273-284, 308-309` `architectureHealth`

### Description
`$edgeTruncated`/`$memberTruncated` feed only per-cycle fields; the envelope `truncated` and `bounds.truncation_reasons` are untouched. `architectureHealth` builds `$cycleMembers` from the already-sliced `array_slice($component, 0, 100)`, and reads only the (unset) envelope flag for `cycle_scan_truncated`.

### Why it matters
In a 500-node SCC, 400 real cycle members get `cycle_participant=false` and lose the hotspot bonus, while `cycle_scan_truncated` reports false — health silently understates the biggest tangles, and `dependencyCycles` returns `truncated:false` on demonstrably truncated data.

### Evidence
Per-cycle truncation not OR'd into the envelope; membership collected post-slice (verified in source).

### Recommended Fix
OR per-cycle truncation into the envelope with `member_limit`/`internal_edge_limit` reasons; collect cycle membership from the raw component IDs before slicing.

### Confidence
High

---

## Issue: `snapshot_diff` loads two full snapshots (up to 1.4M rows each) into memory; `architecture_trends` loops it up to 20×

### Severity
Medium

### Location
`src/Query/ProjectCatalogQueryService.php:397-408, 144-145, 324-340`

### Description
`snapshotFacts` `fetchAll()`s `SELECT * … LIMIT 200001` across 7 tables; `snapshotDiff` calls it for both endpoints and builds a second ksorted copy; archived snapshots additionally `json_decode` payloads up to 50 MB; `architectureTrends` repeats this for up to 20 snapshots.

### Why it matters
A large project pushes a single `snapshot_diff` into multi-GB PHP memory (rows + indexed copies + decoded JSON at once) — OOM rather than a graceful "too large".

### Evidence
Full `fetchAll` + second sorted copy + payload decode (verified in source).

### Recommended Fix
Diff table-by-table (load, diff, free), compare via per-row hashes, reuse one decoded payload, and guard by byte size before decode.

### Confidence
Medium

---

## Issue: Descendant worker processes orphaned during the grace window and always on non-Linux

### Severity
Medium

### Location
`src/Scanner/Worker/WorkerProcessSupervisor.php:122-144, 151-168`

### Description
Descendants are enumerated once and signaled only inside `if ($status['running'])`. A worker that exits on stdin EOF during the 100 ms grace, leaving grandchildren, skips both signal passes → orphans reparented to init. `descendantPids()` returns `[]` unless Linux, so macOS/Windows never kill the tree; the `/proc/<pid>/task/<tid>/children` read also misses cross-thread children and anything spawned after the single enumeration. Contradicts `ScannerClient`'s "release its complete process tree".

### Why it matters
Zombie scanner helpers accumulate across scans (memory, handles, stale locks), especially under watch mode.

### Evidence
Single enumeration, running-only signaling, Linux-only PID discovery (verified in source).

### Recommended Fix
Re-enumerate before each signal pass, signal even after the child exits, and prefer a process group (`posix_setsid`/`setpgid` in the worker + `posix_kill(-$pgid, …)`), which also fixes macOS.

### Confidence
High

---

## Issue: Worker crash mid-line busy-spins at 100% CPU for the full timeout and misreports TIMEOUT

### Severity
Medium

### Location
`src/Scanner/Worker/NdjsonRpcChannel.php:116-122` `readMessage()`

### Description
Exit detection requires `stdoutBuffer === ''`. A worker dying with a partial (newline-less) line never satisfies it: `stream_select` returns instantly on the EOF fd, `fread` returns `''`, and the loop spins hot until the timeout (up to 120 s), then throws WORKER_TIMEOUT instead of WORKER_EXITED. WatchService then treats it as retryable.

### Why it matters
A crashing scanner pegs a core for up to two minutes per request and the diagnostic lies, sending operators down the wrong path.

### Evidence
The three-part EOF condition and instant re-select on EOF fds (verified in source).

### Recommended Fix
Drop the `stdoutBuffer === ''` requirement (throw WORKER_EXITED on `!running && feof`), and treat `fread() === ''` on an EOF stream as terminal.

### Confidence
High

---

## Issue: A file removed between readdir and stat aborts the entire scan

### Severity
Medium

### Location
`src/Discovery/ProjectDiscoverer.php:89, 118, 56-76` `discover`

### Description
Only `DirectoryIterator` construction is wrapped; `SplFileInfo::getSize()`/`getMTime()` throw `RuntimeException` when `stat` fails (a temp file removed by a concurrent build), propagating out of `discover()` and failing the whole scan. `FileFingerprint::compute` handles unreadable files gracefully, but the stat calls around it do not.

### Why it matters
Scans of actively-churning trees (watchers, builds) fail entirely instead of emitting a per-file diagnostic.

### Evidence
Unwrapped `getSize()`/`getMTime()` (verified in source).

### Recommended Fix
Wrap the per-entry body in try/catch (Throwable), emit a `DISCOVERY_FILE_UNREADABLE` diagnostic, and continue.

### Confidence
High

---

## Issue: TOCTOU between discovery-time hashing and worker-time reads can poison the contribution cache

### Severity
Medium

### Location
`src/Discovery/ProjectDiscoverer.php:100`; `src/Scan/LanguageScanRunner.php:63`; `src/Scan/ContributionCacheService.php:74-77`

### Description
The cache pairs the discovery-time content hash with the contribution the worker produced from bytes read minutes later. If the file changes in that window, the cache stores (hash A → facts of B). Normally self-healing, but if the file later *reverts* to content A (git checkout, branch switch, undo), every subsequent incremental scan takes the cache hit and serves facts-of-B for content-A indefinitely. Size is likewise statted before hashing, so `maxFileBytes` can be bypassed by a file growing in between.

### Why it matters
Persistently wrong facts for the affected file, with source-line evidence pointing at nonexistent lines and no invalidation trigger.

### Evidence
Hash computed at discovery, content read later, cache keyed on the discovery hash (verified in source).

### Recommended Fix
Have the worker return the hash of the bytes it actually parsed (or re-fingerprint at scan time) and rescan entries whose scan-time hash differs.

### Confidence
High (mechanism); Medium (frequency)

---

## Issue: IgnoreMatcher uses fnmatch, not gitignore semantics — patterns silently fail at depth

### Severity
Medium

### Location
`src/Discovery/IgnoreMatcher.php:56-72` `matches`

### Description
User patterns are `fnmatch($pattern, $path, FNM_PATHNAME)` against the full relative path. With `FNM_PATHNAME`, `*` never crosses `/` and `**` is just two non-crossing stars (only trailing `/**` is special-cased). So `*.log` matches only top-level files, `generated` only a top-level entry, `**/fixtures` only one level, and `!keep.php` is a literal name.

### Why it matters
Users porting `.gitignore`-style patterns get unignored trees scanned (slow, junk nodes) or believe files are excluded when they aren't — with no warning for never-matching patterns.

### Evidence
`fnmatch(..., FNM_PATHNAME)` with only trailing-`/**` handling (verified in source).

### Recommended Fix
Implement gitignore matching (segment-wise `**`, basename matching for slash-free patterns, negation), or document the exact fnmatch semantics and reject unsupported constructs with a diagnostic.

### Confidence
High

---

## Issue: Cancellation is unobservable during discovery, planning, analysis, and reconciliation

### Severity
Medium

### Location
`src/Scan/ProjectScanService.php:56-83`; `src/Discovery/ProjectDiscoverer.php:21-155`

### Description
The token is checked around worker RPCs but `planner->prepare()` (which walks and SHA-256-hashes up to 100,000 files) gets no token, nor do finalize/partition/analyze. Discovery is the longest non-worker stage and runs cancel-blind.

### Why it matters
`notifications/cancelled` does nothing for the entire discovery stage — clients wait minutes for a cancel ack; watch-mode shutdown is equally delayed.

### Evidence
No token threaded into `discover`/`partition` (verified in source).

### Recommended Fix
Thread the `CancellationToken` into `ProjectDiscoverer::discover` (check every N files) and `ContributionCacheService::partition`.

### Confidence
High

---

## Issue: Python worker misreports valid BOM-prefixed and PEP 263 non-UTF-8 files as syntax errors

### Severity
Medium

### Location
`workers/python/bin/worker.py:594` `scan`

### Description
`ast.parse(absolute.read_text(encoding="utf-8"), ...)`. A UTF-8 BOM (legal Python; CPython strips it from bytes) decodes to a leading U+FEFF and `ast.parse` raises `SyntaxError`; a PEP 263 `# -*- coding: latin-1 -*-` file raises `UnicodeDecodeError`, converted to a fake `PY_SYNTAX_ERROR`.

### Why it matters
Valid source files (BOMs are routine from Windows editors) produce zero facts plus a false error diagnostic.

### Evidence
Empirically verified: BOM file → `SyntaxError: invalid non-printable character U+FEFF`.

### Recommended Fix
Parse bytes (`ast.parse(absolute.read_bytes(), ...)`, which honors BOM/coding cookies) or use `encoding="utf-8-sig"`.

### Confidence
High

---

## Issue: Python absolute-import resolution breaks for src/-layout projects

### Severity
Medium

### Location
`workers/python/bin/worker.py:66-71, 465-472, 596-603`

### Description
`module_name("src/app/models.py")` → `"src.app.models"`, but `from app.models import User` resolves to `"app.models"`, so the declarations lookup misses and the edge targets a `py:module:app.models` node that never exists. Any project whose packages aren't at the repo root is affected.

### Why it matters
For src-layout/monorepo projects, all absolute intra-project imports, `extends` bases, and call targets degrade to dangling external references — the Python graph is largely disconnected.

### Evidence
`module_name` derives from the raw path; `absolute_import` strips no source root (verified in source).

### Recommended Fix
Detect source roots (dirs containing top-level packages / `pyproject.toml` / `__init__.py` chains) and strip them in `module_name`.

### Confidence
High

---

## Issue: Python fact identity depends on scan-batch composition — non-deterministic IDs across chunkings

### Severity
Medium

### Location
`workers/python/bin/worker.py:471, 448-456, 596-603`

### Description
`declarations` covers only the current request's files. The same import yields target `py:class:app.models.User` if `app/models.py` is in the batch, or `py:external_symbol:app.models.User` if it was chunked elsewhere.

### Why it matters
Edge targets — and downstream stable IDs/hashes/diffs — change with batching; incremental subset scans produce different edges than full scans, violating fact determinism.

### Evidence
Per-request declarations map with an external-symbol fallback (verified in source).

### Recommended Fix
Emit a batch-independent target form and resolve to declared nodes server-side, or supply a project-wide declarations index.

### Confidence
High

---

## Issue: Python and TS scans retain all parsed ASTs for the whole batch

### Severity
Medium

### Location
`workers/python/bin/worker.py:589-608`; `workers/typescript/src/scanner.js:648-688, 910-937`

### Description
Python parses **all** files into a `parsed` list before emitting, keeping every tree alive until return. The TS worker's `createRestrictedProgram` reads/tokenizes/binds every include-matched or import-reachable file in the root, and the 2 MB `max_file_bytes` cap is enforced only on `params.files`, never on program-pulled files.

### Why it matters
Peak memory scales with total batch source (Python ASTs ~10× source); a large monorepo OOM-kills the worker, and one giant generated `.d.ts` (never requested) is still fully parsed despite the documented per-file limit.

### Evidence
Python's two-loop structure retains `parsed`; TS `host.readFile` has no size guard (verified in source).

### Recommended Fix
Python: emit-and-null trees during the second pass (or collect only top-level names in pass 1). TS: wrap `host.readFile`/`getSourceFile` with a `statSync` size check returning `undefined` + a diagnostic above the cap.

### Confidence
High

---

## Issue: One pathological file aborts the whole scan request in all three workers

### Severity
Medium

### Location
`workers/php/src/WorkerServer.php:118-122`; `workers/typescript/src/scanner.js:209-215, 928-936`; `workers/python/bin/worker.py:409-435, 591-592`

### Description
Unbounded AST-walker recursion (TS `visit` → `RangeError`, Python `generic_visit`/`dotted` → `RecursionError`) and per-file limit/existence violations are all handled at request granularity: TS/Python catch only at the request level; Python's per-file try catches only `(SyntaxError, UnicodeDecodeError)`, and `collect()` runs outside it; PHP throws `WorkerInputException` mid-loop after earlier contributions streamed. (The PHP fatal case is the separate High finding — uncatchable.)

### Why it matters
A single generated/minified/adversarial file discards facts for every other file in the request; the parent reconciles a request-level error with already-applied partial contributions.

### Evidence
Request-level-only guards; Python `collect()` outside the per-file try (verified in source).

### Recommended Fix
Wrap per-file collection in try/catch, emit a per-file `*_INTERNAL_ERROR` diagnostic, and continue; Python also catch `RecursionError`; convert per-file limit violations into per-file diagnostics.

### Confidence
High

---

## Issue: `git log` history silently drops all non-ASCII file paths

### Severity
Medium

### Location
`src/Git/ProcessGitHistoryProvider.php:35-39, 58-66`

### Description
The command is `git … log … --name-only …` with no `-z` and no `-c core.quotePath=false`. With git's default `core.quotePath=true`, any path with bytes >0x7F is octal-escaped and quoted; `parse()` then runs `RelativePath::assertValid` (which rejects `\`) and the `catch (Throwable) { continue; }` silently discards it.

### Why it matters
`change_impact` churn/hotspot data silently omits every file with a non-ASCII name on ordinary repos (German/French/CJK), with no diagnostic.

### Evidence
No `core.quotePath=false`; validation throws on the escaped path, caught-and-continued (verified in source).

### Recommended Fix
Add `-c core.quotePath=false` (or use `--name-only -z` and split on NUL) before validating.

### Confidence
High

---

## Issue: Non-UTF-8 filenames make JSON output throw, failing whole commands and tools

### Severity
Medium

### Location
`src/Cli/CliCommandContext.php:63`; `src/Git/ProcessGitWorkingTreeProvider.php:42-77`; `src/Mcp/StdioServer.php:126-131`

### Description
`git diff -z`/`ls-files -z` bring raw filesystem bytes into results; `RelativePath::assertValid` has no UTF-8 check. `output()` uses `JSON_THROW_ON_ERROR` with no `JSON_INVALID_UTF8_SUBSTITUTE`, so a latin-1-named file (valid on ext4) throws `JsonException` → exit 2 / `KNOSSOS_TOOL_ERROR`, discarding the entire result.

### Why it matters
One oddly-named file anywhere denies `changed-files-impact` (and any query over stored non-UTF-8 paths) entirely, in both CLI JSON and MCP modes.

### Evidence
`-z` providers + no substitute flag at the encode boundaries (verified in source).

### Recommended Fix
Add `JSON_INVALID_UTF8_SUBSTITUTE` at output boundaries, or reject/normalize non-UTF-8 paths at ingestion.

### Confidence
High

---

## Issue: Explicit boundary `namespace_prefix` is not separator-anchored, inflating membership and corrupting policy verdicts

### Severity
Medium

### Location
`src/Boundary/BoundaryInference.php:74-75, 100-102`

### Description
Inferred namespace rules are anchored (`$namespace . '\\'`), but explicit rules are not: `ltrim($rule['namespace_prefix'], '\\')` with a bare `str_starts_with`. A boundary declared `"namespace_prefix": "App"` matches `Apple\Service`, `AppKernel`, etc. `path_prefix` is anchored via `pathPrefix()`; namespace is not.

### Why it matters
`check-architecture` policies key allow/deny verdicts on boundary membership, so false members both fabricate and mask policy violations — a policy-integrity bug.

### Evidence
Inferred path appends `\`; explicit path does not (verified in source).

### Recommended Fix
Normalize explicit namespace prefixes to end with `\`, or match `$candidate === $prefix || str_starts_with($candidate, $prefix . '\\')`.

### Confidence
High

---

## Issue: `doctor` PHP version check enforces no minimum — passes on unsupported PHP

### Severity
Medium

### Location
`src/Runtime/DoctorService.php:19`

### Description
`PHP_VERSION_ID < 80500 ? PHP_VERSION : throw …('PHP 8.3 or 8.4 is required.')` rejects only ≥ 8.5. On PHP 8.1 (or 7.4) it reports `[OK] php.version`.

### Why it matters
The environment-validation tool green-lights unsupported PHP while its own message claims 8.3/8.4 is required; users debugging old-PHP failures are told the environment is healthy.

### Evidence
Only the upper bound is checked (verified in source).

### Recommended Fix
`PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80500 ? PHP_VERSION : throw …`.

### Confidence
High

---

## Issue: `doctor` external-command probe has no timeout and deadlocks on >64KB stderr

### Severity
Medium

### Location
`src/Runtime/DoctorService.php:91-108` `command()` (found independently by two audit passes)

### Description
Unlike `GitProcessRunner` (deadline + non-blocking select), `command()` does blocking `stream_get_contents($pipes[1])` to stdout EOF before reading stderr, with no timeout. A probed binary that hangs, or writes >64 KB to stderr while stdout is open, blocks `knossos doctor` forever.

### Why it matters
`doctor` hangs indefinitely on a chatty/failing `node`/`python3`/`git` shim instead of reporting an error.

### Evidence
Sequential blocking reads, no timeout (verified in source).

### Recommended Fix
Reuse `GitProcessRunner` (it is command-agnostic) or replicate its deadline/select loop.

### Confidence
High

---

## Issue: Python worker has zero native tests; `bin/http-router.php` is never executed by any test

### Severity
Medium

### Location
`workers/python/bin/worker.py` (no pytest); `bin/http-router.php` (mapped to the `transport` coverage component but never booted)

### Description
`git ls-files workers/python` returns only `worker.py`; `tools/quality` runs only ruff/mypy on it, and its 96% coverage floor is collected solely by PHP integration tests. Separately, `pcov-report.php` maps `bin/http-router.php` into the `transport` component (floor 92.2), but no test boots `php -S` with it — its env parsing (`KNOSSOS_ALLOWED_ROOTS`, bearer token, allowed hosts/origins) is untested, and an unexecuted file evidently drops out of the coverage aggregation silently.

### Why it matters
The residual Python branches the PHP harness can't reach are unverified, and the network-exposed HTTP bootstrap — the file docs tell users to run — has zero execution coverage while the transport coverage number overstates what's verified.

### Evidence
No pytest in the repo; `HttpTest.php` constructs `HttpEndpoint` directly, never the router (verified).

### Recommended Fix
Add a pytest suite for `worker.py` pure functions wired into `tools/quality`; add one integration test that boots `php -S … bin/http-router.php` asserting 401/403/200; make `pcov-report.php` treat listed-but-unexecuted files as 0%.

### Confidence
High (Python untested, router untested); Medium (coverage silently ignoring the router)

---

## Issue: `composer check` is a weaker gate than CI and mutation gates nothing

### Severity
Medium

### Location
`composer.json:52-58`; `tools/quality:17-29`; `.github/workflows/mutation.yml:5-8`

### Description
`composer check` = validate + workers check + `php -l` + tests; CI additionally runs php-cs-fixer, phpstan, eslint, prettier, markdownlint, ruff, mypy, doc-drift checks, and coverage floors. Mutation testing is `workflow_dispatch`-only, excluded from every profile.

### Why it matters
A developer treating the command literally named `check` as "the check" gets green locally and red in CI for phpstan/cs-fixer/doc-drift; and the ratcheted MSI floor exercises nothing (compounding the High mutation finding).

### Evidence
`composer check` script contents; `mutation.yml` trigger (verified).

### Recommended Fix
Rename to `check:quick` or delegate to `tools/quality fast`; add a `schedule:` trigger to `mutation.yml`.

### Confidence
High

---

## Low

Grouped; each verified against source.

- **Stale descendant-PID SIGKILL can hit a reused PID** (`WorkerProcessSupervisor.php:123-142`) — up to ~350 ms between enumeration and SIGKILL; a same-user process reusing the PID is killable. Prefer process-group kill. *Confidence: Medium.*
- **Scanner workers inherit the full server environment** (`WorkerProcessSupervisor.php:41`, `proc_open(..., $environment=null)`) — PATH, secrets, DB creds flow into untrusted-source parsers. Pass a minimal explicit env + neutral cwd. *Confidence: High.*
- **Initial watch fingerprint taken after the initial scan** (`WatchService.php:51,65`) — files changed during the first scan are permanently missed (the poll loop correctly pre-snapshots). Capture the fingerprint before the initial `scan()`. *Confidence: High.*
- **Watch mode SHA-256-rehashes the entire project every poll tick** (`WatchService.php:163-181`, `FileFingerprint.php:22-50`) — no (size,mtime) short-circuit; continuous full-content rehashing twice a second on large repos. Cache fingerprints by `(path,size,mtime)`. *Confidence: High.*
- **Watch retries permanent worker failures forever as "retryable"** (`WatchScanAttempt.php:46-54`) — WORKER_START_FAILED / VERSION_MISMATCH / REQUEST_TOO_LARGE retried every ≤30 s for the life of the watch. Classify by diagnostic code. *Confidence: High.*
- **Cooperative cancel sends `request_id` as string vs the int scan id** (`ScannerProtocolSession.php:90-141`) — type-strict workers never match; cancel silently no-ops (masked today by the SIGKILL path). *Confidence: Medium.*
- **Abandoned `scan()` generator poisons the pooled session** (`ScannerProtocolSession.php:87-128`) — early-stopped iteration leaves an unread frame that fails the next request on that worker with a protocol error. Latent; wrap in try/finally or make eager. *Confidence: High.*
- **Dead worker reused across scans → one guaranteed failure before recovery** (`LanguageWorkerPool.php:30-34`) — `initialize()` returns the cached manifest without a liveness probe. Check `isRunning()` and respawn. *Confidence: High.*
- **Project identity/lock keyed on byte-exact realpath** (`ProjectScanService.php:72`) — case-insensitive filesystems can yield duplicate projects and two lock rows for one tree. Canonicalize case / use device+inode. *Confidence: Medium.*
- **Lease release relies solely on `__destruct`** (`ProjectScanService.php:73`) — no try/finally; latent 1-hour lockout if a refactor extends the lease lifetime. Wrap in finally, log zero-row releases. *Confidence: High.*
- **Unknown tool / invalid args returned as `isError` instead of JSON-RPC -32602** (`StdioServer.php:132-146`, `ToolService.php:699`) — spec-conformant clients mis-handle protocol errors as tool output. *Confidence: High.*
- **`refresh_if_stale` honored on tools that don't declare it, before arg validation** (`ToolService.php:603-630`) — `remove_project {refresh_if_stale:true}` rescans the project it's about to delete; unknown-arg requests rescan then fail validation. Gate to declaring tools, validate first. *Confidence: High.*
- **Client cancellation swallowed; cancelled requests still get full responses** (`ToolService.php:661-666`, `StdioServer.php`) — `refreshIfStale` catches `ScanCancelledException` and proceeds; `cancelledRequests` map never pruned. Rethrow, suppress the response, prune the map. *Confidence: High.*
- **`max_chars` never trims `evidence` or 1-element lists** (`ResultEnricher.php:32-69,116-138`) — under `verbosity:full` the byte budget is silently not honored. Include evidence in the victim walk; lower the selection floor. *Confidence: High.* (Note: nested-list trimming was fixed on `fix/self-test-findings`; this is the remaining evidence/floor gap.)
- **`scan_project` schema defaults wrong when knossos.json overrides** (`ToolService.php:70-71`, `ScanPlanner.php:30-42`) — advertised `snapshot_retention:5`/`worker_timeout_ms:30000` are overridden by project config; a client omitting the arg on a `snapshot_retention:0` project gets 0. Drop the `default` keywords, document the coupling. *Confidence: High.*
- **Empty `budgets: {}` rejected with a type error** (`ToolService.php:773-776`) — `json_decode('{}',true)` is `[]`, `array_is_list([])` is true → "budgets must be an object". Treat `[]` as empty object or add `minProperties:1`. *Confidence: High.*
- **Raw internal exception messages relayed to clients** (`StdioServer.php:139-145`) — `KNOSSOS_TOOL_ERROR` fallback returns `$error->getMessage()` (SQL state, absolute paths). Log internally, return a generic message. *Confidence: High.*
- **`initialize` as a notification returns 200 `[]`** (`HttpEndpoint.php:94-98`) — should be 202 with no body. *Confidence: High.*
- **HTTP sessions expire on a fixed clock, not idle** (`HttpSessionStore.php:59-98`) — contradicts the threat model's "idle expiry"; a >30-min active session gets a mid-conversation 404. Slide expiry per request or fix the docs. *Confidence: High.*
- **`-32002` reused for server-not-initialized and resource-not-found** (`StdioServer.php:112,159`) — forces clients to string-match. Use a distinct code for the lifecycle error. *Confidence: Medium.*
- **`check-architecture` exits 0 even with violations** (`QueryCommand.php:183-189,288-292`) — the one command named "check" can't gate CI on its own result. Return 1 on violations, mirroring `quality-gate`. *Confidence: High.*
- **Unknown CLI options silently ignored; `--flag=false` still enables** (`CliOptionParser.php:16-27`) — typos apply defaults; `--execute=false` on `remove-project` would execute. A literal `--` also throws, so `--`-prefixed positional file args are inexpressible. Validate against an allowlist; treat `--` as end-of-options. *Confidence: High.*
- **Router creates + migrates the DB before validating the command; default DB path is cwd-relative** (`CliCommandRouter.php:49-51`, `RuntimeFactory.php:29-37`) — typo'd commands mkdir `.knossos` and run migrations; `getcwd()===false` attempts `mkdir('/.knossos')`; running a query from another dir silently creates an empty DB and reports "not found". Route first, open lazily. *Confidence: High.*
- **One stale allowed-root blocks resolution against all later roots** (`RootGuard.php:20-28`) — `--allow-root=/deleted --allow-root=/valid` fails every scan. Skip nonexistent roots (guard itself is sound). *Confidence: High.*
- **TestModuleRule tags any `spec/`/`test/` segment as test code** (`TestModuleRule.php:21,50-57`) — `src/openapi/spec/PetStore.php` becomes a test module, excluded from dead-code candidacy. Anchor the segment or lower confidence. *Confidence: Medium.*
- **Duplicate explicit boundary names silently overwrite; both-matcher entries silently drop the path matcher** (`BoundaryInference.php:80`, `ProjectConfigurationLoader.php:69-85`) — reject duplicate names / entries declaring both. *Confidence: High.*
- **`inheritedMethodContext` inspects only direct parents while claiming ancestor coverage** (`GraphTopologyQueryService.php:788-884`) — a method overriding a grandparent's is reported as a `probable` dead-code candidate. Walk the extends/implements closure. *Confidence: High.*
- **Results exactly equal to `limit` mislabeled `truncated`** (`impactAnalysis`/`explainFlow`/`checkArchitecture`) — the in-PHP collectors don't use the `limit+1` pattern every SQL path uses. Collect `limit+1`, slice. *Confidence: High.*
- **`explainFlow` overwrites time/visit-limit truncation reason with `path_limit`** (`GraphTopologyQueryService.php:494-497`) — a timed-out search reports only trimming. Make the reason a list. *Confidence: High.*
- **`searchArchitecture` pagination lacks a unique tiebreaker** (`ComponentQueryService.php:220`) — `ORDER BY rank, canonical_name` ties across kinds; OFFSET paging can skip/duplicate rows. Append `, n.id`. *Confidence: High.*
- **`impactAnalysis` `path_confidence` reflects discovery order, not the best path** (`GraphTopologyQueryService.php:566-594`) — a dependant can be bucketed 'possible' though a 'certain' same-length chain exists. Track max confidence per distance or document it. *Confidence: High.*
- **quality-gate metrics disagree with the tools they mirror** (`ProjectCatalogQueryService.php:536-552`) — counts self-loops as cycles (dependency_cycles excludes them) and counts every contained member as 'unreferenced' (no health-style filtering), inflating that budget by orders of magnitude. Reuse the same policies. *Confidence: High.*
- **`architectureContext` truncated flag detected by substring-matching serialized JSON** (`ArchitectureContextService.php:89-90`) — a scanned attribute containing `"status":"truncated"` flips the flag. Track truncation structurally. *Confidence: Medium.*
- **StalenessProbe reports 'fresh' when it couldn't check** (`StalenessProbe.php:39,111-133`) — returns null (root missing or >500 files) yet reports 'fresh'; also never sees added/deleted files. Add an 'unverified' state. *Confidence: High.*
- **`explainFlow` cannot find self-flows (from == to)** (`GraphTopologyQueryService.php:446,465`) — the source is pre-seeded into `seen`, so a self-loop query always returns "no flow". *Confidence: High.*
- **TS anonymous declarations keyed by line only** (`scanner.js:786-797`) — minified single-line bundles collide all anonymous entities into one node. Add `:character`. Same class of bug: PHP `LaravelTraversalContext.php:48-51` gives every anonymous class the id `php:class:{anonymous}`, dangling vs the core collector. *Confidence: High.*
- **PHP variable-type tracking never invalidates on reassignment, yet emits `calls` edges at confidence `certain`** (`FactCollector.php:231-285`) — stale `$x` type after `$x = other()` produces wrong high-confidence edges; closures write into the enclosing scope. Clear on non-`new` assignment; label flow-inferred edges `probable`. *Confidence: High.*
- **TS edge dedup key ignores attributes** (`fact-accumulator.js:43-58`) — a class in both `controllers` and `providers` of a Nest `@Module` keeps only the first; `dynamic:true` import metadata lost. Merge attributes on duplicate keys. *Confidence: High.*
- **TS discover/scan walk crashes on unreadable directories** (`scanner.js:998-1007`) — one EACCES dir fails the whole discover/scan (Python's `os.walk` skips). try/catch around `readdirSync`. *Confidence: High.*
- **Python `mod.py` vs `mod/__init__.py` collide on module id** (`worker.py:66-71,596-603`) — later-parsed wins; both emit `py:module:mod`. Detect and prefer the package. *Confidence: High.*
- **PHP worker attributes error responses to the previous request's id** (`WorkerServer.php:17-31`) — `$request` isn't reset per iteration, so a malformed-JSON error carries iteration N-1's id (Python/JS reset correctly). `unset($request)` per loop. *Confidence: High.*
- **`GraphBundleService::export` reads 7 tables without a wrapping read transaction** (`GraphBundleService.php:21-38`) — a reconcile commit mid-export produces a torn, self-checksummed bundle that fails on import. Wrap in a deferred read transaction. *Confidence: High.*
- **Bundle importer accepts `parent_id` cycles including self-parenting** (`PortableGraphImporter.php:53-68`) — latent infinite loop for any future recursive parent traversal. Verify acyclicity before applying. *Confidence: High.*
- **`transaction()` nesting is a silent no-op without savepoints** (`SqliteGraphRepository.php:20-24`) — a caught nested failure leaves partial writes in the outer transaction. Use SAVEPOINT or document the hazard. *Confidence: High.*
- **Bundle import duplicate check is a TOCTOU outside the transaction** (`GraphBundleService.php:98-105`) — concurrent imports surface a raw UNIQUE violation instead of the clean "already imported" error. Move the check inside `BEGIN IMMEDIATE`. *Confidence: High.*
- **MigrationRunner records no-transaction migrations outside any transaction** (`MigrationRunner.php:63-79`) — a crash between the migration COMMIT and the bookkeeping insert re-runs the heavy rebuild (idempotent today by accident). Record the version inside the migration's own transaction. *Confidence: High.*
- **`deleteFactsByOwner` is dead and, as written, cascade-unsafe** (`SqliteGraphRepository.php:481-491`) — would FK-cascade *other owners'* edges and misses boundaries; fix before wiring it into incremental reconcile. *Confidence: High.*
- **StableId merges same-named symbols; the conflict guard is unreachable** (`GraphReconciler.php:196-212`, `StableId.php:28-41`) — two same-language/kind/canonical-name declarations collapse to one node with order-dependent evidence; the `throw` can never fire. Emit a warning diagnostic on re-declaration. *Confidence: High.*
- **`cleanupStaleScans` targets states that never persist** (`DatabaseMaintenanceService.php:52-113`) — `running` rows roll back on crash and nothing ever writes `failed`/`cancelled`, so it always removes 0. Either drop it or start persisting failed scans. *Confidence: High.*
- **Hardcoded README badges assert "tests passing"/"coverage 91%"** (`README.md:9-10`) — static, unenforced, and the number is wrong (real floor 92.5). Point at workflow status or cross-check in `documentation-check.php`. *Confidence: High.*
- **`phpunit.xml` suite names/comment are stale** (`phpunit.xml:2-24`) — `--testsuite default` silently skips every MCP transport/concurrency/HTTP test; the "unit" suite actually holds the most integration-flavored tests. Collapse or rename the suites. *Confidence: High.*
- **Timing-based tests can flake** — `FaultInjectionTest.php:93-96` bounds child-reap at 500 ms (raise to a multi-second wall-clock bound). *Confidence: Medium.*

---

## Final Summary

**Total issues by severity:** 11 Critical/High (0 Critical, 11 High) · 34 Medium · ~50 Low.

**Most dangerous logic flaw:** the `quality_gate` boundary-violation undercount (High). A gate that reports "passed" while 5,000 violations exist above a budget of 150 is worse than no gate — it manufactures false confidence in exactly the CI path meant to catch regressions. The TS canonical-name divergence is a close second for silent graph incorrectness.

**Highest-risk architectural issue:** reconciliation is not actually incremental (High). The whole graph is torn down and rebuilt on every scan, `deleteFactsByOwner` (the incremental primitive) is unused dead code, and the missing FK index turns that teardown quadratic. The architecture advertises incremental scanning it does not implement.

**Biggest maintainability problem:** the persistence/reconciliation layer conflates "incremental" and "full" while carrying a dead incremental primitive and a migration that silently dropped an index — a trap for the next person who tries to make incremental scans fast without realizing the fast path was never wired.

**Largest performance opportunity:** add the `nodes(parent_id)` and `nodes(file_id)` indexes — a two-line migration that empirically cuts the reconciliation delete from 27.2 s to 0.23 s (~99%), immediately followed by making reconciliation delta-sized rather than project-sized.

**Biggest security concern:** defensive-depth, not a breach — scanner subprocesses inherit the full server environment (secrets, DB creds) while parsing untrusted source, and the default HTTP deployment has no bearer token and an unbounded session-creation path. Neither is a direct compromise, but together they are the softest part of the attack surface.

**Estimated overall code quality:** 7.5/10 — clean separation, disciplined SQL and path handling, honest confidence labeling, strong protocol value objects; held back by the incremental-reconciliation gap and a band of silent-undercount query bugs.

**Estimated production readiness:** 5.5/10 — the performance cliff, the false-passing quality gate, and the worker-crash/UTF-8 batch-abort modes need fixing before this is trustworthy on large or untrusted repos.

**Estimated technical debt:** 4/10 — moderate and well-localized; most findings are surgical, and the test/CI scaffolding (once the root-container and mutation gaps are closed) is above average.

### Single highest-impact improvement

**Add the missing `nodes(parent_id)` and `nodes(file_id)` indexes, then make reconciliation delta-sized.** It ranks first because it is the only finding that is simultaneously (a) the confirmed root cause of the headline symptom (the 30 s stage), (b) empirically proven (27.2 s → 0.23 s), (c) a two-line migration for the immediate 99% win, and (d) the gateway to the larger architectural fix (true incremental reconciliation) that everything else in the persistence layer is quietly waiting on. No other change buys as much correctness-adjacent reliability per line. Fix the index this week; schedule the incremental-reconcile rework next.
