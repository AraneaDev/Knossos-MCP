<?php

declare(strict_types=1);

namespace Knossos\Query\Drift;

use Knossos\Discovery\UnitInputSet;
use PDO;

/**
 * The hashes a scan recorded for the manifests it read but stores no `files`
 * row for.
 *
 * Shared by both oracles for the reason {@see ScannedPaths} is: the two must
 * decide the same path the same way, and a manifest is only decidable at all
 * against the hash the scan stored for it. Reading the column in each oracle
 * separately is how they would drift apart.
 */
final readonly class RecordedUnitInputs
{
    /**
     * What the scan stored, or an empty set when it stored nothing that can be
     * compared against.
     *
     * Empty is not the claim that the project has no manifests — see
     * {@see UnitInputSet::decode()} for the shapes that collapse to it. The
     * difference stays visible in the answer rather than being swallowed here:
     * a manifest with no stored hash has nothing to be decided against, so it
     * is reported as an addition, and one rescan settles it.
     *
     * @return array<string, string> relative path => content hash
     */
    public static function forScan(PDO $pdo, string $activeScanId): array
    {
        $statement = $pdo->prepare('SELECT unit_inputs_json FROM scans WHERE id = :id');
        $statement->execute(['id' => $activeScanId]);
        $raw = $statement->fetchColumn();
        $recorded = UnitInputSet::decode(is_string($raw) ? $raw : null);

        return $recorded === null ? [] : $recorded->inputs;
    }
}
