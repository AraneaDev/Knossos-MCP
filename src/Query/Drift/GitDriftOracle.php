<?php

declare(strict_types=1);

namespace Knossos\Query\Drift;

use Knossos\Git\GitProcessRunner;
use Knossos\Git\GitProcessRunnerInterface;
use PDO;
use Throwable;

/**
 * Drift since the scan, with git used to narrow what has to be looked at.
 *
 * Git names the candidates; the graph's own stored hashes decide them. The
 * distinction is the whole point. `git diff <recorded head>` compares the
 * commit to the working tree, but the scan built its graph *from* the working
 * tree, so every uncommitted edit the scan already absorbed reads as drift on
 * that comparison, and reads as drift forever: a rescan records the same HEAD,
 * because saving a file does not move it.
 *
 * Asking git only for the candidate set closes three divergences at once. A
 * changed file the scanner does not track (a README, a lockfile, an image)
 * falls out, because it has no row in `files`. A sibling package's churn falls
 * out, because the diff is scoped to the scanned root. And an edit the scan
 * already holds hashes equal, so it is correctly reported as no drift.
 *
 * It answers only when the scan recorded a commit that still resolves; every
 * other case returns null and the walk takes over. Cost stays proportional to
 * the change set rather than to the repository, which is what lets this answer
 * for a tree far above the walk's own ceiling.
 *
 * One gap survives even the index cross-check: a file `.gitignore` excludes
 * that the scanner would track for the *first* time — new since the active
 * scan, so it has no `files` row yet — appears in neither `diff` nor
 * `ls-files --others --exclude-standard`, and the cross-check has nothing
 * stored to notice it by. Only the walk catches it. Declining whenever the
 * cross-check finds any gitignored-but-scanned row was considered and
 * rejected: it would hand every large project with even one such file
 * permanently to the walk's own ceiling, trading the fast path away for
 * exactly the repositories it exists to serve, to close a gap that a full
 * scan or the walk already closes on its own.
 */
final readonly class GitDriftOracle implements DriftOracle
{
    private const TIMEOUT_MS = 2000;

    /**
     * Candidates above which the probe declines rather than becoming the walk
     * it exists to avoid. Deliberately the walk's own ceiling: a change set
     * this large is a graph nobody should be answering queries from anyway.
     */
    private const MAX_CANDIDATES = 20_000;

    /**
     * Tracked files up to which the index is cross-checked against the graph.
     *
     * The cross-check is what keeps this oracle as sensitive as the walk for a
     * file `.gitignore` excludes and the scanner tracks anyway: git reports no
     * change to a file it does not follow, and a zero from here ends the chain
     * before the walk can look. Reading the tracked set costs one query and no
     * file I/O, so it is affordable exactly as far as the walk's ceiling; above
     * it this oracle is the only one that answers at all, and answering from
     * git's narrower universe beats reporting the graph unverified.
     */
    private const MAX_CROSS_CHECKED_FILES = 20_000;

    /** Paths per hash lookup, well inside SQLite's own placeholder ceiling. */
    private const PLACEHOLDERS_PER_QUERY = 400;

    /** Shared prefix of both hash lookups, so the narrowed one cannot drift from the whole-scan one. */
    private const HASH_SQL = 'SELECT relative_path, content_hash FROM files WHERE project_id = ? AND last_scan_id = ?';

    private GitProcessRunnerInterface $runner;

    /** Defaults to a real subprocess runner; a test double replaces it without touching callers. */
    public function __construct(
        private PDO $pdo,
        ?GitProcessRunnerInterface $runner = null,
    ) {
        $this->runner = $runner ?? new GitProcessRunner();
    }

    /**
     * Drift since the scan, decided by content hash over the paths git names.
     *
     * `$finishedAt` is unused: every candidate is decided against the hash the
     * scan stored for it, so there is no wall-clock boundary left for this
     * oracle to need. It stays in the signature only because {@see DriftOracle}
     * is shared with the walk, which does need it.
     *
     * Every failure path (no recorded head, an unparseable one, a commit that
     * no longer resolves, git unavailable or over its timeout, a change set too
     * large to decide) returns null rather than a zero count, so an oracle that
     * could not check never reports a graph as fresh it never verified.
     */
    public function drift(string $projectId, string $activeScanId, string $root, ?string $finishedAt): ?DriftCounts
    {
        $statement = $this->pdo->prepare('SELECT git_head FROM scans WHERE id = :id');
        $statement->execute(['id' => $activeScanId]);
        $head = $statement->fetchColumn();
        // The two lengths git emits, SHA-1 and SHA-256, and nothing between them.
        if (!is_string($head) || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $head) !== 1) {
            return null;
        }
        $crossCheck = $this->trackedFileCount($projectId, $activeScanId) <= self::MAX_CROSS_CHECKED_FILES;
        try {
            // Verify first. Handing a garbage-collected or rebased-away commit
            // to `diff` fails anyway, but failing here says why, and says it
            // before the more expensive calls.
            $this->run($root, ['rev-parse', '--verify', $head . '^{commit}']);
            // --relative reports paths relative to the scanned root and the
            // pathspec keeps a monorepo's sibling packages out; `git diff` is
            // otherwise repository-wide however it is invoked. --no-renames so
            // a move arrives as both of its paths, which is what a graph has to
            // be rebuilt for.
            $changedOutput = $this->run($root, ['diff', '--name-only', '-z', '--no-ext-diff', '--no-renames', '--relative', $head, '--', $root]);
            $untrackedOutput = $this->run($root, ['ls-files', '--others', '--exclude-standard', '-z', '--']);
        } catch (Throwable $error) {
            // A permanently silent fast path is undiagnosable: this oracle
            // failing simply means the walk answers, which looks like nothing
            // at all from outside. Never stdout, which carries protocol frames.
            error_log('knossos drift query: git could not answer (' . $error->getMessage() . ')');
            return null;
        }

        $indexedOutput = '';
        if ($crossCheck) {
            try {
                $indexedOutput = $this->run($root, ['ls-files', '--cached', '-z', '--']);
            } catch (Throwable $error) {
                // Deliberately its own try/catch, distinct from the one above:
                // this listing is an addition to what the oracle can decide,
                // not a precondition for it. On a large repository with long
                // paths it is the call most likely to approach
                // GitProcessRunner's maxOutputBytes, and at exactly the
                // repository size where the walk is also near its own
                // ceiling. Losing it costs only the gitignored-but-scanned
                // coverage the cross-check adds; folding its failure into the
                // block above would cost the whole oracle instead, for a
                // reason unrelated to whether diff and untracked candidates
                // could still answer.
                error_log('knossos drift query: git ls-files --cached could not answer, cross-check skipped (' . $error->getMessage() . ')');
                $crossCheck = false;
            }
        }

        $tracked = $crossCheck ? $this->trackedHashes($projectId, $activeScanId, null) : [];
        $candidates = self::entries($changedOutput) + self::entries($untrackedOutput);
        if ($crossCheck) {
            // Files the graph holds that git does not follow at all. Edits to
            // them are invisible to `diff`, so they are decided every probe.
            $candidates += array_fill_keys(array_keys(array_diff_key($tracked, self::entries($indexedOutput))), true);
        }
        if (count($candidates) > self::MAX_CANDIDATES) {
            return null;
        }
        $hashes = $crossCheck ? $tracked : $this->trackedHashes($projectId, $activeScanId, array_keys($candidates));

        return $this->decide($projectId, $root, array_keys($candidates), $hashes);
    }

    /**
     * Each candidate weighed against the hash the scan stored for it, which is
     * the same rule the walk applies and the reason the two agree.
     *
     * @param list<string> $candidates paths relative to $root
     * @param array<string, string> $hashes relative path => content hash as scanned
     */
    private function decide(string $projectId, string $root, array $candidates, array $hashes): DriftCounts
    {
        $changed = 0;
        $added = 0;
        $deleted = 0;
        $scanned = null;
        foreach ($candidates as $path) {
            $absolute = $root . '/' . $path;
            if (array_key_exists($path, $hashes)) {
                $hash = @hash_file('sha256', $absolute);
                if ($hash === false) {
                    ++$deleted;
                    continue;
                }
                if ($hash !== $hashes[$path]) {
                    ++$changed;
                }
                continue;
            }
            if (!is_file($absolute)) {
                continue;
            }
            // Built lazily: a probe whose candidates are all tracked files
            // never needs the project's ignore configuration at all.
            $scanned ??= ScannedPaths::forProject($this->pdo, $projectId);
            if ($scanned->tracks($path, $absolute)) {
                ++$added;
            }
        }

        return new DriftCounts($changed, $added, $deleted);
    }

    /** How many files the active scan tracks, which decides whether the index is worth cross-checking. */
    private function trackedFileCount(string $projectId, string $activeScanId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM files WHERE project_id = :project AND last_scan_id = :scan');
        $statement->execute(['project' => $projectId, 'scan' => $activeScanId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Content hashes as the scan stored them, for every tracked file or only
     * for the named paths.
     *
     * Chunked when narrowed, so a large change set cannot build a statement
     * with more placeholders than SQLite accepts.
     *
     * @param list<string>|null $paths null for every tracked file
     * @return array<string, string> relative path => content hash
     */
    private function trackedHashes(string $projectId, string $activeScanId, ?array $paths): array
    {
        if ($paths === null) {
            return $this->hashRows(self::HASH_SQL, [$projectId, $activeScanId]);
        }
        $hashes = [];
        foreach (array_chunk($paths, self::PLACEHOLDERS_PER_QUERY) as $chunk) {
            $hashes += $this->hashRows(
                self::HASH_SQL . ' AND relative_path IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                array_merge([$projectId, $activeScanId], $chunk),
            );
        }

        return $hashes;
    }

    /**
     * One hash lookup, positionally bound.
     *
     * @param list<string> $parameters
     * @return array<string, string> relative path => content hash
     */
    private function hashRows(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $hashes = [];
        foreach ($statement->fetchAll() as $row) {
            $hashes[(string) $row['relative_path']] = (string) $row['content_hash'];
        }

        return $hashes;
    }

    /**
     * Runs one git subcommand against $root under the fixed timeout and
     * operation label every call here shares.
     *
     * @param list<string> $arguments
     */
    private function run(string $root, array $arguments): string
    {
        return $this->runner->run(
            array_merge(['git', '--no-optional-locks', '--no-pager', '-C', $root], $arguments),
            self::TIMEOUT_MS,
            'drift query',
        );
    }

    /**
     * Non-empty NUL-separated entries as a set, which is how every `-z` listing
     * is read and how two listings are unioned without counting a path twice.
     *
     * @return array<string, true>
     */
    private static function entries(string $output): array
    {
        $entries = [];
        foreach (explode("\0", rtrim($output, "\0")) as $entry) {
            if ($entry !== '') {
                $entries[$entry] = true;
            }
        }

        return $entries;
    }
}
