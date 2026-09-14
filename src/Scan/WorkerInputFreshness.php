<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Discovery\FileFingerprint;
use Knossos\Discovery\RootGuard;
use Knossos\Discovery\UnitInputSet;
use PDO;

/** Compares the dependency files a previous scan used with their current bytes. */
final readonly class WorkerInputFreshness
{
    /**
     * Whether a scan's successful worker reads have changed or cannot be
     * checked. A legacy scan without the nested record is deliberately treated
     * as unknown so the next incremental scan rebuilds its contributions.
     */
    public static function changed(PDO $pdo, string $scanId, string $root): bool
    {
        $statement = $pdo->prepare('SELECT unit_inputs_json FROM scans WHERE id = :id');
        $statement->execute(['id' => $scanId]);
        $record = UnitInputSet::decodeWorkerInputs($statement->fetchColumn() ?: null);
        if ($record === null) {
            return true;
        }

        clearstatcache(true);
        $root = rtrim($root, '/');
        foreach ($record as $relativePath => $expected) {
            $absolute = $root . '/' . $relativePath;
            $resolved = realpath($absolute);
            if ($resolved === false || !RootGuard::contains($root, $resolved)) {
                return true;
            }
            $actual = FileFingerprint::contentHashOf($resolved);
            if ($actual === null || !hash_equals($expected, $actual)) {
                return true;
            }
        }

        return false;
    }
}
