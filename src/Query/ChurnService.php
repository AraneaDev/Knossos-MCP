<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Git\{GitProcessRunner, GitProcessRunnerInterface};
use PDO;
use Throwable;

/**
 * The architecture pane's churn hotspots: the files changed most often in
 * the last {@see self::DAYS} days that much of the project depends on.
 *
 * A file's score is its commits in the window times the files that depend
 * on it: a file edited often and depended on widely is where a change is
 * both likely and far-reaching. The commits come from one `git log`, bounded
 * in commits read ({@see self::COMMITS}) and in time; the dependents from
 * one grouped read of the graph for the files git named. Files the graph
 * does not hold (deleted since, or not scanned) are left out.
 *
 * Read-only, never scans. Without git, or when git does not answer, the
 * status says `no-git` and nothing is listed.
 */
final readonly class ChurnService
{
    /** The window, in days. */
    public const DAYS = 30;

    /** The most commits read from the window, newest first. */
    public const COMMITS = 500;

    /** The most files ranked: the ones changed most often. */
    private const FILES = 300;

    /** Files listed, the highest score first. */
    public const LIMIT = 40;

    /** How long git may take. */
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
     * The churn hotspots of the project owning `$path`, or an `unscanned` or
     * `no-git` envelope. `head` is the commit the window ends at: what the
     * pane keeps the answer for.
     *
     * @return array<string, mixed>
     */
    public function churn(string $path): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = ['path' => $absolute, 'project_id' => null, 'snapshot_id' => null, 'days' => self::DAYS, 'head' => null, 'commits' => 0, 'truncated' => false, 'files' => []];
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return ['status' => 'unscanned'] + $envelope;
        }
        $id = (string) $project['id'];
        $root = (string) $project['root_realpath'];
        $envelope = ['project_id' => $id, 'snapshot_id' => $project['active_scan_id']] + $envelope;
        $log = $this->log($root);
        if ($log === null) {
            return ['status' => 'no-git'] + $envelope;
        }
        [$head, $commits, $counts] = $log;
        arsort($counts);
        $counts = array_slice($counts, 0, self::FILES, true);
        $dependents = $this->dependents($id, array_map('strval', array_keys($counts)));
        $files = [];
        foreach ($counts as $file => $count) {
            if (isset($dependents[$file])) {
                $files[] = ['path' => (string) $file, 'commits' => $count, 'dependents' => $dependents[$file], 'score' => $count * $dependents[$file]];
            }
        }
        usort($files, static fn(array $a, array $b): int => [$b['score'], $b['commits'], $a['path']] <=> [$a['score'], $a['commits'], $b['path']]);
        $files = array_slice($files, 0, self::LIMIT);
        $labels = BoundaryLabels::load($this->pdo, $id)->forFiles($id, array_column($files, 'path'));

        return [
            'status' => 'ok',
            'head' => $head,
            'commits' => $commits,
            'truncated' => $commits >= self::COMMITS,
            'files' => array_map(static fn(array $f): array => $f + ['boundary' => $labels[$f['path']] ?? null], $files),
        ] + $envelope;
    }

    /**
     * The commit the window ends at, how many commits it read and how many of
     * them changed each file (relative to the project root); null without git.
     *
     * @return array{0: string, 1: int, 2: array<string, int>}|null
     */
    private function log(string $root): ?array
    {
        $git = ['git', '-c', 'core.quotePath=false', '--no-optional-locks', '--no-pager', '-C', $root];
        try {
            $head = trim($this->git->run([...$git, 'rev-parse', '--verify', '-q', 'HEAD'], self::GIT_TIMEOUT_MS, 'churn'));
            if ($head === '') {
                return null;
            }
            $out = $this->git->run([...$git, 'log', '--since=' . self::DAYS . '.days.ago', '--max-count=' . self::COMMITS, '--no-merges', '--no-renames', '--format=%x1e', '--name-only', '--relative', '--', '.'], self::GIT_TIMEOUT_MS, 'churn');
        } catch (Throwable) {
            return null;
        }
        $counts = [];
        $commits = 0;
        foreach (explode("\x1E", $out) as $index => $record) {
            if ($index === 0) {
                continue;
            }
            ++$commits;
            $changed = array_unique(array_filter(array_map('trim', explode("\n", $record)), static fn(string $line): bool => $line !== ''));
            foreach ($changed as $file) {
                $counts[$file] = ($counts[$file] ?? 0) + 1;
            }
        }

        return [$head, $commits, $counts];
    }

    /**
     * How many other files depend on each of `$paths` the graph holds, by
     * path; a file nothing depends on counts 0, a file the graph does not
     * hold is absent.
     *
     * @param list<string> $paths
     * @return array<string, int>
     */
    private function dependents(string $projectId, array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($paths), '?'));
        $known = $this->pdo->prepare("SELECT relative_path FROM files WHERE project_id = ? AND relative_path IN ({$marks})");
        $known->execute([$projectId, ...$paths]);
        $counts = array_fill_keys(array_map('strval', $known->fetchAll(PDO::FETCH_COLUMN)), 0);
        $kinds = implode(',', array_fill(0, count(AbstractArchitectureQueryService::IMPACT_EDGE_KINDS), '?'));
        $statement = $this->pdo->prepare(
            'SELECT tf.relative_path, COUNT(DISTINCT sn.file_id) AS dependents FROM edges e '
            . 'JOIN nodes tn ON tn.id = e.target_id JOIN files tf ON tf.id = tn.file_id JOIN nodes sn ON sn.id = e.source_id '
            . "WHERE e.project_id = ? AND e.kind IN ({$kinds}) AND sn.file_id IS NOT NULL AND sn.file_id <> tn.file_id AND tf.relative_path IN ({$marks}) "
            . 'GROUP BY tf.id',
        );
        $statement->execute([$projectId, ...AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, ...$paths]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['relative_path']] = (int) $row['dependents'];
        }

        return $counts;
    }
}
