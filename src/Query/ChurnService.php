<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Git\{GitLogRecords, GitProcessRunner, GitProcessRunnerInterface};
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
 * Read-only, never scans. Without git, or when git cannot name the commit
 * checked out, the status says `no-git` and nothing is listed; a log too
 * large to read whole is read shorter and `truncated`, and one git cannot
 * print even shorter says `unreadable`, never an empty window.
 */
final readonly class ChurnService
{
    /** The window, in days. */
    public const DAYS = 30;

    /** The most commits read from the window, newest first. */
    public const COMMITS = 500;

    /** The commits read again when the whole window's log is more than git may print. */
    private const FEWER = 50;

    /** The most files ranked: the ones changed most often. */
    private const FILES = 300;

    /** Files listed, the highest score first. */
    public const LIMIT = 40;

    /** What starts each commit in the log; read NUL-separated so a path git would quote keeps its real name. */
    private const MARKER = "KNOSSOS_CHURN\x1f";

    /** {@see self::MARKER} as `git log --format` writes it. */
    private const MARKER_FORMAT = 'KNOSSOS_CHURN%x1f';

    /** How long git may take. */
    private const GIT_TIMEOUT_MS = 3000;

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param GitProcessRunnerInterface|null $runner how git is run when a test stands in; the hardened runner otherwise
     */
    public function __construct(private PDO $pdo, private ?GitProcessRunnerInterface $runner = null) {}

    /**
     * The churn hotspots of the project owning `$path`, or an `unscanned`,
     * `no-git` or `unreadable` envelope. `head` is the commit the window ends at: what the
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
        if ($log === false) {
            // git named the commit but printed no log, not even a shorter one: nothing was read, which is no empty window.
            return ['status' => 'unreadable'] + $envelope;
        }
        [$head, $commits, $counts, $cut] = $log;
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
            'truncated' => $cut || $commits >= self::COMMITS,
            'files' => array_slice($files, 0, self::LIMIT),
        ] + $envelope;
    }

    /**
     * The commit the window ends at, how many commits it read, how many of
     * them changed each file (relative to the project root), and whether the
     * log was cut short; null without git.
     *
     * A window whose log git cannot print within its bounds (a huge commit,
     * or a slow repository) is read again over the newest
     * {@see self::FEWER} commits, and said to be cut; only when that fails
     * too is nothing read, and false says so. The repository is there either
     * way: it is never called `no-git` for being large.
     *
     * @return array{0: string, 1: int, 2: array<string, int>, 3: bool}|false|null
     */
    private function log(string $root): array|false|null
    {
        $git = ['git', '-c', 'core.quotePath=false', '--no-optional-locks', '--no-pager', '-C', $root];
        $runner = $this->runner ?? new GitProcessRunner();
        try {
            $head = trim($runner->run([...$git, 'rev-parse', '--verify', '-q', 'HEAD'], self::GIT_TIMEOUT_MS, 'churn'));
        } catch (Throwable) {
            return null;
        }
        if ($head === '') {
            return null;
        }
        $out = null;
        foreach ([self::COMMITS, self::FEWER] as $count) {
            try {
                $out = $runner->run([...$git, 'log', '--since=' . self::DAYS . '.days.ago', '--max-count=' . $count, '--no-merges', '--no-renames', '-z', '--format=' . self::MARKER_FORMAT, '--name-only', '--relative', '--', '.'], self::GIT_TIMEOUT_MS, 'churn');
                break;
            } catch (Throwable) {
                continue;
            }
        }
        if ($out === null) {
            return false;
        }
        $cut = $count !== self::COMMITS;
        $counts = [];
        $records = GitLogRecords::parse($out, self::MARKER);
        foreach ($records as $record) {
            foreach (array_unique($record['paths']) as $file) {
                $counts[$file] = ($counts[$file] ?? 0) + 1;
            }
        }
        $commits = count($records);

        return [$head, $commits, $counts, $cut];
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
