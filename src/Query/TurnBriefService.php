<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Discovery\FileFingerprint;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ProjectWriterLease;
use Knossos\Scan\ProjectWriterLock;
use Knossos\Scan\ScanBusyException;
use PDO;
use Throwable;

/**
 * What one Claude Code turn did to the graph.
 *
 * Reads every file's content hash, runs an incremental scan, reads them
 * again: the difference is exactly what changed on disk since the last
 * scan, whoever changed it, with no git needed. Then reports fan-in, the
 * tests that reach the changed files (a JavaScript one with the runner its
 * package.json names, {@see JsTestRunner}), and the boundary-policy violations the
 * turn introduced: those whose source lives in a file the caller reported as
 * edited and that were not there before the scan. Files changed by other
 * means never contribute to the policy verdict. Scans only roots the operator
 * allowed.
 */
final readonly class TurnBriefService
{
    /** Dependents listed per changed file. */
    private const TOP_DEPENDENTS = 5;

    /** How often a scan another writer holds the project for is tried, and how long to wait between tries. */
    private const BUSY_ATTEMPTS = 5;

    private const BUSY_WAIT_MS = 1000;

    /** Tests listed in the brief, enforced by testImpact()'s own limit. */
    private const MAX_TESTS = 20;

    /** Violations listed in the brief; the total counts them all, exactly unless `policy.truncated`. */
    private const MAX_VIOLATIONS = 10;

    /**
     * @param string $databasePath where $pdo lives; locates `roots.json`
     * @param int $policyTimeoutMs the time budget of each policy check, which walks only the edited files' edges
     * @param int $policyMaxEdges the edge budget of each policy check
     */
    public function __construct(
        private PDO $pdo,
        private string $databasePath,
        private string $installationRoot,
        private int $policyTimeoutMs = 5000,
        private int $policyMaxEdges = ArchitecturePolicyQueryService::DEFAULT_MAX_EDGES,
    ) {}

    /**
     * Scan $path's project incrementally and brief the change.
     *
     * `$since` is the snapshot the turn started from. When another writer
     * scanned since (the live watcher, a rescan, another session), the graph
     * before this brief's scan already holds some of the turn's edits; the
     * {@see ScanLedger} then says what each file held before the first scan
     * that changed it, so the diff and the policy baseline are still the
     * turn's. When the ledger cannot account for every scan since, the files
     * are still reported but the policy is not evaluated: its baseline is gone.
     *
     * With `$reuseScan`, a brief whose reported files the graph already holds
     * as they are on disk does not scan again: another writer just did.
     *
     * @param list<string> $extraFiles paths the caller saw edited, relative or absolute; merged into the diff
     * @param list<array<string, mixed>>|null $policies null reads knossos.json
     * @return array<string, mixed>
     */
    public function brief(string $path, array $extraFiles = [], ?array $policies = null, bool $enforcePolicies = true, ?string $since = null, bool $reuseScan = false): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = self::empty($absolute);
        [$allowed, $project, $refusal] = (new ScanTarget($this->pdo, $this->databasePath))->resolve($absolute);
        if ($project === null) {
            return ($refusal ?? []) + $envelope;
        }
        $root = (string) $project['root_realpath'];
        $projectId = (string) $project['id'];
        $reported = self::relative($root, $extraFiles);
        $violations = new FileViolationQuery($this->pdo, $this->policyTimeoutMs, $this->policyMaxEdges);
        $policies = $enforcePolicies ? FileViolationQuery::policies($root, $policies) : [];
        $ledger = new ScanLedger($this->pdo);
        $reuse = $reuseScan && self::onDisk($root, $reported, $ledger->hashes($projectId));
        // A brief that scans holds the project's write lease from before it reads the graph until its scan
        // is recorded: no other writer scans in between, so the ledger entry starts where the scan did.
        try {
            $lease = $reuse ? null : $this->lease($projectId);
        } catch (Throwable $failure) {
            return ['status' => 'scan-failed', 'reason' => $failure->getMessage(), 'project_root' => $root] + $envelope;
        }
        try {
            $from = $ledger->activeSnapshot($projectId);
            $current = $ledger->hashes($projectId);
            $absorbed = $since === null ? ['before' => [], 'baselines' => [], 'scans' => []] : $ledger->since($projectId, $since);
            // Taken before the scan rewrites the graph: what the reported files already broke is not this turn's doing.
            [$baseline, $perFile] = $this->baseline($violations, $projectId, $policies, $reported, $absorbed);
            $before = self::rewound($current, $absorbed['before'] ?? []);
            $started = hrtime(true);
            try {
                $scan = $lease === null ? null : (new ProjectScanService($this->pdo, $this->installationRoot, $allowed))->scan($root, mode: 'incremental', lease: $lease);
            } catch (Throwable $failure) {
                return ['status' => 'scan-failed', 'reason' => $failure->getMessage(), 'project_root' => $root] + $envelope;
            }
            // Same id as the baseline, so a recomputed id cannot make every file look added.
            $after = $ledger->hashes($projectId);
            if ($scan !== null) {
                $ledger->record($projectId, $from, $scan->snapshotId, $current, $after, $perFile);
            }
        } finally {
            $lease?->release();
        }
        [$changed, $added, $deleted] = self::diff($before, $after, $reported);
        $live = array_merge($changed, $added);
        // Policy looks only at what the turn itself edited: a checkout, a formatter or a shell command
        // can change many files at once, and their old violations are not the model's to fix.
        $edited = array_values(array_intersect($live, $reported));
        $queries = new ArchitectureQueryService($this->pdo, gitWorkingTree: new ProcessGitWorkingTreeProvider());
        $tests = $live === [] ? ['tests' => [], 'truncated' => false] : FileTestReach::testsOf($queries, $projectId, $live, self::MAX_TESTS);
        return [
            'status' => 'ok',
            'project_root' => $root,
            'project_id' => $projectId,
            'snapshot_id' => $scan === null ? $from : $scan->snapshotId,
            'scanned_at' => time(),
            'scan_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'scanned' => $scan !== null,
            'changed_files' => $changed,
            'added_files' => $added,
            'deleted_files' => $deleted,
            'impact' => self::withTests((new FileFanInQuery($this->pdo))->forPaths($projectId, $live, self::TOP_DEPENDENTS), (new FileTestReach($this->pdo))->reach($projectId, $live)),
            'tests' => JsTestRunner::annotate($root, $tests['tests']),
            'tests_truncated' => $tests['truncated'],
            'policy' => self::policy($enforcePolicies, $edited, $baseline, $baseline === null ? null : $violations->inFiles($projectId, $policies, $edited)),
        ] + $envelope;
    }

    /**
     * The project's write lease; one another writer holds is waited out a
     * few times before it counts as a failure.
     */
    private function lease(string $projectId): ProjectWriterLease
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return (new ProjectWriterLock($this->pdo))->acquire($projectId);
            } catch (ScanBusyException $busy) {
                if ($attempt >= self::BUSY_ATTEMPTS) {
                    throw $busy;
                }
                usleep(self::BUSY_WAIT_MS * 1000);
            }
        }
    }

    /**
     * The violations the reported files held before the turn, combined, and
     * the same file by file (for the ledger), or null for either when they
     * cannot be told: no policies, a scan since the turn began that the
     * ledger cannot account for, or one that recorded no baseline for a
     * reported file it changed.
     *
     * @param list<array<string, mixed>> $policies
     * @param list<string> $reported
     * @param array{before: array<string, string|null>, baselines: array<string, mixed>}|null $absorbed
     * @return array{0: array{violations: array<string, array<string, mixed>>, truncated: bool}|null, 1: array<string, array{violations: array<string, array<string, mixed>>, truncated: bool}>|null}
     */
    private function baseline(FileViolationQuery $violations, string $projectId, array $policies, array $reported, ?array $absorbed): array
    {
        if ($policies === [] || $absorbed === null) {
            return [null, null];
        }
        $combined = ['violations' => [], 'truncated' => false];
        $perFile = [];
        $fresh = array_values(array_filter($reported, static fn(string $f): bool => !array_key_exists($f, $absorbed['before'])));
        foreach ($reported as $file) {
            if (array_key_exists($file, $absorbed['before'])) {
                $found = $absorbed['baselines'][$file] ?? null;
                if ($found === null) {
                    return [null, null];
                }
            } elseif (count($fresh) <= LedgeredScanner::MAX_CHECKED) {
                $found = $violations->inFiles($projectId, $policies, [$file]);
                $perFile[$file] = $found;
            } else {
                continue;
            }
            $combined = ['violations' => $combined['violations'] + $found['violations'], 'truncated' => $combined['truncated'] || $found['truncated']];
        }
        if (count($fresh) > LedgeredScanner::MAX_CHECKED) {
            $all = $violations->inFiles($projectId, $policies, $fresh);
            $combined = ['violations' => $combined['violations'] + $all['violations'], 'truncated' => $combined['truncated'] || $all['truncated']];
            $perFile = null;
        }
        return [$combined, $perFile];
    }

    /**
     * The hashes the graph held when the turn began: today's, with each file
     * a scan since then changed put back as it was (gone, when it added it).
     *
     * @param array<string, string> $current
     * @param array<string, string|null> $absorbed
     * @return array<string, string>
     */
    private static function rewound(array $current, array $absorbed): array
    {
        foreach ($absorbed as $path => $hash) {
            if ($hash === null) {
                unset($current[$path]);
            } else {
                $current[$path] = $hash;
            }
        }
        return $current;
    }

    /**
     * Whether the graph already holds every reported file as it is on disk
     * (a deleted one as gone): then a scan would find nothing of the turn's.
     * False with nothing reported, which says nothing either way.
     *
     * @param list<string> $reported
     * @param array<string, string> $graph
     */
    private static function onDisk(string $root, array $reported, array $graph): bool
    {
        foreach ($reported as $file) {
            $absolute = rtrim($root, '/') . '/' . $file;
            $disk = is_file($absolute) ? FileFingerprint::contentHashOf($absolute) : null;
            if ($disk !== ($graph[$file] ?? null)) {
                return false;
            }
        }
        return $reported !== [];
    }

    /**
     * Split two hash maps into changed, added and deleted paths, each sorted.
     *
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @param list<string> $extra relative paths the caller reported; present only if still tracked
     * @return array{list<string>, list<string>, list<string>}
     */
    private static function diff(array $before, array $after, array $extra): array
    {
        $changed = [];
        foreach ($after as $file => $hash) {
            if (isset($before[$file]) && $before[$file] !== $hash) {
                // An all-digit path comes back from FETCH_KEY_PAIR as an integer key.
                $changed[] = (string) $file;
            }
        }
        foreach ($extra as $file) {
            if (isset($before[$file], $after[$file]) && !in_array($file, $changed, true)) {
                $changed[] = $file;
            }
        }
        $added = array_keys(array_diff_key($after, $before));
        $deleted = array_keys(array_diff_key($before, $after));
        sort($changed);
        sort($added);
        sort($deleted);
        return [$changed, array_map('strval', $added), array_map('strval', $deleted)];
    }

    /**
     * Reported paths made relative to the project root, keeping a leading dot.
     *
     * An absolute path is resolved first, so one spelled through a symbolic
     * link (a checkout reached by a linked directory) still lands under the
     * root, which is a real path. A deleted file no longer resolves; its
     * directory still does.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private static function relative(string $root, array $paths): array
    {
        $prefix = rtrim($root, '/') . '/';
        return array_map(
            static function (string $p) use ($prefix): string {
                $p = self::resolved($p);
                // Only a literal "./" prefix goes: ltrim() with a character list would also eat the dot of ".github/".
                return str_starts_with($p, $prefix)
                    ? substr($p, strlen($prefix))
                    : (str_starts_with($p, './') ? substr($p, 2) : $p);
            },
            $paths,
        );
    }

    /** An absolute path with its links resolved, through its directory when the file is gone; others unchanged. */
    private static function resolved(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            return $path;
        }
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        $directory = realpath(dirname($path));
        return $directory === false ? $path : rtrim($directory, '/') . '/' . basename($path);
    }

    /**
     * Each file's fan-in with `tests`, how many test files reach it, where
     * that is known ({@see FileTestReach}); a file without a count has none.
     *
     * @param array<string, array<string, mixed>> $impact
     * @param array<string, int> $reach
     * @return array<string, array<string, mixed>>
     */
    private static function withTests(array $impact, array $reach): array
    {
        foreach ($reach as $file => $tests) {
            if (isset($impact[$file])) {
                $impact[$file]['tests'] = $tests;
            }
        }
        return $impact;
    }

    /**
     * The boundary-policy verdict: the violations in the edited files after the
     * scan that were not in them before it.
     *
     * @param list<string> $live the reported files still tracked after the scan
     * @param array{violations: array<string, array<string, mixed>>, truncated: bool}|null $before null when not evaluable
     * @param array{violations: array<string, array<string, mixed>>, truncated: bool}|null $after
     * @return array{status: string, total: int, violations: list<array<string, mixed>>, truncated: bool}
     */
    private static function policy(bool $enforce, array $live, ?array $before, ?array $after): array
    {
        if (!$enforce) {
            return ['status' => 'disabled', 'total' => 0, 'violations' => [], 'truncated' => false];
        }
        if ($live === [] || $before === null || $after === null) {
            return ['status' => 'not_evaluated', 'total' => 0, 'violations' => [], 'truncated' => false];
        }
        $introduced = array_values(array_diff_key($after['violations'], $before['violations']));
        return [
            'status' => 'evaluated',
            'total' => count($introduced),
            'violations' => array_slice($introduced, 0, self::MAX_VIOLATIONS),
            'truncated' => $before['truncated'] || $after['truncated'],
        ];
    }

    /** @return array<string, mixed> the fields every status carries */
    private static function empty(string $path): array
    {
        return [
            'path' => $path, 'project_root' => null, 'project_id' => null, 'snapshot_id' => null,
            'scanned_at' => null, 'scan_ms' => null, 'reason' => null, 'roots_file' => null, 'refused_root' => null,
            'changed_files' => [], 'added_files' => [], 'deleted_files' => [],
            'impact' => [], 'tests' => [], 'tests_truncated' => false,
            'policy' => ['status' => 'not_evaluated', 'total' => 0, 'violations' => [], 'truncated' => false],
        ];
    }
}
