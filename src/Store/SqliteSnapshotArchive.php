<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;
use PDO;

/**
 * Captures a project's active graph as a retained, compressed snapshot.
 *
 * Captures exactly the tables a scan writes, handed in by the repository that
 * owns that list, so a snapshot can never silently miss a table a scan
 * produces. Their order fixes the key order of every stored payload.
 */
final class SqliteSnapshotArchive
{
    /** @param list<string> $capturedTables */
    public function __construct(
        private readonly PDO $pdo,
        private readonly SqliteScanLifecycle $lifecycle,
        private readonly array $capturedTables,
    ) {}

    /**
     * Capture the current active graph as a retained snapshot.
     *
     * No-ops when retention is 0, when the project has no active scan, or when this
     * scan is already snapshotted — the last case also avoids building and encoding a
     * payload that INSERT OR IGNORE would discard.
     *
     * @throws InvalidArgumentException when $retention is outside 0..20
     */
    public function archiveActiveSnapshot(string $projectId, string $configHash, int $retention): void
    {
        if ($retention < 0 || $retention > 20) {
            throw new InvalidArgumentException('Snapshot retention must be between 0 and 20.');
        }
        if ($retention === 0) {
            return;
        }
        $project = $this->lifecycle->findProject($projectId);
        $scanId = is_array($project) ? $project['active_scan_id'] : null;
        if (!is_string($scanId) || $scanId === '') {
            return;
        }
        // Skip when the active graph is unchanged: a snapshot for this scan was
        // already captured, so there is nothing new to archive. This also
        // avoids materialising and JSON-encoding a payload only for the
        // INSERT OR IGNORE below to discard it.
        $existing = $this->pdo->prepare('SELECT 1 FROM scan_snapshots WHERE scan_id = :scan');
        $existing->execute(['scan' => $scanId]);
        if ($existing->fetchColumn() !== false) {
            return;
        }
        $scan = $this->pdo->prepare('SELECT scanner_set_hash FROM scans WHERE id = :scan AND project_id = :project AND status = :status');
        $scan->execute(['scan' => $scanId, 'project' => $projectId, 'status' => 'complete']);
        $scannerHash = $scan->fetchColumn();
        if (!is_string($scannerHash)) {
            return;
        }

        // Count first so an over-limit table is never fetched into memory only
        // to be discarded (the previous SELECT * ... LIMIT 200001 + fetchAll
        // materialised up to 1.4M rows before checking the bound).
        $complete = true;
        foreach ($this->capturedTables as $table) {
            $count = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE project_id = :project', $table));
            $count->execute(['project' => $projectId]);
            if ((int) $count->fetchColumn() > 200_000) {
                $complete = false;
                break;
            }
        }

        // Streamed row by row into an incremental compressor rather than built
        // whole: materialising the payload cost several times its own size in
        // peak memory, and that multiplier grew with the project.
        [$payload, $factCount, $byteSize] = $complete
            ? $this->streamSnapshotPayload($projectId, $this->capturedTables, $complete)
            : [null, 0, 0];
        if (!$complete) {
            // Which ceiling stopped it: too many facts to be worth keeping, or a
            // payload that outgrew the byte cap while being written.
            $encoded = SqliteValues::json(['schema' => 1, 'reason' => $payload === null ? 'fact_limit' : 'byte_limit']);
            $factCount = 0;
            $byteSize = strlen($encoded);
            $payload = SnapshotPayload::encode($encoded);
        }
        // byte_size stays the size of the facts themselves. It answers "how big
        // is this snapshot", which readers compare across scans; how many bytes
        // the row happens to occupy after compression is a storage detail.
        $insert = $this->pdo->prepare(
            'INSERT OR IGNORE INTO scan_snapshots(scan_id, project_id, scanner_set_hash, config_hash, complete, fact_count, byte_size, payload_json, captured_at) ' .
            'VALUES (:scan, :project, :scanner, :config, :complete, :facts, :bytes, :payload, :captured)',
        );
        $insert->execute([
            'scan' => $scanId, 'project' => $projectId, 'scanner' => $scannerHash, 'config' => $configHash,
            'complete' => $complete ? 1 : 0, 'facts' => $factCount, 'bytes' => $byteSize,
            'payload' => $payload, 'captured' => SqliteValues::now(),
        ]);
    }

    /**
     * Compress a project's facts into a stored payload as the rows are read.
     *
     * @param list<string> $tables
     * @param bool $complete set to false when the payload outgrew its byte ceiling
     *
     * @return array{0: string, 1: int, 2: int} payload, fact count, uncompressed size
     */
    private function streamSnapshotPayload(string $projectId, array $tables, bool &$complete): array
    {
        $writer = SnapshotPayload::writer(50_000_000);
        $writer->write('{"schema":1,"facts":{');
        $factCount = 0;
        foreach ($tables as $index => $table) {
            $order = $table === 'boundary_memberships' ? 'boundary_id, node_id' : 'id';
            $statement = $this->pdo->prepare(sprintf('SELECT * FROM %s WHERE project_id = :project ORDER BY %s', $table, $order));
            $statement->execute(['project' => $projectId]);
            $writer->write(sprintf('%s"%s":[', $index === 0 ? '' : ',', $table));
            $first = true;
            while (($row = $statement->fetch()) !== false) {
                $writer->write(($first ? '' : ',') . SqliteValues::json($row));
                $first = false;
                ++$factCount;
                if ($writer->exceeded()) {
                    // The payload is already being abandoned; reading and
                    // encoding the rest of this table would be work for nothing.
                    break;
                }
            }
            $writer->write(']');
            if ($writer->exceeded()) {
                // Past the ceiling the payload is discarded, so stop reading.
                $complete = false;

                return ['', 0, 0];
            }
        }
        $writer->write('}}');

        return [$writer->finish(), $factCount, $writer->byteSize()];
    }
}
