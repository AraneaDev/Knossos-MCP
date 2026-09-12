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
     * What the scan stored, or null when it stored nothing that can be
     * compared against.
     *
     * Null and `[]` are different answers and must stay different. `[]` is a
     * scan saying "I read no manifests", which is decidable: nothing to
     * compare, nothing drifted. Null is a scan saying nothing at all — the
     * column never written, a value that no longer parses, a set the scan had
     * to truncate — and collapsing that to `[]` turns "I could not determine
     * this" into "I determined there is nothing". Every manifest the graph
     * holds a hash for then drops out of the comparison, both oracles return
     * zero, {@see FirstAnsweringDriftOracle} takes the first zero as
     * authoritative, and the probe reports `fresh` for a graph nothing
     * verified.
     *
     * So the caller is handed the uncertainty and has to decline on it, the
     * same way {@see GitDriftOracle} already declines on an untrustworthy
     * dirty set.
     *
     * @return array<string, string>|null relative path => content hash, or null when unknown
     */
    public static function forScan(PDO $pdo, string $activeScanId): ?array
    {
        $statement = $pdo->prepare('SELECT unit_inputs_json FROM scans WHERE id = :id');
        $statement->execute(['id' => $activeScanId]);
        $raw = $statement->fetchColumn();

        return UnitInputSet::decode(is_string($raw) ? $raw : null)?->inputs;
    }
}
