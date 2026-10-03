<?php

declare(strict_types=1);

namespace Knossos\Query;

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
 * tests that reach the changed files, and the boundary-policy violations the
 * turn introduced: those whose source lives in a file the caller reported as
 * edited and that were not there before the scan. Files changed by other
 * means never contribute to the policy verdict. Scans only roots the operator
 * allowed.
 */
final readonly class TurnBriefService
{
    /** Dependents listed per changed file. */
    private const TOP_DEPENDENTS = 5;

    /** Tests listed in the brief, enforced by testImpact()'s own limit. */
    private const MAX_TESTS = 20;

    /** Violations listed in the brief; the total counts them all, exactly unless `policy.truncated`. */
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
        [$allowed, $project, $refusal] = (new ScanTarget($this->pdo, $this->databasePath))->resolve($absolute);
        if ($project === null) {
            return ($refusal ?? []) + $envelope;
        }
        $root = (string) $project['root_realpath'];
        $projectId = (string) $project['id'];
        $reported = self::relative($root, $extraFiles);
        $violations = new FileViolationQuery($this->pdo);
        $policies = $enforcePolicies ? FileViolationQuery::policies($root, $policies) : [];
        // Taken before the scan rewrites the graph: what the reported files already broke is not this turn's doing.
        $baseline = $violations->inFiles($projectId, $policies, $reported);
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
        [$changed, $added, $deleted] = self::diff($before, $after, $reported);
        $live = array_merge($changed, $added);
        // Policy looks only at what the turn itself edited: a checkout, a formatter or a shell command
        // can change many files at once, and their old violations are not the model's to fix.
        $edited = array_values(array_intersect($live, $reported));
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
            'policy' => self::policy($enforcePolicies, $edited, $baseline, $baseline === null ? null : $violations->inFiles($scan->projectId, $policies, $edited)),
        ] + $envelope;
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
            'impact' => [], 'tests' => [],
            'policy' => ['status' => 'not_evaluated', 'total' => 0, 'violations' => [], 'truncated' => false],
        ];
    }
}
