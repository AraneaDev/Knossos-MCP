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
 * graph for the files git named, counted as the fan-in map counts them
 * ({@see FileFanInQuery}). Files the graph does not hold (deleted since, or
 * not scanned) are left out.
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

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param GitProcessRunnerInterface|null $runner how git is run when a test stands in; the hardened runner otherwise
     */
    public function __construct(private PDO $pdo, private ?GitProcessRunnerInterface $runner = null) {}

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
        $known = $this->known($id, array_map('strval', array_keys($counts)));
        // Dependents and the file's own boundary as the fan-in map and the file detail count them.
        $fanIn = $known === [] ? [] : (new FileFanInQuery($this->pdo))->forPaths($id, $known, 0);
        $files = [];
        foreach ($fanIn as $file => $row) {
            $count = $counts[$file];
            $files[] = ['path' => $file, 'commits' => $count, 'dependents' => $row['dependent_files'], 'score' => $count * $row['dependent_files'], 'boundary' => $row['boundary']];
        }
        usort($files, static fn(array $a, array $b): int => [$b['score'], $b['commits'], $a['path']] <=> [$a['score'], $a['commits'], $b['path']]);

        return [
            'status' => 'ok',
            'head' => $head,
            'commits' => $commits,
            'truncated' => $commits >= self::COMMITS,
            'files' => array_slice($files, 0, self::LIMIT),
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
        $runner = $this->runner ?? new GitProcessRunner();
        try {
            $head = trim($runner->run([...$git, 'rev-parse', '--verify', '-q', 'HEAD'], self::GIT_TIMEOUT_MS, 'churn'));
            if ($head === '') {
                return null;
            }
            $out = $runner->run([...$git, 'log', '--since=' . self::DAYS . '.days.ago', '--max-count=' . self::COMMITS, '--no-merges', '--no-renames', '--format=%x1e', '--name-only', '--relative', '--', '.'], self::GIT_TIMEOUT_MS, 'churn');
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
     * Those of `$paths` the graph holds, in the order given.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private function known(string $projectId, array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $statement = $this->pdo->prepare('SELECT relative_path FROM files WHERE project_id = ? AND relative_path IN (' . implode(',', array_fill(0, count($paths), '?')) . ')');
        $statement->execute([$projectId, ...$paths]);
        $held = array_flip(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));

        return array_values(array_filter($paths, static fn(string $path): bool => isset($held[$path])));
    }
}
