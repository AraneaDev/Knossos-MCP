<?php

declare(strict_types=1);

namespace Knossos\Query\Drift;

use Knossos\Git\DirtyPathSet;
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
 * git's own universe still cannot show it a file `.gitignore` excludes that
 * the scanner tracks anyway, which is what the visibility cross-check is for.
 * The question it asks is not "is this path in the index" but "can git name
 * this path at all": a path in any of the three listings this oracle already
 * gathers — `ls-files --cached` (committed), `ls-files --others
 * --exclude-standard` (untracked but not ignored), or `diff --name-only`
 * (changed or deleted versus HEAD) — is visible to git, whatever its index
 * status. The index alone is not enough: an uncommitted new file lives only
 * in `--others`, and a staged rename's or delete's old path lives only in
 * `diff`, so checking the index in isolation declined on ordinary uncommitted
 * work, not only on the gap this check exists to close. Whenever the
 * cross-check runs (at or below {@see self::MAX_CROSS_CHECKED_FILES} tracked
 * files) and finds a tracked row absent from all three listings, this oracle
 * declines outright — null, never a zero or a partial count — so {@see
 * FirstAnsweringDriftOracle} falls through to the walk, which sees such a
 * file directly through {@see ScannedPaths} rather than through git.
 *
 * That guarantee holds only where the cross-check actually ran, or was never
 * attempted because the project is above the file-count bound. Above that
 * bound this oracle proceeds anyway, deliberately: the walk cannot take over
 * for such a repository either, since its ceiling is the same number, so
 * declining there would trade a possible miss for a certain `unverified` on
 * every probe against it.
 *
 * Below that bound, though, when the cross-check was attempted and the
 * `ls-files --cached` call itself failed (its own try/catch, below — most
 * likely on a large repository with long paths, approaching
 * {@see \Knossos\Git\GitProcessRunner}'s own output ceiling), this oracle
 * declines outright rather than deciding from `diff` and `--others` alone.
 * Without the index listing, a file `.gitignore` excludes that the scanner
 * tracks anyway is absent from both remaining listings, which is exactly the
 * gap the cross-check exists to close — so proceeding here can return a
 * non-null zero for a project that is not actually fresh, and
 * {@see FirstAnsweringDriftOracle} stops at that answer without ever running
 * the walk that would have caught it. A false `fresh` is the one outcome
 * this oracle exists to avoid, so it declines even in the large-repository
 * case it otherwise tries hard to serve, leaving the walk (or `unverified`,
 * if the walk is also beyond its own ceiling) to answer instead.
 *
 * One gap survives even where the guarantee holds: a file `.gitignore`
 * excludes that the scanner would track for the *first* time has no `files`
 * row yet, so the cross-check has nothing to compare it against — it appears
 * in none of the three listings either. This does not heal on its own:
 * nothing rescans a repository where that new file is the only change, so it
 * stays invisible indefinitely, until something else triggers a rescan or a
 * full one is run by hand. Once such a file has been scanned once, though,
 * its row exists and every later edit or deletion to it is caught by the
 * cross-check like any other tracked file.
 *
 * Git's listings are also blind to a file the scan read while it was
 * modified relative to HEAD and the user has since restored: `diff` no longer
 * names it (it matches HEAD again), `--others` never did (it is tracked), and
 * the cross-check finds it exactly where it belongs — while the hash the scan
 * stored is of content that is no longer on disk. The scan records the paths
 * that were dirty when it read them for precisely this, and every one of them
 * is a candidate on every probe, whatever the three listings say now. A scan
 * that recorded no such set (a graph built before the column existed, or one
 * whose dirty listing git could not answer, or one dirty past the recorded
 * bound) cannot rule that case out, so this oracle declines for it rather
 * than reporting a freshness it never verified.
 *
 * A narrower spurious decline can still occur: a path-normalisation mismatch
 * between what the scan stored and what git reports — a case-insensitive
 * filesystem where the two differ only in case is the clearest example —
 * would make a visible path fail to match its `files` row by string key, and
 * the row would read as invisible to all three listings when it is not
 * actually so. Harmless, since it only hands an ordinary case to the walk one
 * probe early, and rare enough not to be worth a normalisation pass here.
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
     * Tracked files up to which git's visibility into them is cross-checked
     * against the graph, and the boundary of the decline guarantee described
     * in the class docblock, not only a performance cutoff.
     *
     * At or below it, a tracked row git cannot name by any of its three
     * listings (`--cached`, `--others --exclude-standard`, `diff`) declines
     * the oracle outright, so the walk answers from a complete view instead
     * of this one from an incomplete one. Above it, the cross-check is
     * skipped and this oracle proceeds as though git's universe were
     * complete anyway: the walk cannot help a project this large either, so
     * declining here would only trade a possible miss for a certain
     * `unverified` on every probe against it.
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
        // Before any subprocess: a scan with no trustworthy dirty set cannot
        // be decided from git's listings at all, so there is nothing to spend
        // three git calls on.
        $dirty = $this->dirtyPaths($activeScanId);
        if ($dirty === null) {
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
                // this listing is what the cross-check needs, not a
                // precondition for the oracle overall. On a large repository
                // with long paths it is the call most likely to approach
                // GitProcessRunner's maxOutputBytes, and at exactly the
                // repository size where the walk is also near its own
                // ceiling — but proceeding on diff and untracked candidates
                // alone here would lose exactly the coverage the cross-check
                // exists to add: a file .gitignore excludes that the scanner
                // tracks anyway is absent from both remaining listings, so
                // this oracle could return a non-null zero for a project that
                // is not actually fresh. That is a false 'fresh', the one
                // outcome worse than answering unverified, so this declines
                // rather than proceeding.
                error_log('knossos drift query: git ls-files --cached could not answer (' . $error->getMessage() . ')');
                return null;
            }
        }

        $changedEntries = self::entries($changedOutput);
        $untrackedEntries = self::entries($untrackedOutput);
        $tracked = $crossCheck ? $this->trackedHashes($projectId, $activeScanId, null) : [];
        if ($crossCheck) {
            // Visible, not merely indexed: a path git can name by any of the
            // three listings it was asked for is a path git can decide, even
            // when the index alone does not hold it — an uncommitted new
            // file lives only in `--others`, and a staged rename's or
            // delete's old path lives only in `diff`. Checking the index in
            // isolation declined on both of those, which are the normal shape
            // of uncommitted work, not the gap this check exists to close.
            $visible = self::entries($indexedOutput) + $untrackedEntries + $changedEntries;
            if (array_diff_key($tracked, $visible) !== []) {
                // A tracked row git cannot name by any of its three listings:
                // this oracle's view of the project is incomplete, not merely
                // thin. Declining outright — null, not a zero or a partial
                // count over just the candidates diff and untracked already
                // named — is what lets the walk answer from a complete view
                // instead. See the class docblock for where this guarantee
                // holds, where it deliberately does not, and the residual
                // spurious decline this same check can still produce.
                return null;
            }
        }
        // The dirty set joins the candidates rather than the drift count:
        // each of its paths is still decided against the hash the scan stored
        // for it, so one that has not actually moved reports no drift.
        $candidates = $changedEntries + $untrackedEntries + $dirty->asKeys();
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

    /**
     * The paths the scan recorded as differing from its commit, or null when
     * it recorded none this oracle can trust.
     *
     * Null is every shape that is not a complete recorded set — see
     * {@see DirtyPathSet::decode()} — and all of them mean the same thing
     * here: a file scanned dirty and since restored cannot be ruled out, so
     * the walk must answer instead.
     */
    private function dirtyPaths(string $activeScanId): ?DirtyPathSet
    {
        $statement = $this->pdo->prepare('SELECT dirty_paths_json FROM scans WHERE id = :id');
        $statement->execute(['id' => $activeScanId]);
        $raw = $statement->fetchColumn();

        return DirtyPathSet::decode(is_string($raw) ? $raw : null);
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
