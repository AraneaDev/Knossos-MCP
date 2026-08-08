<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use PDO;

/**
 * Reports how far a project's graph has drifted from its source.
 *
 * Attached to query results so an answer from an out-of-date graph is visibly
 * qualified rather than silently wrong, and so tools can offer to rescan before
 * answering.
 */
final readonly class StalenessProbe
{
    /** Tracked files above which content probing is skipped and freshness reported as unverified. */
    private const MAX_PROBED_FILES = 500;

    private Closure $wallClock;

    public function __construct(private PDO $pdo, ?Closure $wallClock = null)
    {
        $this->wallClock = $wallClock ?? static fn(): int => time();
    }

    /**
     * How far a project's graph has drifted from its source, or null when it has none.
     *
     * @return array<string, mixed>|null
     */
    public function probe(string $projectId): ?array
    {
        // 'catalog' and 'server' are scopes, not projects: the tools using them
        // describe the server itself. Probing them found no project row and
        // reported state 'missing' with advice to run scan_project — a project
        // that does not exist, and a scan that would not change the answer.
        if ($projectId === '' || $projectId === 'catalog' || $projectId === 'server') {
            return null;
        }
        $project = $this->fetchProject($projectId);
        if ($project === null) {
            return $this->missing();
        }
        $activeScanId = $project['active_scan_id'];
        if (!is_string($activeScanId) || $activeScanId === '') {
            return $this->missing();
        }

        $finishedAt = $this->activeFinishedAt($activeScanId);
        $ageSeconds = $this->age($finishedAt);
        $newerAttempt = $this->hasNewerAttempt($projectId, $activeScanId);
        $drift = $this->changedFilesSince($projectId, $activeScanId, (string) $project['root_realpath'], $finishedAt);
        $drifted = $drift === null ? null : $drift['changed'] + $drift['added'] + $drift['deleted'];

        // 'unverified' when no newer scan attempt exists but content-change
        // detection was skipped (root missing, or too many files to fingerprint):
        // the graph cannot be confirmed fresh, and must not be reported as such.
        $state = match (true) {
            $newerAttempt || ($drifted !== null && $drifted > 0) => 'stale',
            $drifted === null => 'unverified',
            default => 'fresh',
        };
        $result = [
            'state' => $state,
            'scanned_at' => $finishedAt,
            'age_seconds' => $ageSeconds,
        ];
        if ($drift !== null) {
            $result['changed_files_since'] = $drift['changed'];
            $result['added_files_since'] = $drift['added'];
            $result['deleted_files_since'] = $drift['deleted'];
        }
        if ($state === 'stale') {
            $result['guidance'] = 'Graph may be stale; rescan with scan_project for current results.';
        } elseif ($state === 'unverified') {
            $result['guidance'] = 'Change detection was skipped (project root unavailable or too many files); freshness is unconfirmed. Rescan with scan_project to be certain.';
        }
        return $result;
    }

    /**
     * The shape returned when a project has no graph at all, which requires a scan rather than a refresh.
     *
     * @return array<string, mixed>
     */
    private function missing(): array
    {
        return [
            'state' => 'missing',
            'scanned_at' => null,
            'age_seconds' => null,
            'guidance' => 'No active graph for this project; call scan_project first.',
        ];
    }

    /**
     * The project row backing the probe.
     *
     * @return array<string, mixed>|null
     */
    private function fetchProject(string $projectId): ?array
    {
        $statement = $this->pdo->prepare('SELECT active_scan_id, root_realpath FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }
    /** When the active scan finished, the reference point for every age calculation. */

    private function activeFinishedAt(string $scanId): ?string
    {
        $statement = $this->pdo->prepare('SELECT finished_at FROM scans WHERE id = :id');
        $statement->execute(['id' => $scanId]);
        $row = $statement->fetch();
        if ($row === false || !is_string($row['finished_at'])) {
            return null;
        }
        return $row['finished_at'];
    }
    /** Seconds since the graph was built, which is what makes staleness legible. */

    private function age(?string $finishedAt): ?int
    {
        if ($finishedAt === null) {
            return null;
        }
        $then = strtotime($finishedAt);
        if ($then === false) {
            return null;
        }
        return max(0, ($this->wallClock)() - $then);
    }
    /** Whether a later scan attempt exists, so a failed rescan is distinguishable from never trying. */

    private function hasNewerAttempt(string $projectId, string $activeScanId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status FROM scans WHERE project_id = :project ORDER BY started_at DESC, id DESC LIMIT 1',
        );
        $statement->execute(['project' => $projectId]);
        $latest = $statement->fetch();
        if ($latest === false) {
            return false;
        }
        return $latest['id'] !== $activeScanId
            && in_array($latest['status'], ['running', 'failed', 'cancelled'], true);
    }
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
     * @return array{changed: int, added: int, deleted: int}|null
     */
    private function changedFilesSince(string $projectId, string $activeScanId, string $root, ?string $finishedAt): ?array
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
            'SELECT relative_path, mtime FROM files WHERE project_id = :project AND last_scan_id = :scan LIMIT ' . self::MAX_PROBED_FILES,
        );
        $statement->execute(['project' => $projectId, 'scan' => $activeScanId]);
        $changed = 0;
        $deleted = 0;
        $directories = [];
        foreach ($statement->fetchAll() as $file) {
            $absolute = $root . '/' . $file['relative_path'];
            $current = @filemtime($absolute);
            if ($current === false) {
                ++$deleted;
                continue;
            }
            // `!==`, not `>`: a checkout of an older revision moves mtime
            // backwards, and that is a change like any other.
            if ($current !== (int) $file['mtime']) {
                ++$changed;
            }
            $directories[dirname($absolute)][basename($absolute)] = true;
        }

        return ['changed' => $changed, 'added' => self::addedSince($directories, $finishedAt), 'deleted' => $deleted];
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
     * themselves — those absent from the tracked-path set, and whose own inode
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
     * - Ignore rules are not applied. An entry the scanner would never have
     *   tracked — a build artifact, a vendored dependency — counts as an
     *   addition, so this can report drift that a rescan would not act on.
     * - Only the first self::MAX_PROBED_FILES untracked entries of a directory
     *   are stat'ed, so an addition sitting behind that many others in the
     *   same directory is missed. The bound is per directory rather than per
     *   probe on purpose: one crowded directory next to a small source tree
     *   must not exhaust the budget and report the tree as fresh.
     *
     * @param array<string, array<string, true>> $directories directory => tracked basenames within it
     * @param ?string $finishedAt when the active scan finished
     */
    private static function addedSince(array $directories, ?string $finishedAt): int
    {
        $scannedAt = $finishedAt === null ? false : strtotime($finishedAt);
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
                while ($examined < self::MAX_PROBED_FILES && ($entry = readdir($handle)) !== false) {
                    if ($entry === '.' || $entry === '..' || isset($tracked[$entry])) {
                        continue;
                    }
                    ++$examined;
                    $createdAt = @filectime($directory . '/' . $entry);
                    if ($createdAt !== false && $createdAt > $scannedAt) {
                        ++$added;
                    }
                    // Enough drift to report; what the rest of the tree holds
                    // cannot change the answer.
                    if ($added >= self::MAX_PROBED_FILES) {
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
