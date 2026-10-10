<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Scan\ProjectPathResolver;
use Knossos\Scan\ScanLedger;
use Knossos\Scan\ScanLedgerSpan;
use PDO;

/**
 * Everything that changed in a project since a session started, whoever
 * changed it: the session's own turns and subagents, the person's editor, a
 * git checkout, another session. Read from the {@see ScanLedger} the live
 * watcher (and every other recorded writer) keeps, never by scanning.
 *
 * Each file a recorded scan since `$since` changed is compared with what the
 * graph holds now: changed, added, or deleted, and a file put back as it was
 * is left out. Each comes with its dependents and boundaries, and the tests
 * that reach the files still there; every list is bounded and says when it
 * was cut. When the ledger cannot account for every scan since `$since` (one
 * was not recorded, or the session began before the oldest scan the ledger
 * still holds), `complete` is false. The files are then those changed since
 * the oldest snapshot the ledger can still answer for, which `reached` names
 * with when its scan was recorded (Unix seconds), so the caller can say so;
 * with no such snapshot, `reached` is null and no file is listed. When
 * `$since` lies among scans the ledger has merged, the files are those its
 * later scans changed and `start_approximate` is true
 * ({@see ScanLedger::since()}).
 *
 * Each file still there says how many test files reach it (`tests`, at
 * most {@see FileTestReach::COUNTED}) where that is known: the most depended
 * on first, within {@see FileTestReach}'s cap and deadline, so the pane can
 * mark a changed file no test reaches.
 *
 * Each file also names the snapshots the recorded scans that changed it
 * produced (`scans`, the newest {@see self::MAX_SCANS} in recording order),
 * so the caller can tell a change its own session's scans took in from one
 * scanned while it was idle. `scans` lists the recorded scans since
 * `$since` themselves, in recording order, the newest
 * {@see self::MAX_TIMELINE} (`scans_truncated` past that): the snapshot
 * each produced, when (Unix seconds) and how many files it changed, so the
 * pane can draw the session's scans as a timeline.
 */
final readonly class SessionChangesService
{
    /** Files listed, most dependents first. */
    public const MAX_FILES = 200;

    /** Tests listed, nearest first. */
    public const MAX_TESTS = 50;

    /** Scans named per file, the newest kept: a file changed more often than this is still told by its latest. */
    public const MAX_SCANS = ScanLedgerSpan::SCANS_PER_FILE;

    /** Scans listed in the timeline, the newest kept. */
    public const MAX_TIMELINE = 60;

    /** Files the test search starts from: the most depended on, past it the rest are left to the turn briefs. */
    private const MAX_TEST_SOURCES = 100;

    public function __construct(private PDO $pdo) {}

    /**
     * The changes since snapshot `$since` in the project that owns `$path`.
     *
     * @return array<string, mixed>
     */
    public function changes(string $path, string $since): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = ['path' => $absolute, 'project_root' => null, 'project_id' => null, 'snapshot_id' => null, 'since' => $since, 'complete' => false,
            'files' => (object) [], 'files_truncated' => false, 'tests' => [], 'tests_truncated' => false, 'scans' => [], 'scans_truncated' => false, 'reached' => null, 'start_approximate' => false];
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return ['status' => 'unscanned'] + $envelope;
        }
        $projectId = (string) $project['id'];
        $root = (string) $project['root_realpath'];
        $ledger = new ScanLedger($this->pdo);
        $base = ['status' => 'ok', 'project_root' => $root, 'project_id' => $projectId, 'snapshot_id' => $ledger->activeSnapshot($projectId)] + $envelope;
        $absorbed = $ledger->since($projectId, $since);
        if ($absorbed === null) {
            // The oldest point still answered for, unless that is where the session began (then nothing was recorded since).
            $reached = $ledger->reach($projectId);
            $absorbed = $reached === null || $reached['snapshot_id'] === $since ? null : $ledger->since($projectId, $reached['snapshot_id']);
            if ($absorbed === null || $reached === null) {
                return $base;
            }
            return ['complete' => false, 'reached' => $reached] + $this->listed($projectId, $root, $absorbed) + $base;
        }
        return ['complete' => true, 'start_approximate' => $absorbed['approximate']] + $this->listed($projectId, $root, $absorbed) + $base;
    }

    /**
     * The files `$absorbed` says changed, as they stand now, with their reach
     * and the tests that reach them, and the scans that changed them.
     *
     * @param array{before: array<string, string|null>, scans: array<string, list<string>>, chain: list<array<string, mixed>>} $absorbed
     * @return array<string, mixed>
     */
    private function listed(string $projectId, string $root, array $absorbed): array
    {
        $ledger = new ScanLedger($this->pdo);
        $statuses = self::statuses($absorbed['before'], $ledger->hashes($projectId));
        $live = array_keys(array_filter($statuses, static fn(string $s): bool => $s !== 'deleted'));
        $impact = (new FileFanInQuery($this->pdo))->forPaths($projectId, $live, 0);
        $files = [];
        foreach ($statuses as $file => $status) {
            $found = $impact[$file] ?? null;
            $files[$file] = [
                'status' => $status,
                'dependents' => (int) ($found['dependent_files'] ?? 0),
                'boundaries' => array_values($found['boundaries'] ?? []),
                'boundary' => $found['boundary'] ?? null,
                'scans' => array_slice($absorbed['scans'][$file] ?? [], -self::MAX_SCANS),
            ];
        }
        uksort($files, static fn(string $a, string $b): int => $files[$b]['dependents'] <=> $files[$a]['dependents'] ?: strcmp($a, $b));
        $sources = array_slice(array_values(array_filter(array_keys($files), static fn(string $f): bool => $files[$f]['status'] !== 'deleted')), 0, self::MAX_TEST_SOURCES);
        $tests = $sources === [] ? ['tests' => [], 'truncated' => false] : FileTestReach::testsOf(ArchitectureQueryService::forDatabase($this->pdo), $projectId, $sources, self::MAX_TESTS);
        foreach ((new FileTestReach($this->pdo))->reach($projectId, $sources) as $file => $reached) {
            $files[$file]['tests'] = $reached;
        }
        return [
            'files' => (object) array_slice($files, 0, self::MAX_FILES, true),
            'files_truncated' => count($files) > self::MAX_FILES || count($live) > self::MAX_TEST_SOURCES,
            'tests' => JsTestRunner::annotate($root, $tests['tests']),
            'tests_truncated' => $tests['truncated'],
            'scans' => array_slice($absorbed['chain'], -self::MAX_TIMELINE),
            'scans_truncated' => count($absorbed['chain']) > self::MAX_TIMELINE,
        ];
    }

    /**
     * How each file stands now against what it held when the session began:
     * gone from the graph is deleted, absent then is added, a different hash is
     * changed; one with the same hash as then is not listed.
     *
     * @param array<string, string|null> $before
     * @param array<string, string> $now
     * @return array<string, string>
     */
    private static function statuses(array $before, array $now): array
    {
        $statuses = [];
        foreach ($before as $file => $hash) {
            $current = $now[$file] ?? null;
            if ($current === $hash) {
                continue;
            }
            $statuses[(string) $file] = $current === null ? 'deleted' : ($hash === null ? 'added' : 'changed');
        }
        return $statuses;
    }
}
