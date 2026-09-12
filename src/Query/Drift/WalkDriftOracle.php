<?php

declare(strict_types=1);

namespace Knossos\Query\Drift;

use PDO;

/**
 * Detects drift by walking the filesystem directly against the tracked-file rows.
 *
 * Bounded to keep a freshness check cheap: above {@see self::MAX_PROBED_FILES}
 * tracked files it declines to answer rather than turn a probe into a full
 * tree walk.
 *
 * Every present tracked file is read and hashed on each call, not stat'd:
 * content is the only thing that decides drift, so there is no cheaper check
 * that stays correct. This runs on every enriched query result, so a project
 * near the {@see self::MAX_PROBED_FILES} ceiling pays up to that many file
 * reads and SHA-256 hashes per tool call. Two things keep that bound tolerable
 * in practice rather than in theory: discovery caps a tracked file at 2 MB,
 * and a later oracle in this project's plan answers from git for a git
 * repository ahead of this one, so once that lands this walk only runs at all
 * for a small or gitless project.
 */
final readonly class WalkDriftOracle implements DriftOracle
{
    /** Tracked files above which the walk is skipped and freshness reported as unverified. */
    private const MAX_PROBED_FILES = 20_000;

    /** Entries examined per directory when looking for additions; opening directories is the expensive half. */
    private const MAX_ADDITION_ENTRIES = 500;

    /**
     * Additions counted before the walk stops, a different quantity from the
     * entries it examines to find them. One number served as both, which read
     * as a single bound and was two.
     */
    private const MAX_ADDITIONS_COUNTED = 500;

    public function __construct(private PDO $pdo) {}

    /**
     * What changed on disk since the scan: content edits, additions, and
     * deletions. All three matter — an mtime-only comparison reported a graph
     * as fresh after files were added or removed, which is the failure mode
     * this probe exists to prevent.
     *
     * Bounded: above self::MAX_PROBED_FILES tracked files the walk is skipped
     * and the caller reports 'unverified' rather than guessing.
     *
     * @param ?string $finishedAt when the active scan finished, already read by
     *   the caller — passed down rather than queried again.
     */
    public function drift(string $projectId, string $activeScanId, string $root, ?string $finishedAt): ?DriftCounts
    {
        if (!is_dir($root)) {
            return null;
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM files WHERE project_id = :project AND last_scan_id = :scan');
        $count->execute(['project' => $projectId, 'scan' => $activeScanId]);
        if ((int) $count->fetchColumn() > self::MAX_PROBED_FILES) {
            return null; // bound exceeded; omit best-effort fields
        }
        $statement = $this->pdo->prepare(
            'SELECT relative_path, content_hash FROM files WHERE project_id = :project AND last_scan_id = :scan LIMIT ' . self::MAX_PROBED_FILES,
        );
        $statement->execute(['project' => $projectId, 'scan' => $activeScanId]);
        $changed = 0;
        $deleted = 0;
        $directories = [];
        foreach ($statement->fetchAll() as $file) {
            $absolute = $root . '/' . $file['relative_path'];
            // filemtime() here is only an existence probe, not a comparison: a
            // moved mtime does not prove the bytes changed (a `touch` or a
            // `git checkout` that restores identical content moves it for
            // free), and an unmoved one does not prove they didn't (a
            // filesystem with a coarse clock can hide a real edit inside the
            // same tick). Both cases require reading the file, so the mtime
            // buys nothing as a prefilter; it only tells us the file is still
            // there.
            if (@filemtime($absolute) === false) {
                ++$deleted;
                continue;
            }
            $hash = @hash_file('sha256', $absolute);
            if ($hash === false) {
                ++$deleted;
                continue;
            }
            if ($hash !== (string) $file['content_hash']) {
                ++$changed;
            }
            $directories[dirname($absolute)][basename($absolute)] = true;
        }

        return new DriftCounts($changed, $this->addedSince($directories, $finishedAt, ScannedPaths::forProject($this->pdo, $projectId), $root), $deleted);
    }

    /**
     * Entries that appeared since the scan, in the directories that hold
     * tracked files.
     *
     * A directory's mtime changes when an entry is created *or* unlinked
     * inside it, so the mtime alone cannot tell the two apart: counting one
     * addition per drifted directory reported a pure deletion as both a
     * deletion and an addition. The mtime is therefore only a filter for which
     * directories are worth opening; the count comes from the entries
     * themselves — those absent from the tracked-path set, that {@see
     * ScannedPaths} says the scanner would have picked up, and whose own inode
     * change time is later than the scan, so an untracked entry that has sat
     * there since before the scan is not counted every time a sibling moves.
     *
     * Two limits remain, both consequences of the {@see
     * self::MAX_PROBED_FILES} bound this probe works under rather than
     * oversights. Neither can be closed without walking the tree, which is the
     * cost the bound exists to avoid:
     *
     * - A new directory is seen only when its parent holds a tracked file.
     *   Nothing points at a subtree with no tracked file in it, so a new
     *   directory created there is invisible to this check.
     * - Only the first {@see self::MAX_ADDITION_ENTRIES} untracked entries of
     *   a directory are examined, so an addition sitting behind that many
     *   others in the same directory is missed. The bound is per directory
     *   rather than per probe on purpose: one crowded directory next to a
     *   small source tree must not exhaust the budget and report the tree as
     *   fresh.
     *
     * @param array<string, array<string, true>> $directories directory => tracked basenames within it
     * @param ?string $finishedAt when the active scan finished
     */
    private function addedSince(array $directories, ?string $finishedAt, ScannedPaths $scanned, string $root): int
    {
        if ($finishedAt === null) {
            return 0;
        }
        $scannedAt = strtotime($finishedAt);
        if ($scannedAt === false) {
            return 0;
        }
        $added = 0;
        foreach ($directories as $directory => $tracked) {
            $mtime = @filemtime($directory);
            if ($mtime === false || $mtime <= $scannedAt) {
                continue;
            }
            // Read incrementally rather than with scandir(): the bound below
            // has to stop the enumeration itself, and scandir() materialises
            // the whole listing before the first entry is looked at. A
            // directory holding a hundred thousand untracked entries must not
            // turn a freshness probe into a full enumeration, and counting
            // only the entries that turned out to be additions bounded the
            // stat() calls while leaving the listing unbounded.
            $handle = @opendir($directory);
            if ($handle === false) {
                continue;
            }
            try {
                $examined = 0;
                while ($examined < self::MAX_ADDITION_ENTRIES && ($entry = readdir($handle)) !== false) {
                    if ($entry === '.' || $entry === '..' || isset($tracked[$entry])) {
                        continue;
                    }
                    $absolute = $directory . '/' . $entry;
                    $relative = ltrim(substr($absolute, strlen($root)), '/');
                    if (!$scanned->tracks($relative, $absolute)) {
                        continue;
                    }
                    ++$examined;
                    $createdAt = @filectime($absolute);
                    if ($createdAt !== false && $createdAt > $scannedAt) {
                        ++$added;
                    }
                    // Enough drift to report; what the rest of the tree holds
                    // cannot change the answer.
                    if ($added >= self::MAX_ADDITIONS_COUNTED) {
                        return $added;
                    }
                }
            } finally {
                closedir($handle);
            }
        }

        return $added;
    }
}
