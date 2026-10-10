<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Git\GitProcessRunner;
use Knossos\Git\GitProcessRunnerInterface;
use PDO;
use Throwable;

/**
 * What the checked-out branch did to the architecture, for the architecture
 * pane's Branch tab: the graph now against the snapshot taken where the
 * branch left its default branch.
 *
 * Git says where that is, locally and bounded, never over the network: the
 * default branch is the one `origin/HEAD` names (its local branch when there
 * is one), else `main` or `master`, local before remote; the merge base is
 * where HEAD left it. That commit is then matched to a retained snapshot by
 * the commit each scan was taken at (`git_head`): one taken at the merge
 * base itself (`exact`), else the nearest one taken before it (`before`,
 * with how many commits before). When none is retained, `status` is
 * `no-snapshot` and says so; the comparison is then made against the oldest
 * retained snapshot taken after the merge base, if any (`after`), which
 * already holds some of the branch's commits, and says how many.
 *
 * The comparison itself is {@see QualityGateQueryService::branchComparison()}.
 * Read-only: it never scans; git runs with the hardened runner, each call
 * bounded in time, and the snapshots it checks are bounded in number.
 */
final readonly class BranchDiffService
{
    /** Retained snapshots looked at, the newest first. */
    private const SNAPSHOTS = 50;

    /** Distinct commits checked for ancestry. */
    private const HEADS_CHECKED = 20;

    /** Items each list names. */
    public const LIMIT = 8;

    /** How long one git call may take. */
    private const GIT_TIMEOUT_MS = 3000;

    private GitProcessRunnerInterface $git;

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param GitProcessRunnerInterface|null $git how git is run; the hardened runner unless a test stands in
     */
    public function __construct(private PDO $pdo, ?GitProcessRunnerInterface $git = null)
    {
        $this->git = $git ?? new GitProcessRunner();
    }

    /**
     * The branch's changes to the architecture of the project that owns
     * `$path`. `status`: `ok`, `no-snapshot` (with or without a partial
     * comparison), `on-default` (the default branch itself is checked out),
     * `no-git`, `no-default` (no default branch to compare with), `unscanned`.
     *
     * @return array<string, mixed>
     */
    public function diff(string $path): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = ['path' => $absolute, 'project_id' => null, 'snapshot_id' => null, 'branch' => null, 'default_branch' => null, 'merge_base' => null, 'ahead' => null, 'base' => null, 'comparison' => null];
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return ['status' => 'unscanned'] + $envelope;
        }
        $id = (string) $project['id'];
        $root = (string) $project['root_realpath'];
        $envelope = ['project_id' => $id, 'snapshot_id' => $project['active_scan_id']] + $envelope;
        $head = $this->git($root, ['rev-parse', '--verify', '-q', 'HEAD']);
        if ($head === null) {
            return ['status' => 'no-git'] + $envelope;
        }
        $branch = $this->git($root, ['symbolic-ref', '--short', '-q', 'HEAD']);
        $default = $this->defaultBranch($root);
        $envelope = ['branch' => $branch, 'default_branch' => $default] + $envelope;
        if ($default === null) {
            return ['status' => 'no-default'] + $envelope;
        }
        $mergeBase = $this->git($root, ['merge-base', 'HEAD', $default]);
        if ($mergeBase === null) {
            return ['status' => 'no-default'] + $envelope;
        }
        $ahead = (int) ($this->git($root, ['rev-list', '--count', $mergeBase . '..HEAD']) ?? 0);
        $envelope = ['merge_base' => ['rev' => $mergeBase, 'at' => (int) ($this->git($root, ['show', '-s', '--format=%ct', $mergeBase]) ?? 0)], 'ahead' => $ahead] + $envelope;
        if ($ahead === 0 || $branch === $default || $branch === preg_replace('#^[^/]+/#', '', $default)) {
            return ['status' => 'on-default'] + $envelope;
        }
        $base = $this->baseSnapshot($root, $id, $mergeBase, (string) $project['active_scan_id']);
        $envelope['base'] = $base;
        if ($base === null) {
            return ['status' => 'no-snapshot'] + $envelope;
        }
        $comparison = (new ArchitectureQueryService($this->pdo))->branchComparison($id, $base['snapshot_id'], FileViolationQuery::policies($root, null), self::LIMIT);

        return ['status' => $base['match'] === 'after' ? 'no-snapshot' : 'ok', 'comparison' => $comparison] + $envelope;
    }

    /**
     * The default branch to compare with: what `origin/HEAD` names (its
     * local branch when one exists), else `main` or `master`, local before
     * remote; null when none exists.
     */
    private function defaultBranch(string $root): ?string
    {
        $named = $this->git($root, ['symbolic-ref', '--short', '-q', 'refs/remotes/origin/HEAD']);
        $candidates = $named === null ? [] : [(string) preg_replace('#^origin/#', '', $named), $named];
        foreach ([...$candidates, 'main', 'master', 'origin/main', 'origin/master'] as $candidate) {
            if ($this->git($root, ['rev-parse', '--verify', '-q', $candidate . '^{commit}']) !== null) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * The retained snapshot that stands for the merge base: one taken at it,
     * else the nearest taken before it, else the oldest taken after it; with
     * how it matched and how many commits lie between. Null when no retained
     * snapshot was taken on this line of history.
     *
     * @return array{snapshot_id: string, rev: string, at: string, match: string, commits: int}|null
     */
    private function baseSnapshot(string $root, string $projectId, string $mergeBase, string $active): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.id, s.git_head, s.started_at FROM scan_snapshots ss JOIN scans s ON s.id = ss.scan_id '
            . 'WHERE ss.project_id = :project AND ss.complete = 1 AND s.git_head IS NOT NULL AND s.id <> :active ORDER BY ss.rowid DESC LIMIT ' . self::SNAPSHOTS,
        );
        $statement->execute(['project' => $projectId, 'active' => $active]);
        $byHead = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // The newest scan of a commit stands for it: its graph is the most complete.
            $byHead[(string) $row['git_head']] ??= $row;
        }
        $pick = static fn(array $row, string $match, int $commits): array => ['snapshot_id' => (string) $row['id'], 'rev' => (string) $row['git_head'], 'at' => (string) $row['started_at'], 'match' => $match, 'commits' => $commits];
        if (isset($byHead[$mergeBase])) {
            return $pick($byHead[$mergeBase], 'exact', 0);
        }
        $before = $after = null;
        foreach (array_slice(array_keys($byHead), 0, self::HEADS_CHECKED) as $commit) {
            if ($this->git($root, ['merge-base', '--is-ancestor', $commit, $mergeBase]) !== null) {
                $distance = (int) ($this->git($root, ['rev-list', '--count', $commit . '..' . $mergeBase]) ?? PHP_INT_MAX);
                $before = $before === null || $distance < $before[1] ? [$commit, $distance] : $before;
            } elseif ($this->git($root, ['merge-base', '--is-ancestor', $mergeBase, $commit]) !== null) {
                $distance = (int) ($this->git($root, ['rev-list', '--count', $mergeBase . '..' . $commit]) ?? PHP_INT_MAX);
                $after = $after === null || $distance < $after[1] ? [$commit, $distance] : $after;
            }
        }
        if ($before !== null) {
            return $pick($byHead[$before[0]], 'before', $before[1]);
        }

        return $after === null ? null : $pick($byHead[$after[0]], 'after', $after[1]);
    }

    /**
     * A git command's trimmed output in `$root`, or null when it fails, times
     * out or says nothing (`--is-ancestor` answers by its exit status alone,
     * so its success is an empty string).
     *
     * @param list<string> $args
     */
    private function git(string $root, array $args): ?string
    {
        try {
            $out = trim($this->git->run(['git', '-c', 'core.quotePath=false', '--no-optional-locks', '--no-pager', '-C', $root, ...$args], self::GIT_TIMEOUT_MS, 'branch diff'));
        } catch (Throwable) {
            return null;
        }
        return $out === '' && !in_array('--is-ancestor', $args, true) ? null : $out;
    }
}
