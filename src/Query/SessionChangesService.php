<?php

declare(strict_types=1);

namespace Knossos\Query;

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
 * was not recorded, or the session began before the oldest entry kept),
 * `complete` is false and no file is listed: a partial list would read as
 * the whole story.
 */
final readonly class SessionChangesService
{
    /** Files listed, most dependents first. */
    public const MAX_FILES = 200;

    /** Tests listed, nearest first. */
    public const MAX_TESTS = 50;

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
            'files' => (object) [], 'files_truncated' => false, 'tests' => [], 'tests_truncated' => false];
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
            return $base;
        }
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
            ];
        }
        uksort($files, static fn(string $a, string $b): int => $files[$b]['dependents'] <=> $files[$a]['dependents'] ?: strcmp($a, $b));
        $sources = array_slice(array_values(array_filter(array_keys($files), static fn(string $f): bool => $files[$f]['status'] !== 'deleted')), 0, self::MAX_TEST_SOURCES);
        $tests = $sources === [] ? [] : $this->tests($projectId, $sources);
        return [
            'complete' => true,
            'files' => (object) array_slice($files, 0, self::MAX_FILES, true),
            'files_truncated' => count($files) > self::MAX_FILES || count($live) > self::MAX_TEST_SOURCES,
            'tests' => JsTestRunner::annotate($root, array_slice($tests, 0, self::MAX_TESTS)),
            'tests_truncated' => count($tests) > self::MAX_TESTS,
        ] + $base;
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

    /**
     * The tests that reach `$files`, nearest first, one more than listed so a cut is seen.
     *
     * @param list<string> $files
     * @return list<array{path: string, distance: int}>
     */
    private function tests(string $projectId, array $files): array
    {
        $data = (new ArchitectureQueryService($this->pdo))->testImpact($projectId, $files, limit: self::MAX_TESTS + 1)->data;
        return array_map(
            static fn(array $t): array => ['path' => (string) $t['path'], 'distance' => (int) $t['distance']],
            $data['test_files'] ?? [],
        );
    }
}
