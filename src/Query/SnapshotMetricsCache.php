<?php

declare(strict_types=1);

namespace Knossos\Query;

use JsonException;
use Knossos\Store\SnapshotPayload;
use PDO;
use PDOException;
use ReflectionClass;

/**
 * The metrics and fact counts of retained snapshots, computed once and kept.
 *
 * A retained snapshot's archive never changes, so what was computed from it
 * holds for as long as the archive does: `snapshot_metrics` keeps it, and the
 * archive's deletion deletes it. A row answers only while the archive's
 * `captured_at` and `byte_size` and the {@see self::fingerprint()} of the code
 * that computed it all still match; anything else is a miss, recomputed by the
 * caller. Never the active snapshot: its facts are the live tables.
 *
 * Writing is best effort and never waits: a reader (the dashboard) must not
 * stall behind a scan that holds the write lock, so a store that would block
 * or fail is dropped and the next call computes again.
 */
final readonly class SnapshotMetricsCache
{
    /**
     * The classes whose code decides the stored figures: resolving the
     * snapshot and reading its archive, computing the metrics, and the trend
     * that asks for them and stores what comes back.
     */
    private const SOURCES = [
        QualityGateQueryService::class, SnapshotMetrics::class, SnapshotResolver::class, AbstractArchitectureQueryService::class,
        ReportableComponent::class, SnapshotPayload::class, SnapshotGraphReader::class, ErasedTypeEdge::class, RenameMatching::class,
    ];

    /** The code the stored figures must come from to answer. */
    private string $fingerprint;

    /**
     * @param PDO $pdo a migrated graph database
     * @param string|null $fingerprint the code the figures come from; defaults to {@see self::fingerprint()}
     */
    public function __construct(private PDO $pdo, ?string $fingerprint = null)
    {
        $this->fingerprint = $fingerprint ?? self::fingerprint();
    }

    /**
     * A hash of the source files that compute the stored figures, so a change
     * to how a metric is computed never serves figures the old code computed.
     */
    public static function fingerprint(): string
    {
        $hashes = '';
        foreach (self::SOURCES as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            $hashes .= $file === false ? $class : (hash_file('sha256', $file) ?: $class);
        }
        return hash('sha256', $hashes);
    }

    /**
     * The stored figures for the archive of `$scanId` captured at `$capturedAt`
     * with `$byteSize` bytes, or null when none match it.
     *
     * @return array{counts: array<string, int>, metrics: array<string, int>}|null
     */
    public function get(string $scanId, string $capturedAt, int $byteSize): ?array
    {
        $statement = $this->pdo->prepare('SELECT fingerprint, captured_at, byte_size, payload_json FROM snapshot_metrics WHERE scan_id = :scan');
        $statement->execute(['scan' => $scanId]);
        $row = $statement->fetch();
        if (!is_array($row) || $row['fingerprint'] !== $this->fingerprint || $row['captured_at'] !== $capturedAt || (int) $row['byte_size'] !== $byteSize) {
            return null;
        }
        try {
            $entry = json_decode((string) $row['payload_json'], true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        return is_array($entry) && is_array($entry['counts'] ?? null) && is_array($entry['metrics'] ?? null)
            ? ['counts' => $entry['counts'], 'metrics' => $entry['metrics']]
            : null;
    }

    /**
     * Stores the figures computed from that archive, replacing any older row,
     * when the database takes the write at once; otherwise stores nothing.
     *
     * @param array{counts: array<string, int>, metrics: array<string, int>} $entry
     */
    public function put(string $scanId, string $capturedAt, int $byteSize, array $entry): void
    {
        $timeout = (int) $this->pdo->query('PRAGMA busy_timeout')->fetchColumn();
        $this->pdo->exec('PRAGMA busy_timeout = 0');
        try {
            $this->pdo->prepare(
                'INSERT OR REPLACE INTO snapshot_metrics(scan_id, fingerprint, captured_at, byte_size, payload_json) VALUES (:scan, :fingerprint, :captured, :size, :payload)',
            )->execute([
                'scan' => $scanId,
                'fingerprint' => $this->fingerprint,
                'captured' => $capturedAt,
                'size' => $byteSize,
                'payload' => json_encode($entry, JSON_THROW_ON_ERROR),
            ]);
        } catch (PDOException) {
            // Locked, read-only, or the archive went in between: the next call computes again.
        } finally {
            $this->pdo->exec('PRAGMA busy_timeout = ' . $timeout);
        }
    }
}
