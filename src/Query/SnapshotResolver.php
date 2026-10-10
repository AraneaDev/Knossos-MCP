<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use PDO;

/**
 * A snapshot identifier resolved to its scan, and that scan's graph read.
 *
 * The diff and the gate both name snapshots the same way (`active` or a scan
 * id) and must reject the same ones: an unknown scan, one whose facts were
 * not retained, an incomplete archive. One resolver is what keeps the two
 * from accepting different snapshots.
 */
final readonly class SnapshotResolver
{
    public function __construct(private PDO $pdo) {}

    /**
     * Resolve a snapshot identifier to its scan metadata and (when retained)
     * archive row, validating existence and archive completeness. Fact rows are
     * NOT loaded here so callers can stream table-by-table.
     *
     * @return array{scan_id: string, is_active: bool, archived: array<string, mixed>|null, metadata: array<string, mixed>}
     */
    public function resolve(string $projectId, string $identifier, string $activeScanId): array
    {
        $scanId = $identifier === 'active' ? $activeScanId : trim($identifier);
        if ($scanId === '') {
            throw new InvalidArgumentException('The project has no active snapshot.');
        }
        $scan = $this->pdo->prepare('SELECT * FROM scans WHERE id = :scan AND project_id = :project AND status = :status');
        $scan->execute(['scan' => $scanId, 'project' => $projectId, 'status' => 'complete']);
        $metadata = $scan->fetch();
        if (!is_array($metadata)) {
            throw new InvalidArgumentException(sprintf('Unknown complete snapshot: %s', $scanId));
        }
        // Never the payload: the reader fetches or streams it when it is read.
        $archive = $this->pdo->prepare('SELECT scan_id, project_id, scanner_set_hash, config_hash, complete, fact_count, byte_size, captured_at FROM scan_snapshots WHERE scan_id = :scan AND project_id = :project');
        $archive->execute(['scan' => $scanId, 'project' => $projectId]);
        $archived = $archive->fetch();
        $isActive = $scanId === $activeScanId;
        if (!$isActive) {
            if (!is_array($archived)) {
                throw new InvalidArgumentException(sprintf('Snapshot facts are not retained: %s', $scanId));
            }
            if ((int) $archived['complete'] !== 1) {
                throw new InvalidArgumentException(sprintf('Snapshot archive is incomplete: %s', $scanId));
            }
        }
        return [
            'scan_id' => $scanId,
            'is_active' => $isActive,
            'archived' => is_array($archived) ? $archived : null,
            'metadata' => [
                'scan_id' => $scanId, 'active' => $isActive, 'mode' => $metadata['mode'],
                'scanner_set_hash' => $metadata['scanner_set_hash'], 'config_hash' => is_array($archived) ? $archived['config_hash'] : null,
                'started_at' => $metadata['started_at'], 'finished_at' => $metadata['finished_at'],
            ],
        ];
    }

    /**
     * A resolved snapshot's graph through {@see SnapshotGraphReader}: the active tables by column list, or the archive read as it inflates.
     *
     * @param array{scan_id: string, is_active: bool, archived: array<string, mixed>|null} $resolved
     * @return array<string, list<array<string, mixed>>>
     */
    public function facts(string $projectId, array $resolved): array
    {
        $reader = new SnapshotGraphReader($this->pdo);

        return $resolved['is_active']
            ? $reader->active($projectId, $resolved['scan_id'])
            : $reader->archivedById($resolved['scan_id']);
    }
}
