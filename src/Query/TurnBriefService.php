<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Discovery\AllowedRoots;
use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\RootGuard;
use Knossos\Discovery\RootNotFoundException;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Scan\ProjectScanService;
use PDO;
use Throwable;

/**
 * What one Claude Code turn did to the graph.
 *
 * Reads every file's content hash, runs an incremental scan, reads them
 * again: the difference is exactly what changed on disk since the last
 * scan, whoever changed it, with no git needed. Then reports fan-in, the
 * tests that reach the changed files, and the boundary-policy violations
 * touching them. Scans only roots the operator allowed.
 */
final readonly class TurnBriefService
{
    /** Dependents listed per changed file. */
    private const TOP_DEPENDENTS = 5;

    /** Tests listed in the brief, enforced by testImpact()'s own limit. */
    private const MAX_TESTS = 20;

    /** Violations listed in the brief; the total is always exact. */
    private const MAX_VIOLATIONS = 10;

    /**
     * @param string $databasePath where $pdo lives; locates `roots.json`
     */
    public function __construct(
        private PDO $pdo,
        private string $databasePath,
        private string $installationRoot,
    ) {}

    /**
     * Scan $path's project incrementally and brief the change.
     *
     * @param list<string> $extraFiles paths the caller saw edited, relative or absolute; merged into the diff
     * @param list<array<string, mixed>>|null $policies null reads knossos.json
     * @return array<string, mixed>
     */
    public function brief(string $path, array $extraFiles = [], ?array $policies = null, bool $enforcePolicies = true): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = self::empty($absolute);
        $allowed = new AllowedRoots(AllowedRoots::fromEnvironment(), $this->rootsFile());
        $refusal = self::refusal($allowed, $absolute, $this->rootsFile());
        if ($refusal !== null) {
            return $refusal + $envelope;
        }
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        if ($project === null) {
            return ['status' => 'unscanned'] + $envelope;
        }
        $root = (string) $project['root_realpath'];
        // The resolver may have walked up to an ancestor project: that root is what gets scanned, so it is what must be allowed.
        $refusal = self::refusal($allowed, $root, $this->rootsFile());
        if ($refusal !== null) {
            return $refusal + $envelope;
        }
        $projectId = (string) $project['id'];
        $before = $this->hashes($projectId);
        $started = hrtime(true);
        try {
            $scan = (new ProjectScanService($this->pdo, $this->installationRoot, $allowed))
                ->scan($root, mode: 'incremental');
        } catch (Throwable $failure) {
            return ['status' => 'scan-failed', 'reason' => $failure->getMessage(), 'project_root' => $root] + $envelope;
        }
        // Same id as the baseline, so a recomputed id cannot make every file look added.
        $after = $this->hashes($projectId);
        [$changed, $added, $deleted] = self::diff($before, $after, self::relative($root, $extraFiles));
        $live = array_merge($changed, $added);
        $queries = new ArchitectureQueryService($this->pdo, gitWorkingTree: new ProcessGitWorkingTreeProvider());
        return [
            'status' => 'ok',
            'project_root' => $root,
            'project_id' => $scan->projectId,
            'snapshot_id' => $scan->snapshotId,
            'scanned_at' => time(),
            'scan_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'changed_files' => $changed,
            'added_files' => $added,
            'deleted_files' => $deleted,
            'impact' => (new FileFanInQuery($this->pdo))->forPaths($scan->projectId, $live, self::TOP_DEPENDENTS),
            'tests' => $live === [] ? [] : $this->tests($queries, $scan->projectId, $live),
            'policy' => $this->policy($queries, $scan->projectId, $live, $policies, $enforcePolicies),
        ] + $envelope;
    }

    /**
     * The status fields for a path the allow-list refuses, or null when it is allowed.
     *
     * @return array<string, mixed>|null
     */
    private static function refusal(AllowedRoots $allowed, string $path, string $rootsFile): ?array
    {
        try {
            (new RootGuard($allowed))->resolve($path);
        } catch (RootNotFoundException) {
            return ['status' => 'missing'];
        } catch (DiscoveryException) {
            return ['status' => 'not-allowed', 'roots_file' => $rootsFile];
        }
        return null;
    }

    /** `roots.json` beside the database, or the override the environment names. */
    private function rootsFile(): string
    {
        return AllowedRoots::defaultConfigPath($this->databasePath);
    }

    /**
     * Every tracked file's content hash, the baseline a diff is taken against.
     *
     * @return array<string, string> relative path => content hash
     */
    private function hashes(string $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT relative_path, content_hash FROM files WHERE project_id = ?');
        $statement->execute([$projectId]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_KEY_PAIR));
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
                $changed[] = $file;
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
     * @param list<string> $paths
     * @return list<string>
     */
    private static function relative(string $root, array $paths): array
    {
        $prefix = rtrim($root, '/') . '/';
        return array_map(
            // Only a literal "./" prefix goes: ltrim() with a character list would also eat the dot of ".github/".
            static fn(string $p): string => str_starts_with($p, $prefix)
                ? substr($p, strlen($prefix))
                : (str_starts_with($p, './') ? substr($p, 2) : $p),
            $paths,
        );
    }

    /**
     * The test files that reach the live changed files, nearest first.
     *
     * @param list<string> $live
     * @return list<array{path: string, distance: int}>
     */
    private function tests(ArchitectureQueryService $queries, string $projectId, array $live): array
    {
        $data = $queries->testImpact($projectId, $live, limit: self::MAX_TESTS)->data;
        return array_map(
            static fn(array $t): array => ['path' => (string) $t['path'], 'distance' => (int) $t['distance']],
            $data['test_files'] ?? [],
        );
    }

    /**
     * The boundary-policy verdict for the live changed files.
     *
     * @param list<string> $live
     * @param list<array<string, mixed>>|null $policies
     * @return array{status: string, total: int, violations: list<array<string, mixed>>}
     */
    private function policy(ArchitectureQueryService $queries, string $projectId, array $live, ?array $policies, bool $enforce): array
    {
        if (!$enforce) {
            return ['status' => 'disabled', 'total' => 0, 'violations' => []];
        }
        if ($live === []) {
            return ['status' => 'not_evaluated', 'total' => 0, 'violations' => []];
        }
        $check = $queries->reviewDiff($projectId, files: $live, policies: $policies)->data['policy_check'] ?? [];
        if (($check['status'] ?? '') !== 'evaluated') {
            return ['status' => 'not_evaluated', 'total' => 0, 'violations' => []];
        }
        $violations = array_map(static fn(array $v): array => [
            'policy_id' => $v['policy_id'],
            'source' => $v['source']['canonical_name'],
            'target' => $v['target']['canonical_name'],
            'source_boundaries' => $v['source_boundaries'],
            'target_boundaries' => $v['target_boundaries'],
        ], $check['violations_touching_change'] ?? []);
        return [
            'status' => 'evaluated',
            'total' => count($violations),
            'violations' => array_slice($violations, 0, self::MAX_VIOLATIONS),
        ];
    }

    /** @return array<string, mixed> the fields every status carries */
    private static function empty(string $path): array
    {
        return [
            'path' => $path, 'project_root' => null, 'project_id' => null, 'snapshot_id' => null,
            'scanned_at' => null, 'scan_ms' => null, 'reason' => null, 'roots_file' => null,
            'changed_files' => [], 'added_files' => [], 'deleted_files' => [],
            'impact' => [], 'tests' => [],
            'policy' => ['status' => 'not_evaluated', 'total' => 0, 'violations' => []],
        ];
    }
}
