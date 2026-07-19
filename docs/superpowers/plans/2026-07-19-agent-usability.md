# Agent Usability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Knossos ergonomic for coding agents by making every MCP tool self-explaining (intent-first descriptions), self-guiding on missing/stale graphs (structured `staleness`), and agent-shaped in output (`next_steps`, compact-by-default evidence, byte honesty) — without a front-door tool and without changing the read-only contract.

**Architecture:** All 28 tools return a single `ResultEnvelope` rendered through one `jsonSerialize()`. We extend that envelope with three optional fields and add a thin **enrichment layer** at the `ToolService::call()` boundary that (a) attaches a `staleness` block computed from the `scans`/`files` tables, (b) attaches up to 3 `next_steps` computed per-tool from the result, (c) compacts `evidence` when `verbosity=compact` (the default), and (d) reports `meta.result_bytes`. Query services are left untouched; enrichment is pure/centralized and independently testable.

**Tech Stack:** PHP 8.4 (`final readonly` classes, `declare(strict_types=1)`), SQLite via PDO, custom test harness `tests/run.php` (run with `composer test`), reference generator `tools/generate-reference.php`.

## Global Constraints

- PHP files start with `<?php`, then `declare(strict_types=1);`, then `namespace Knossos\...;` — copy the style of neighboring files exactly.
- Namespaces: query-layer classes are `Knossos\Query\...`; MCP-layer classes are `Knossos\Mcp\...`.
- Tests live in `tests/run.php` as `$tests['<description>'] = static function (): void { ... };`. Available assertions: `assertSame($expected, $actual)`, `assertContains($needle, $haystack)`, `assertThrows(callable, Class::class)`, `captureThrows(callable, Class::class)`. There is NO PHPUnit.
- Run the whole suite with `composer test` (which is `php tests/run.php`). There is no single-test runner; run the full suite each time.
- `docs/MCP-REFERENCE.md` and `docs/CLI-REFERENCE.md` are GENERATED. Never hand-edit them. Regenerate with `php tools/generate-reference.php` and verify with `php tools/generate-reference.php --check` (exit 0 = in sync).
- `verbosity` is a string enum `"compact" | "full"`, default `"compact"`.
- `next_steps` is capped at **3** entries. Each entry is `{ "tool": string, "args": object, "why": string }`.
- Never trigger a scan from a query/read tool. Staleness only *reports*; it never *acts*.
- `changed_files_since` is best-effort and bounded (probe at most 500 files); omit the field entirely if the project root is unavailable or the bound is exceeded.

---

## File Structure

- `src/Query/ResultEnvelope.php` — MODIFY: add optional `staleness`, `nextSteps`, `meta` fields + a `with()` cloner + conditional serialization. Single source of the wire shape.
- `src/Query/StalenessProbe.php` — CREATE: computes the `staleness` block for a project id from the DB + filesystem. Read-only.
- `src/Query/ArchitectureQueryService.php` — MODIFY: expose `staleness(string $projectId): ?array` and accept an optional wall clock. Facade already holds the PDO.
- `src/Mcp/NextStepPlanner.php` — CREATE: pure mapper `(toolName, envelope) -> list<step>` (cap 3).
- `src/Mcp/ResultEnricher.php` — CREATE: composes staleness + next_steps + verbosity compaction + byte metering into an enriched envelope.
- `src/Mcp/ToolService.php` — MODIFY: wire the enricher into `call()`, parse/strip the generic `verbosity` argument, add the `verbosity` input to read-tool schemas, and rewrite all 28 descriptions intent-first.
- `docs/MCP-REFERENCE.md` — REGENERATED (not hand-edited).
- `tests/run.php` — MODIFY: append test closures per task.

---

## Task 1: Extend ResultEnvelope with enrichment fields

**Files:**
- Modify: `src/Query/ResultEnvelope.php`
- Test: `tests/run.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `ResultEnvelope` gains constructor params `?array $staleness = null`, `array $nextSteps = []`, `?array $meta = null` (all trailing/optional, so every existing `new ResultEnvelope(...)` call still compiles). Adds `public function with(?array $staleness = null, ?array $nextSteps = null, ?array $meta = null): self` returning a clone with the given enrichment overrides (null = keep existing). `jsonSerialize()` includes `staleness`/`next_steps`/`meta` keys ONLY when set (non-null / non-empty for next_steps).

- [ ] **Step 1: Write the failing test**

Append to `tests/run.php`:

```php
$tests['ResultEnvelope omits enrichment keys until set'] = static function (): void {
    $envelope = new ResultEnvelope('p1', 's1', 'sum', ['k' => 'v']);
    $json = $envelope->jsonSerialize();
    assertSame('sum', $json['summary']);
    assertSame(false, array_key_exists('staleness', $json));
    assertSame(false, array_key_exists('next_steps', $json));
    assertSame(false, array_key_exists('meta', $json));
};

$tests['ResultEnvelope with() attaches enrichment'] = static function (): void {
    $base = new ResultEnvelope('p1', 's1', 'sum', ['k' => 'v'], [['file' => 'a.php']]);
    $enriched = $base->with(
        staleness: ['state' => 'fresh', 'scanned_at' => '2026-07-19T00:00:00Z', 'age_seconds' => 10],
        nextSteps: [['tool' => 'inspect_component', 'args' => ['component' => 'X'], 'why' => 'drill in']],
        meta: ['result_bytes' => 123, 'verbosity' => 'compact'],
    );
    $json = $enriched->jsonSerialize();
    assertSame('fresh', $json['staleness']['state']);
    assertSame('inspect_component', $json['next_steps'][0]['tool']);
    assertSame(123, $json['meta']['result_bytes']);
    // original is unchanged (readonly clone)
    assertSame(false, array_key_exists('staleness', $base->jsonSerialize()));
};
```

- [ ] **Step 2: Run the suite to verify the new tests fail**

Run: `composer test`
Expected: FAIL — `with()` does not exist / `staleness` key missing (fatal or assertion failure on the two new tests).

- [ ] **Step 3: Implement the fields and cloner**

Replace the body of `src/Query/ResultEnvelope.php` with:

```php
<?php

declare(strict_types=1);

namespace Knossos\Query;

use JsonSerializable;

final readonly class ResultEnvelope implements JsonSerializable
{
    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $evidence
     * @param list<string> $warnings
     * @param array<string, mixed>|null $staleness
     * @param list<array<string, mixed>> $nextSteps
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public string $projectId,
        public string $snapshotId,
        public string $summary,
        public array $data,
        public array $evidence = [],
        public array $warnings = [],
        public bool $truncated = false,
        public ?array $staleness = null,
        public array $nextSteps = [],
        public ?array $meta = null,
    ) {}

    /**
     * @param array<string, mixed>|null $staleness
     * @param list<array<string, mixed>>|null $nextSteps
     * @param array<string, mixed>|null $meta
     */
    public function with(?array $staleness = null, ?array $nextSteps = null, ?array $meta = null): self
    {
        return new self(
            $this->projectId,
            $this->snapshotId,
            $this->summary,
            $this->data,
            $this->evidence,
            $this->warnings,
            $this->truncated,
            $staleness ?? $this->staleness,
            $nextSteps ?? $this->nextSteps,
            $meta ?? $this->meta,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $out = [
            'project_id' => $this->projectId,
            'snapshot_id' => $this->snapshotId,
            'summary' => $this->summary,
            'data' => $this->data,
            'evidence' => $this->evidence,
            'warnings' => $this->warnings,
            'truncated' => $this->truncated,
        ];
        if ($this->staleness !== null) {
            $out['staleness'] = $this->staleness;
        }
        if ($this->nextSteps !== []) {
            $out['next_steps'] = $this->nextSteps;
        }
        if ($this->meta !== null) {
            $out['meta'] = $this->meta;
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run the suite to verify it passes**

Run: `composer test`
Expected: PASS (all tests, including the two new ones).

- [ ] **Step 5: Commit**

```bash
git add src/Query/ResultEnvelope.php tests/run.php
git commit -m "feat(envelope): add optional staleness, next_steps, meta enrichment fields"
```

---

## Task 2: StalenessProbe — read-only freshness of a project

**Files:**
- Create: `src/Query/StalenessProbe.php`
- Test: `tests/run.php`

**Interfaces:**
- Consumes: a `PDO` handle and a wall clock `Closure(): int` returning epoch seconds.
- Produces: `final readonly class Knossos\Query\StalenessProbe` with:
  - `__construct(PDO $pdo, ?Closure $wallClock = null)` — defaults to `static fn(): int => time()`.
  - `probe(string $projectId): ?array` — returns `null` when staleness is not meaningful (empty/`'catalog'` project id, or unknown project). Otherwise returns
    `['state' => 'missing'|'stale'|'fresh', 'scanned_at' => ?string, 'age_seconds' => ?int, 'changed_files_since' => ?int (only if determinable), 'guidance' => ?string (only for missing/stale)]`.

Rules:
- Unknown project OR project row exists but `active_scan_id` is null/empty → `state='missing'`, `scanned_at=null`, `age_seconds=null`, `guidance='No active graph for this project; call scan_project first.'`
- Active scan exists → `scanned_at = active scan.finished_at`; `age_seconds = max(0, wallClock() - strtotime(finished_at))` (or `null` if `finished_at` is null/unparseable).
- `stale` if EITHER: the project's newest scan row differs from the active scan and its status is one of `running|failed|cancelled` (a newer attempt exists), OR `changed_files_since > 0`.
- `changed_files_since`: fetch up to 500 rows `relative_path, mtime` from `files` for the active scan; if `root_realpath` is not a directory, OMIT the key. Otherwise count files whose `@filemtime(root . '/' . relative_path)` is `> mtime`. If more than 500 files exist, OMIT the key (bound exceeded) but still allow the newer-scan signal to drive `stale`.
- `fresh` otherwise; no `guidance` key.
- `guidance` for `stale`: `'Graph may be stale; rescan with scan_project for current results.'`

- [ ] **Step 1: Write the failing test**

Append to `tests/run.php`. This reuses the existing fixture-scan helper pattern already used elsewhere in the file (scan a temp copy of a fixture, then probe). Use the small `php-scanner` fixture.

```php
$tests['StalenessProbe reports missing for unknown or unscanned projects'] = static function (): void {
    $pdo = freshTestDatabase(); // helper defined below if absent
    $probe = new \Knossos\Query\StalenessProbe($pdo, static fn(): int => 1_000_000);
    assertSame(null, $probe->probe(''));
    assertSame(null, $probe->probe('catalog'));
    $missing = $probe->probe('project_does_not_exist');
    assertSame('missing', $missing['state']);
    assertContains('scan_project', $missing['guidance']);
};

$tests['StalenessProbe reports fresh then stale after a file changes'] = static function (): void {
    [$pdo, $projectId, $root] = scanTempFixture('php-scanner'); // helper defined below if absent
    // finished_at is set at scan time; use a wall clock a few seconds later.
    $probe = new \Knossos\Query\StalenessProbe($pdo, static fn(): int => time() + 5);
    $fresh = $probe->probe($projectId);
    assertSame('fresh', $fresh['state']);
    assertSame(true, is_int($fresh['age_seconds']));
    assertSame(0, $fresh['changed_files_since']);

    // Touch a scanned file into the future so its mtime beats the stored mtime.
    $target = $root . '/src/Architecture.php';
    touch($target, time() + 3600);
    clearstatcache();
    $stale = (new \Knossos\Query\StalenessProbe($pdo, static fn(): int => time() + 5))->probe($projectId);
    assertSame('stale', $stale['state']);
    assertContains('rescan', $stale['guidance']);
};
```

If `freshTestDatabase()` / `scanTempFixture()` helpers do not already exist in `tests/run.php`, add them near the top of the file (after `$tests = [];`), modeled on the existing scan setup used around the other `new ArchitectureQueryService($pdo)` tests:

```php
function freshTestDatabase(): PDO {
    $pdo = SqliteConnection::open(':memory:');
    (new MigrationRunner($pdo))->migrate();
    return $pdo;
}

/** @return array{0: PDO, 1: string, 2: string} [pdo, projectId, absoluteRoot] */
function scanTempFixture(string $fixture): array {
    $src = dirname(__DIR__) . '/tests/Fixtures/' . $fixture;
    $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
    // Recursively copy the fixture so mtimes can be mutated safely.
    copyTree($src, $root);
    $pdo = freshTestDatabase();
    $service = buildScanService($pdo); // existing helper used elsewhere in run.php
    $result = $service->scan($root); // returns something exposing projectId; adapt to existing API
    return [$pdo, $result->projectId, $root];
}

function copyTree(string $from, string $to): void {
    mkdir($to, 0777, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS)) as $item) {
        $rel = substr($item->getPathname(), strlen($from) + 1);
        $dest = $to . '/' . $rel;
        if ($item->isDir()) { @mkdir($dest, 0777, true); continue; }
        @mkdir(dirname($dest), 0777, true);
        copy($item->getPathname(), $dest);
    }
}
```

NOTE for the implementer: `tests/run.php` already scans fixtures for other tests — before adding `buildScanService`/`scanTempFixture`, grep the file for the existing scan-setup closure (search `ProjectScanService` / `->scan(`) and reuse that exact wiring instead of inventing a new one. Match the real `scan()` return type when reading `projectId`.

- [ ] **Step 2: Run the suite to verify the new tests fail**

Run: `composer test`
Expected: FAIL — class `Knossos\Query\StalenessProbe` not found.

- [ ] **Step 3: Implement StalenessProbe**

Create `src/Query/StalenessProbe.php`:

```php
<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use PDO;

final readonly class StalenessProbe
{
    private Closure $wallClock;

    public function __construct(private PDO $pdo, ?Closure $wallClock = null)
    {
        $this->wallClock = $wallClock ?? static fn(): int => time();
    }

    /** @return array<string, mixed>|null */
    public function probe(string $projectId): ?array
    {
        if ($projectId === '' || $projectId === 'catalog') {
            return null;
        }
        $project = $this->fetchProject($projectId);
        if ($project === null) {
            return $this->missing();
        }
        $activeScanId = $project['active_scan_id'];
        if (!is_string($activeScanId) || $activeScanId === '') {
            return $this->missing();
        }

        $finishedAt = $this->activeFinishedAt($activeScanId);
        $ageSeconds = $this->age($finishedAt);
        $newerAttempt = $this->hasNewerAttempt($projectId, $activeScanId);
        $changed = $this->changedFilesSince($projectId, $activeScanId, (string) $project['root_realpath']);

        $isStale = $newerAttempt || ($changed !== null && $changed > 0);
        $result = [
            'state' => $isStale ? 'stale' : 'fresh',
            'scanned_at' => $finishedAt,
            'age_seconds' => $ageSeconds,
        ];
        if ($changed !== null) {
            $result['changed_files_since'] = $changed;
        }
        if ($isStale) {
            $result['guidance'] = 'Graph may be stale; rescan with scan_project for current results.';
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function missing(): array
    {
        return [
            'state' => 'missing',
            'scanned_at' => null,
            'age_seconds' => null,
            'guidance' => 'No active graph for this project; call scan_project first.',
        ];
    }

    /** @return array<string, mixed>|null */
    private function fetchProject(string $projectId): ?array
    {
        $statement = $this->pdo->prepare('SELECT active_scan_id, root_realpath FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    private function activeFinishedAt(string $scanId): ?string
    {
        $statement = $this->pdo->prepare('SELECT finished_at FROM scans WHERE id = :id');
        $statement->execute(['id' => $scanId]);
        $row = $statement->fetch();
        if ($row === false || !is_string($row['finished_at'])) {
            return null;
        }
        return $row['finished_at'];
    }

    private function age(?string $finishedAt): ?int
    {
        if ($finishedAt === null) {
            return null;
        }
        $then = strtotime($finishedAt);
        if ($then === false) {
            return null;
        }
        return max(0, ($this->wallClock)() - $then);
    }

    private function hasNewerAttempt(string $projectId, string $activeScanId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status FROM scans WHERE project_id = :project ORDER BY started_at DESC, id DESC LIMIT 1',
        );
        $statement->execute(['project' => $projectId]);
        $latest = $statement->fetch();
        if ($latest === false) {
            return false;
        }
        return $latest['id'] !== $activeScanId
            && in_array($latest['status'], ['running', 'failed', 'cancelled'], true);
    }

    private function changedFilesSince(string $projectId, string $activeScanId, string $root): ?int
    {
        if (!is_dir($root)) {
            return null;
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM files WHERE project_id = :project AND last_scan_id = :scan');
        $count->execute(['project' => $projectId, 'scan' => $activeScanId]);
        if ((int) $count->fetchColumn() > 500) {
            return null; // bound exceeded; omit best-effort field
        }
        $statement = $this->pdo->prepare(
            'SELECT relative_path, mtime FROM files WHERE project_id = :project AND last_scan_id = :scan LIMIT 500',
        );
        $statement->execute(['project' => $projectId, 'scan' => $activeScanId]);
        $changed = 0;
        foreach ($statement->fetchAll() as $file) {
            $current = @filemtime($root . '/' . $file['relative_path']);
            if ($current !== false && $current > (int) $file['mtime']) {
                ++$changed;
            }
        }
        return $changed;
    }
}
```

- [ ] **Step 4: Run the suite to verify it passes**

Run: `composer test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Query/StalenessProbe.php tests/run.php
git commit -m "feat(query): add read-only StalenessProbe for project freshness"
```

---

## Task 3: NextStepPlanner — per-tool follow-up suggestions

**Files:**
- Create: `src/Mcp/NextStepPlanner.php`
- Test: `tests/run.php`

**Interfaces:**
- Consumes: `Knossos\Query\ResultEnvelope`.
- Produces: `final readonly class Knossos\Mcp\NextStepPlanner` with `plan(string $toolName, ResultEnvelope $envelope): array` returning a `list<array{tool:string,args:array,why:string}>` of at most 3 entries. Pure; no I/O. Unknown tools or empty results return `[]`.

Concrete rules (data keys below reference the envelope's `data`; the implementer must confirm the exact key names by reading the corresponding query method and adjust — the rule intent is fixed, the literal key is what to verify):
- `find_component`: if `data['candidates']` has >= 2 entries, suggest `inspect_component` on the top candidate's name — why: `'multiple matches; inspect the top candidate'`.
- `inspect_component`: always suggest `impact_analysis` with `symbol` = the inspected component — why: `'see what depends on this before changing it'`. If the dossier marks it a hub/hotspot, additionally suggest `dependency_cycles` — why: `'this is a hub; check for dependency cycles'`.
- `impact_analysis`: if `data['impacted']` is non-empty, suggest `explain_flow` from the analyzed `symbol` to the first impacted component — why: `'trace how the change reaches an affected component'`.
- `architecture_health`: if a hotspot/hub is present, suggest `inspect_component` on the top one — why: `'inspect the top structural hotspot'`.
- All others: `[]`.
- Always slice the final list to 3.

- [ ] **Step 1: Write the failing test**

Append to `tests/run.php`:

```php
$tests['NextStepPlanner suggests inspecting the top candidate on ambiguous find'] = static function (): void {
    $envelope = new ResultEnvelope('p1', 's1', 'Found 2 candidates.', [
        'candidates' => [
            ['name' => 'App\\Checkout', 'score' => 0.9],
            ['name' => 'App\\CheckoutController', 'score' => 0.7],
        ],
    ]);
    $steps = (new \Knossos\Mcp\NextStepPlanner())->plan('find_component', $envelope);
    assertSame('inspect_component', $steps[0]['tool']);
    assertSame('App\\Checkout', $steps[0]['args']['component']);
};

$tests['NextStepPlanner suggests impact analysis after inspect'] = static function (): void {
    $envelope = new ResultEnvelope('p1', 's1', 'Dossier.', ['component' => 'App\\Checkout']);
    $steps = (new \Knossos\Mcp\NextStepPlanner())->plan('inspect_component', $envelope);
    assertSame('impact_analysis', $steps[0]['tool']);
    assertSame('App\\Checkout', $steps[0]['args']['symbol']);
};

$tests['NextStepPlanner caps at three and defaults to empty'] = static function (): void {
    $empty = (new \Knossos\Mcp\NextStepPlanner())->plan('architecture_summary', new ResultEnvelope('p', 's', 'x', []));
    assertSame([], $empty);
};
```

- [ ] **Step 2: Run the suite to verify the new tests fail**

Run: `composer test`
Expected: FAIL — class `Knossos\Mcp\NextStepPlanner` not found.

- [ ] **Step 3: Implement NextStepPlanner**

Create `src/Mcp/NextStepPlanner.php`. The implementer MUST first read `src/Query/ComponentQueryService.php` (inspect/find) and `src/Query/GraphTopologyQueryService.php` (impact/health) to confirm the exact `data` key names used below (`candidates`, `component`, `impacted`, hotspot/hub key) and correct them if they differ:

```php
<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Knossos\Query\ResultEnvelope;

final readonly class NextStepPlanner
{
    /** @return list<array{tool: string, args: array<string, mixed>, why: string}> */
    public function plan(string $toolName, ResultEnvelope $envelope): array
    {
        $data = $envelope->data;
        $steps = match ($toolName) {
            'find_component' => $this->afterFind($data),
            'inspect_component' => $this->afterInspect($data),
            'impact_analysis' => $this->afterImpact($data),
            'architecture_health' => $this->afterHealth($data),
            default => [],
        };
        return array_slice($steps, 0, 3);
    }

    /** @param array<string, mixed> $data @return list<array{tool: string, args: array<string, mixed>, why: string}> */
    private function afterFind(array $data): array
    {
        $candidates = is_array($data['candidates'] ?? null) ? $data['candidates'] : [];
        if (count($candidates) < 2) {
            return [];
        }
        $top = $candidates[0];
        $name = is_array($top) ? ($top['name'] ?? null) : null;
        if (!is_string($name)) {
            return [];
        }
        return [[
            'tool' => 'inspect_component',
            'args' => ['component' => $name],
            'why' => 'multiple matches; inspect the top candidate',
        ]];
    }

    /** @param array<string, mixed> $data @return list<array{tool: string, args: array<string, mixed>, why: string}> */
    private function afterInspect(array $data): array
    {
        $component = $data['component'] ?? null;
        if (!is_string($component) || $component === '') {
            return [];
        }
        $steps = [[
            'tool' => 'impact_analysis',
            'args' => ['symbol' => $component],
            'why' => 'see what depends on this before changing it',
        ]];
        if (($data['is_hub'] ?? false) === true) {
            $steps[] = [
                'tool' => 'dependency_cycles',
                'args' => [],
                'why' => 'this is a hub; check for dependency cycles',
            ];
        }
        return $steps;
    }

    /** @param array<string, mixed> $data @return list<array{tool: string, args: array<string, mixed>, why: string}> */
    private function afterImpact(array $data): array
    {
        $impacted = is_array($data['impacted'] ?? null) ? $data['impacted'] : [];
        $symbol = $data['symbol'] ?? null;
        if ($impacted === [] || !is_string($symbol)) {
            return [];
        }
        $first = $impacted[0];
        $to = is_array($first) ? ($first['name'] ?? null) : (is_string($first) ? $first : null);
        if (!is_string($to)) {
            return [];
        }
        return [[
            'tool' => 'explain_flow',
            'args' => ['from' => $symbol, 'to' => $to],
            'why' => 'trace how the change reaches an affected component',
        ]];
    }

    /** @param array<string, mixed> $data @return list<array{tool: string, args: array<string, mixed>, why: string}> */
    private function afterHealth(array $data): array
    {
        $hotspots = is_array($data['hotspots'] ?? null) ? $data['hotspots'] : [];
        if ($hotspots === []) {
            return [];
        }
        $top = $hotspots[0];
        $name = is_array($top) ? ($top['name'] ?? null) : null;
        if (!is_string($name)) {
            return [];
        }
        return [[
            'tool' => 'inspect_component',
            'args' => ['component' => $name],
            'why' => 'inspect the top structural hotspot',
        ]];
    }
}
```

- [ ] **Step 4: Run the suite to verify it passes**

Run: `composer test`
Expected: PASS. (If a key name differs from the real query output, the test for that tool will fail with an empty result — fix the key in the planner, not the test's intent.)

- [ ] **Step 5: Commit**

```bash
git add src/Mcp/NextStepPlanner.php tests/run.php
git commit -m "feat(mcp): add NextStepPlanner for per-tool follow-up suggestions"
```

---

## Task 4: ResultEnricher — compose staleness, next_steps, verbosity, meta

**Files:**
- Create: `src/Mcp/ResultEnricher.php`
- Test: `tests/run.php`

**Interfaces:**
- Consumes: `Knossos\Query\StalenessProbe`, `Knossos\Mcp\NextStepPlanner`, `Knossos\Query\ResultEnvelope`.
- Produces: `final readonly class Knossos\Mcp\ResultEnricher` with:
  - `__construct(StalenessProbe $probe, NextStepPlanner $planner)`.
  - `enrich(ResultEnvelope $envelope, string $toolName, string $verbosity): ResultEnvelope` — returns a new envelope with `staleness` (from probe on `envelope->projectId`), `nextSteps` (from planner), compacted `evidence` when `verbosity==='compact'`, and `meta`. `meta` = `['result_bytes' => int, 'verbosity' => $verbosity, 'evidence_total' => int, 'evidence_shown' => int]`.
- Verbosity compaction: `compact` keeps at most the first 3 evidence items; `full` keeps all. `result_bytes` = byte length of `json_encode` of the enriched envelope's serialization computed WITHOUT the `meta` key (documented as payload size, avoids self-reference).

Because `ResultEnvelope` is readonly, compaction produces a fresh base envelope (evidence differs) before `with()` is applied. Add a private helper that rebuilds the envelope with trimmed evidence.

- [ ] **Step 1: Write the failing test**

Append to `tests/run.php`:

```php
$tests['ResultEnricher compacts evidence and reports meta by default'] = static function (): void {
    $pdo = freshTestDatabase();
    $enricher = new \Knossos\Mcp\ResultEnricher(
        new \Knossos\Query\StalenessProbe($pdo, static fn(): int => 1_000_000),
        new \Knossos\Mcp\NextStepPlanner(),
    );
    $evidence = array_map(static fn(int $i): array => ['file' => "f{$i}.php", 'line' => $i], range(1, 10));
    $raw = new ResultEnvelope('project_missing', 's1', 'Dossier.', ['component' => 'App\\X'], $evidence);
    $out = $enricher->enrich($raw, 'inspect_component', 'compact')->jsonSerialize();

    assertSame(3, count($out['evidence']));               // compacted to top 3
    assertSame('compact', $out['meta']['verbosity']);
    assertSame(10, $out['meta']['evidence_total']);
    assertSame(3, $out['meta']['evidence_shown']);
    assertSame(true, is_int($out['meta']['result_bytes']));
    assertSame('missing', $out['staleness']['state']);     // unknown project -> missing
    assertSame('impact_analysis', $out['next_steps'][0]['tool']);
};

$tests['ResultEnricher keeps all evidence in full verbosity'] = static function (): void {
    $pdo = freshTestDatabase();
    $enricher = new \Knossos\Mcp\ResultEnricher(
        new \Knossos\Query\StalenessProbe($pdo, static fn(): int => 1_000_000),
        new \Knossos\Mcp\NextStepPlanner(),
    );
    $evidence = array_map(static fn(int $i): array => ['file' => "f{$i}.php"], range(1, 10));
    $raw = new ResultEnvelope('project_missing', 's1', 'x', [], $evidence);
    $out = $enricher->enrich($raw, 'architecture_summary', 'full')->jsonSerialize();
    assertSame(10, count($out['evidence']));
    assertSame('full', $out['meta']['verbosity']);
    assertSame(false, array_key_exists('next_steps', $out)); // summary has no suggestions
};
```

- [ ] **Step 2: Run the suite to verify the new tests fail**

Run: `composer test`
Expected: FAIL — class `Knossos\Mcp\ResultEnricher` not found.

- [ ] **Step 3: Implement ResultEnricher**

Create `src/Mcp/ResultEnricher.php`:

```php
<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Knossos\Query\ResultEnvelope;
use Knossos\Query\StalenessProbe;

final readonly class ResultEnricher
{
    private const COMPACT_EVIDENCE = 3;

    public function __construct(
        private StalenessProbe $probe,
        private NextStepPlanner $planner,
    ) {}

    public function enrich(ResultEnvelope $envelope, string $toolName, string $verbosity): ResultEnvelope
    {
        $total = count($envelope->evidence);
        $base = $verbosity === 'compact'
            ? $this->compact($envelope)
            : $envelope;
        $shown = count($base->evidence);

        $staleness = $this->probe->probe($envelope->projectId);
        $steps = $this->planner->plan($toolName, $envelope);

        $withoutMeta = $base->with(staleness: $staleness, nextSteps: $steps);
        $resultBytes = strlen((string) json_encode($withoutMeta->jsonSerialize(), JSON_UNESCAPED_SLASHES));

        return $withoutMeta->with(meta: [
            'result_bytes' => $resultBytes,
            'verbosity' => $verbosity,
            'evidence_total' => $total,
            'evidence_shown' => $shown,
        ]);
    }

    private function compact(ResultEnvelope $envelope): ResultEnvelope
    {
        if (count($envelope->evidence) <= self::COMPACT_EVIDENCE) {
            return $envelope;
        }
        return new ResultEnvelope(
            $envelope->projectId,
            $envelope->snapshotId,
            $envelope->summary,
            $envelope->data,
            array_slice($envelope->evidence, 0, self::COMPACT_EVIDENCE),
            $envelope->warnings,
            $envelope->truncated,
            $envelope->staleness,
            $envelope->nextSteps,
            $envelope->meta,
        );
    }
}
```

Note: `with(nextSteps: [])` keeps existing (null-coalescing treats `[]` differently). Passing `$steps` — when `$steps === []`, `with()` receives `[]` which is not null, so it sets `nextSteps = []`; `jsonSerialize()` then omits the key. Correct. Verify the `with()` signature treats an explicit `[]` as "set to empty" (it does: `$nextSteps ?? $this->nextSteps` — `[]` is not null, so `[]` wins).

- [ ] **Step 4: Run the suite to verify it passes**

Run: `composer test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Mcp/ResultEnricher.php tests/run.php
git commit -m "feat(mcp): add ResultEnricher composing staleness, next_steps, verbosity, meta"
```

---

## Task 5: Wire enrichment + verbosity into ToolService::call and the query facade

**Files:**
- Modify: `src/Query/ArchitectureQueryService.php` (add `staleness()` + optional wall clock)
- Modify: `src/Mcp/ToolService.php` (construct enricher, parse/strip `verbosity`, enrich results)
- Test: `tests/run.php`

**Interfaces:**
- Consumes: `ResultEnricher`, `StalenessProbe`, `NextStepPlanner`, the existing `ArchitectureQueryService`.
- Produces:
  - `ArchitectureQueryService::__construct` gains a trailing optional param `?Closure $wallClock = null` and a public method `staleness(string $projectId): ?array` delegating to an internal `StalenessProbe`. Existing `new ArchitectureQueryService($pdo)` callers keep working (new param is optional/last).
  - `ToolService::call(string $name, array $arguments, ?CancellationToken $cancellation = null): ResultEnvelope` now: reads `verbosity` from `$arguments` (default `'compact'`, validate ∈ {compact,full} else throw `InvalidArgumentException`), removes it from `$arguments` before dispatch, and returns `$this->enricher->enrich($rawEnvelope, $name, $verbosity)`.
  - `ToolService::__construct` gains a trailing param `ResultEnricher $enricher`. Update the 3 production/test construction sites: `src/Cli/Command/ServeCommand.php:34`, `bin/http-router.php:43`, and each `new ToolService(` in `tests/run.php`.

The enricher's `StalenessProbe` and the facade's `staleness()` must share the same PDO the queries use. Simplest wiring: `ToolService` builds `new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner())` at the composition root and receives it as a constructor dependency. So the composition roots pass the `$pdo` they already have.

- [ ] **Step 1: Write the failing test**

Append to `tests/run.php`:

```php
$tests['ToolService rejects an invalid verbosity'] = static function (): void {
    [$tools] = buildToolServiceWithScan('php-scanner'); // helper: builds ToolService over a scanned fixture
    assertThrows(
        static fn() => $tools->call('architecture_summary', ['project_id' => 'x', 'verbosity' => 'loud']),
        InvalidArgumentException::class,
    );
};

$tests['ToolService enriches query results with staleness and meta'] = static function (): void {
    [$tools, $projectId] = buildToolServiceWithScan('php-scanner');
    $envelope = $tools->call('architecture_summary', ['project_id' => $projectId]);
    $json = $envelope->jsonSerialize();
    assertSame('compact', $json['meta']['verbosity']);
    assertContains($json['staleness']['state'], ['fresh', 'stale']); // scanned, so not missing
};
```

Add `buildToolServiceWithScan()` near the other helpers if absent, reusing the existing `new ToolService(...)` wiring already present in `tests/run.php` (grep for `new ToolService(` and copy that block, appending the new `ResultEnricher` argument):

```php
/** @return array{0: ToolService, 1: string} */
function buildToolServiceWithScan(string $fixture): array {
    [$pdo, $projectId] = scanTempFixture($fixture); // from Task 2
    $queries = new ArchitectureQueryService($pdo);
    $tools = new ToolService(
        buildScanService($pdo),        // existing helper used by the other ToolService tests
        $queries,
        new DatabaseMaintenanceService($pdo),
        new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
    );
    return [$tools, $projectId];
}
```

- [ ] **Step 2: Run the suite to verify the new tests fail**

Run: `composer test`
Expected: FAIL — `ToolService::__construct` arity mismatch (enricher arg missing) and/or `verbosity` not handled.

- [ ] **Step 3: Add `staleness()` + wall clock to the facade**

In `src/Query/ArchitectureQueryService.php`, add `use Knossos\Query\StalenessProbe;` is unneeded (same namespace). Add the trailing constructor param and a probe field. Change the constructor signature to:

```php
    public function __construct(
        PDO $pdo,
        ?Closure $clock = null,
        ?SemanticRanker $semanticRanker = null,
        ?GitHistoryProvider $gitHistory = null,
        ?GitWorkingTreeProvider $gitWorkingTree = null,
        ?Closure $wallClock = null,
    ) {
        // ... existing body unchanged ...
        $this->stalenessProbe = new StalenessProbe($pdo, $wallClock);
    }
```

Add the property near the other `private readonly` query fields:

```php
    private StalenessProbe $stalenessProbe;
```

(If the class is `final readonly`, this property is fine as it is assigned once in the constructor.) Add the method:

```php
    /** @return array<string, mixed>|null */
    public function staleness(string $projectId): ?array
    {
        return $this->stalenessProbe->probe($projectId);
    }
```

- [ ] **Step 4: Wire the enricher and verbosity into ToolService**

In `src/Mcp/ToolService.php`:

Add imports:

```php
use Knossos\Mcp\ResultEnricher;
```

Add the constructor dependency (trailing):

```php
    public function __construct(
        private ProjectScanService $scanner,
        private ArchitectureQueryService $queries,
        private DatabaseMaintenanceService $maintenance,
        private ResultEnricher $enricher,
    ) {}
```

Wrap `call()`. Locate the current `public function call(...)` (line ~497). Rename the existing dispatch body to a private `dispatch()` and make `call()` handle verbosity + enrichment:

```php
    public function call(string $name, array $arguments, ?CancellationToken $cancellation = null): ResultEnvelope
    {
        $verbosity = 'compact';
        if (array_key_exists('verbosity', $arguments)) {
            $verbosity = $arguments['verbosity'];
            unset($arguments['verbosity']);
            if ($verbosity !== 'compact' && $verbosity !== 'full') {
                throw new InvalidArgumentException('verbosity must be "compact" or "full".');
            }
        }
        $envelope = $this->dispatch($name, $arguments, $cancellation);
        return $this->enricher->enrich($envelope, $name, $verbosity);
    }

    /** @param array<string, mixed> $arguments */
    private function dispatch(string $name, array $arguments, ?CancellationToken $cancellation): ResultEnvelope
    {
        return match ($name) {
            // ... the existing match arms, unchanged ...
        };
    }
```

(Move the existing `match ($name) { ... }` block verbatim into `dispatch()`. The `self::keys(...)` validators inside each handler already reject unknown keys — since `verbosity` is stripped in `call()` before dispatch, they will not see it.)

- [ ] **Step 5: Update the composition roots**

Update the three non-test construction sites to pass the enricher. In `src/Cli/Command/ServeCommand.php` around line 34 and `bin/http-router.php` around line 43, each already has a `$pdo` and builds `ArchitectureQueryService`. Add before the `new ToolService(`:

```php
$enricher = new \Knossos\Mcp\ResultEnricher(
    new \Knossos\Query\StalenessProbe($pdo),
    new \Knossos\Mcp\NextStepPlanner(),
);
```

and append `$enricher` as the final argument to `new ToolService(...)`. Read each file first to use its actual local variable name for the PDO handle.

- [ ] **Step 6: Run the suite to verify it passes**

Run: `composer test`
Expected: PASS. If a pre-existing `new ToolService(` in `tests/run.php` other than the new helper now fails on arity, update it to append an enricher argument (same one-liner). Grep: `grep -n "new ToolService(" tests/run.php`.

- [ ] **Step 7: Commit**

```bash
git add src/Query/ArchitectureQueryService.php src/Mcp/ToolService.php src/Cli/Command/ServeCommand.php bin/http-router.php tests/run.php
git commit -m "feat(mcp): enrich all tool results with staleness, next_steps, verbosity, meta"
```

---

## Task 6: Intent-first descriptions + `verbosity` input + regenerated reference

**Files:**
- Modify: `src/Mcp/ToolService.php` (the `definitions()` array: descriptions + `verbosity` input on read tools)
- Regenerate: `docs/MCP-REFERENCE.md`, `docs/CLI-REFERENCE.md`
- Test: `tests/run.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: every read tool's input schema gains an optional `verbosity` property (`type=string`, `enum=[compact, full]`, `default=compact`); all 28 `description` strings rewritten intent-first; generated reference regenerated and asserted in sync.

- [ ] **Step 1: Write the failing test**

Append to `tests/run.php`:

```php
$tests['read tools advertise a verbosity input'] = static function (): void {
    $defs = buildToolServiceWithScan('php-scanner')[0]->definitions();
    $byName = [];
    foreach ($defs as $d) { $byName[$d['name']] = $d; }
    $verbosity = $byName['impact_analysis']['inputSchema']['properties']['verbosity'] ?? null;
    assertSame('string', $verbosity['type']);
    assertSame(['compact', 'full'], $verbosity['enum']);
    assertSame('compact', $verbosity['default']);
};

$tests['tool descriptions are intent-first, not jargon-first'] = static function (): void {
    $defs = buildToolServiceWithScan('php-scanner')[0]->definitions();
    $byName = [];
    foreach ($defs as $d) { $byName[$d['name']] = $d; }
    // impact_analysis should tell an agent WHEN to reach for it.
    assertContains('before', strtolower($byName['impact_analysis']['description']));
    assertContains('depend', strtolower($byName['impact_analysis']['description']));
};
```

- [ ] **Step 2: Run the suite to verify the new tests fail**

Run: `composer test`
Expected: FAIL — `verbosity` property missing; description assertions fail.

- [ ] **Step 3: Add `verbosity` to read-tool input schemas**

In `src/Mcp/ToolService.php` `definitions()`, for each READ tool (`list_projects`, `list_snapshots`, `find_component`, `snapshot_diff`, `quality_gate`, `architecture_trends`, `inspect_component`, `architecture_summary`, `file_metrics`, `explain_flow`, `impact_analysis`, `dependency_cycles`, `architecture_health`, `check_architecture`, `suggest_location`, `change_impact`, `changed_files_impact`, `architecture_context`, `export_diagram`, `list_boundaries`, `search_architecture`) add to that tool's `inputSchema['properties']`:

```php
'verbosity' => ['type' => 'string', 'enum' => ['compact', 'full'], 'default' => 'compact', 'description' => 'compact (default) trims evidence to a preview; full returns all evidence.'],
```

Do NOT add `verbosity` to write/mutating tools (`scan_project`, `remove_project`, `cleanup_stale_scans`, `maintain_database`) — the enricher still runs on them but they carry no meaningful evidence.

- [ ] **Step 4: Rewrite all 28 descriptions intent-first**

Replace each `description` string in `definitions()`. Template: *[When to reach for it]. [What it answers]. [Why it beats grep/read].* Keep to 1–3 sentences. Full replacement set (apply verbatim):

- `list_projects`: `"Start here to find a project_id. Lists scanned projects with freshness and graph size so you can pick the right project_id before any other call."`
- `scan_project`: `"Build or refresh a project's architecture graph. Run this first for a new project, or when a query reports the graph is missing or stale."`
- `list_snapshots`: `"See a project's scan history. Use to find an older snapshot id to diff against or to check when it was last scanned."`
- `find_component`: `"Locate a component by name when you are unsure of its exact canonical path. Returns ranked candidates — use before inspect_component when the name is ambiguous."`
- `snapshot_diff`: `"See what changed architecturally between two scans. Use after a rescan to review added/removed components and relationships instead of eyeballing a code diff."`
- `quality_gate`: `"Check architecture budgets against a baseline in CI. Use to fail a build on regressions (new cycles, boundary breaks) rather than reviewing them by hand."`
- `architecture_trends`: `"See how architecture metrics moved over recent scans. Use for release notes or to spot slow structural drift."`
- `inspect_component`: `"Get the full dossier for one component — its roles, boundary, containment, relationships, and evidence — in a single call. Faster than opening and cross-referencing several files by hand."`
- `architecture_summary`: `"Get a one-call overview of the codebase by language, node kind, and relationship kind. Use to orient yourself in an unfamiliar project before drilling in."`
- `file_metrics`: `"Find the largest or longest files. Use to spot refactor targets without shelling out to wc/find."`
- `explain_flow`: `"Answer 'how does A reach B?' Traces evidence-backed static paths between two components — more reliable than grepping call sites across layers."`
- `impact_analysis`: `"Before editing a symbol, find everything that depends on it. Answers 'what breaks if I change this?' by following real static references, so it is more complete than grepping for callers."`
- `dependency_cycles`: `"Find circular dependencies. Use before a refactor to see which modules are tangled, instead of tracing imports by hand."`
- `architecture_health`: `"Rank the structural hotspots, hubs, and likely-dead code. Use to decide where cleanup or extra test coverage pays off most."`
- `check_architecture`: `"Verify declared boundary rules still hold. Use to confirm a change did not introduce a forbidden cross-boundary dependency."`
- `suggest_location`: `"Decide where new code for a feature belongs. Ranks existing boundaries by lexical and dependency fit so a new file lands in a cohesive place."`
- `change_impact`: `"Blend static blast radius with recent Git churn to prioritize review. Use when you want risk-ranked impact, not just a reachable-set list."`
- `changed_files_impact`: `"Map a set of changed files (explicit or from a Git diff) to the components they affect. Use to scope review or tests to what a change actually touches."`
- `architecture_context`: `"Assemble a bounded, task-shaped evidence bundle (summary + likely location + impact + dossiers) for a coding task in one call. Use at the start of a task to load just-enough context cheaply."`
- `export_diagram`: `"Render the current graph as Mermaid or PlantUML source. Use to embed an up-to-date architecture diagram in docs without a renderer."`
- `list_boundaries`: `"List the architecture boundaries and sample members. Use to learn how the codebase is partitioned before navigating it."`
- `search_architecture`: `"Search components by name, attribute, or role with structured filters. Use when you know a trait of what you want but not its exact name."`
- `remove_project`: `"Delete a project and its stored graph. Preview by default; pass the confirm flag to actually remove. Use to clean up projects you no longer query."`
- `cleanup_stale_scans`: `"Remove failed, cancelled, or abandoned scan records. Preview by default. Use for housekeeping when the scan history is cluttered."`
- `maintain_database`: `"Check integrity or run a checkpoint/optimize/backup of the graph store. Use for routine upkeep or before an upgrade."`

(These map onto the current tool set. If a tool name here is absent or an extra tool exists, reconcile against the live `definitions()` list — the intent template governs any tool not spelled out above.)

- [ ] **Step 5: Regenerate the reference docs**

Run: `php tools/generate-reference.php`
Then verify: `php tools/generate-reference.php --check`
Expected: exit 0 and prints that the generated reference is current.

- [ ] **Step 6: Run the suite to verify it passes**

Run: `composer test`
Expected: PASS — including the existing reference-sync test (`## \`architecture_summary\`` present) and the new description/verbosity tests.

- [ ] **Step 7: Commit**

```bash
git add src/Mcp/ToolService.php docs/MCP-REFERENCE.md docs/CLI-REFERENCE.md tests/run.php
git commit -m "feat(mcp): intent-first tool descriptions and verbosity input; regenerate reference"
```

---

## Self-Review

**Spec coverage:**
- Pillar A (self-explaining descriptions) → Task 6 (descriptions) + regenerated reference. ✓
- Pillar B (guide-don't-act staleness) → Task 2 (StalenessProbe, never scans) + Task 5 (`staleness()` surfaced on every result). `missing`/`stale`/`fresh` states + guidance strings covered. ✓
- Pillar C envelope fields (`staleness`, `next_steps` cap 3, `meta.result_bytes`, verbosity compact default) → Tasks 1, 3, 4, 5, 6. ✓
- `changed_files_since` best-effort + bounded (≤500, omit if root unavailable) → Task 2. ✓
- Stable machine shape across all 28 tools → single `ResultEnvelope.jsonSerialize()` + single `ResultEnricher` at `call()` boundary. ✓
- Backward-compatibility (additive optional fields/inputs) → Tasks 1 & 5 use trailing optional params; only observable default change is `verbosity=compact`, documented in the regenerated reference. ✓
- Testing plan items (envelope serialization, verbosity compact/full, staleness states, next_steps) → Tasks 1–6 each add closures; reference-sync asserted in Task 6. ✓

**Placeholder scan:** No TBD/TODO. Every code step shows full code. Test helper closures (`freshTestDatabase`, `scanTempFixture`, `buildScanService`, `buildToolServiceWithScan`) are flagged to reuse existing `tests/run.php` scan wiring — the implementer must grep and match the real `ProjectScanService`/`scan()` API rather than invent it; this is the one place the plan defers to live code because the harness's exact scan-setup closure is private to `run.php`.

**Type consistency:** `ResultEnvelope::with(?array $staleness, ?array $nextSteps, ?array $meta)` used consistently in Tasks 1/4. `StalenessProbe::probe(string): ?array` used in Tasks 2/4/5. `NextStepPlanner::plan(string, ResultEnvelope): array` used in Tasks 3/4. `ResultEnricher::enrich(ResultEnvelope, string, string): ResultEnvelope` used in Tasks 4/5. `ToolService::__construct(..., ResultEnricher)` and `dispatch()` split consistent in Task 5. `meta` keys (`result_bytes`, `verbosity`, `evidence_total`, `evidence_shown`) identical in Tasks 4 and 5 tests.

**Known live-code check the implementer must do (not a plan gap, a grounding step):** the `NextStepPlanner` `data` key names (`candidates`, `component`, `impacted`, `is_hub`, `hotspots`) are best-guesses; Task 3 Step 3 instructs reading the real query methods and correcting keys. Tests assert behavior, so a wrong key surfaces as a failing planner test to fix in the planner.
