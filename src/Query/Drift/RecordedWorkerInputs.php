<?php

declare(strict_types=1);

namespace Knossos\Query\Drift;

use Knossos\Discovery\UnitInputSet;
use PDO;

/** Reads the successful dependency files retained with a scan's input record. */
final readonly class RecordedWorkerInputs
{
    /**
     * Worker-read hashes for a scan, or null when the scan predates this
     * record or retained too many inputs to make a complete freshness claim.
     *
     * @return array<string, string>|null relative path => content hash
     */
    public static function forScan(PDO $pdo, string $activeScanId): ?array
    {
        $statement = $pdo->prepare('SELECT unit_inputs_json FROM scans WHERE id = :id');
        $statement->execute(['id' => $activeScanId]);

        return UnitInputSet::decodeWorkerInputs($statement->fetchColumn() ?: null);
    }
}
