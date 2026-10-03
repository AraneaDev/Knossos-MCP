<?php

declare(strict_types=1);

namespace Knossos\Query;

use PDO;

/**
 * What each recorded scan changed (the `scan_ledger` table).
 *
 * A turn brief reports what a turn did by comparing the graph before its own
 * scan with the graph after it. When another writer scanned the turn's edits
 * first (the live watcher, the pane's rescan, another session's turn brief),
 * the graph before the brief's scan already holds them, so that comparison
 * would report nothing. Each writer that records here keeps, per scan, the
 * snapshot it started from, the one it produced, each changed file's content
 * hash before it, and (when the writer computed them) the policy violations
 * those files held before it. {@see self::since()} reads them back in order
 * from the snapshot a turn started at.
 */
final readonly class ScanLedger
{
    /** Entries kept per project: far more than any one turn spans. */
    private const KEPT = 200;

    /** @param PDO $pdo an existing, migrated graph database */
    public function __construct(private PDO $pdo) {}

    /** The project's active snapshot id, or null when it has none. */
    public function activeSnapshot(string $projectId): ?string
    {
        $statement = $this->pdo->prepare('SELECT active_scan_id FROM projects WHERE id = ?');
        $statement->execute([$projectId]);
        $id = $statement->fetchColumn();

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Every tracked file's content hash.
     *
     * @return array<string, string> relative path => content hash
     */
    public function hashes(string $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT relative_path, content_hash FROM files WHERE project_id = ?');
        $statement->execute([$projectId]);
        $hashes = [];
        // An all-digit path comes back from FETCH_KEY_PAIR as an integer key; strval keeps it a path.
        foreach ($statement->fetchAll(PDO::FETCH_KEY_PAIR) as $path => $hash) {
            $hashes[(string) $path] = (string) $hash;
        }

        return $hashes;
    }

    /**
     * Records one scan: which files it changed and what each held before.
     *
     * A scan that moved no snapshot and changed nothing says nothing and is
     * not recorded. Older entries past {@see self::KEPT} are pruned.
     *
     * @param array<string, string> $before every tracked file's hash before the scan
     * @param array<string, string> $after every tracked file's hash after it
     * @param array<string, array{violations: array<string, array<string, mixed>>, truncated: bool}>|null $baselines
     *        the violations each file held before the scan, by path, for the files the writer checked; null when it checked none
     */
    public function record(string $projectId, ?string $from, string $to, array $before, array $after, ?array $baselines = null): void
    {
        $changed = [];
        foreach ($after as $path => $hash) {
            if (($before[$path] ?? null) !== $hash) {
                $changed[(string) $path] = $before[$path] ?? null;
            }
        }
        foreach (array_diff_key($before, $after) as $path => $hash) {
            $changed[(string) $path] = $hash;
        }
        if ($changed === [] && $from === $to) {
            return;
        }
        $kept = $baselines === null ? null : array_intersect_key($baselines, $changed);
        $insert = $this->pdo->prepare('INSERT INTO scan_ledger(project_id, from_snapshot, to_snapshot, recorded_at, changes_json) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([$projectId, $from, $to, time(), json_encode(['before' => (object) $changed, 'baselines' => $kept === null ? null : (object) $kept], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
        $prune = $this->pdo->prepare('DELETE FROM scan_ledger WHERE project_id = ? AND id <= (SELECT id FROM scan_ledger WHERE project_id = ? ORDER BY id DESC LIMIT 1 OFFSET ?)');
        $prune->execute([$projectId, $projectId, self::KEPT]);
    }

    /**
     * What the recorded scans since snapshot `$since` changed, as of before
     * the first of them that touched each file.
     *
     * `before` maps every file a scan since then changed to its hash before
     * that scan (null: the scan added it). `baselines` maps those files to the
     * violations they held then (null: that scan did not check them). Null
     * altogether when the ledger cannot account for every scan since: none
     * recorded starts at `$since`, one in between was not recorded, or the
     * last recorded one is not the project's active snapshot.
     *
     * @return array{before: array<string, string|null>, baselines: array<string, array{violations: array<string, array<string, mixed>>, truncated: bool}|null>}|null
     */
    public function since(string $projectId, string $since): ?array
    {
        $active = $this->activeSnapshot($projectId);
        if ($active === $since) {
            return ['before' => [], 'baselines' => []];
        }
        $statement = $this->pdo->prepare('SELECT from_snapshot, to_snapshot, changes_json FROM scan_ledger WHERE project_id = ? ORDER BY id');
        $statement->execute([$projectId]);
        $before = [];
        $baselines = [];
        $at = null;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($at === null && $row['from_snapshot'] !== $since) {
                continue;
            }
            if ($at !== null && $row['from_snapshot'] !== $at) {
                return null;
            }
            $at = (string) $row['to_snapshot'];
            $changes = json_decode((string) $row['changes_json'], true, 512, JSON_THROW_ON_ERROR);
            foreach ($changes['before'] as $path => $hash) {
                $path = (string) $path;
                if (array_key_exists($path, $before)) {
                    continue;
                }
                $before[$path] = $hash === null ? null : (string) $hash;
                $baselines[$path] = $changes['baselines'][$path] ?? null;
            }
        }

        return $at !== null && $at === $active ? ['before' => $before, 'baselines' => $baselines] : null;
    }
}
