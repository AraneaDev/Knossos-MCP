<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Reconciliation\ContributionCacheEntry;

/**
 * Edits to files whose declarations every file of their scanner sees.
 *
 * A worker that attributes its reads shares a file that declares globally (a
 * script, a module that augments the global scope or another module, a UMD
 * global) with every file of its request, and the planner rebuilds those files
 * when it changes. A file that only starts to declare globally with an edit,
 * though, was in no file's reads beforehand, so nothing the planner knows
 * reaches the files that can now see it. Only the worker's answer says so: its
 * own path is in the reads its request shares.
 *
 * So once a scanner whose added files affect every file has answered, each
 * file it rescanned whose bytes changed since its cached contribution, and
 * that declares globally now or did before, is treated as a file added to it:
 * every row of the scanner, and each reader of those rows in other scanners,
 * is rebuilt in a second pass.
 */
final class GlobalDeclarationEdits
{
    private function __construct() {}

    /**
     * The edited files of one scanner that declare globally now or did before.
     *
     * @param list<ContributionCacheEntry> $entries the scanner's cache entries from this scan
     * @param array<string, array<string, ?string>> $readGroups this scan's shared read sets by group id
     * @return list<string>
     */
    public static function paths(array $entries, array $readGroups, ?CachedReads $cached): array
    {
        if ($cached === null) {
            return [];
        }
        $paths = [];
        foreach ($entries as $entry) {
            $row = $cached->rows[$entry->contribution->ownerKey] ?? null;
            if ($entry->fromCache || $row === null || $row['content_hash'] === $entry->contentHash) {
                continue;
            }
            $now = $entry->readGroup !== null && array_key_exists($entry->filePath, $readGroups[$entry->readGroup] ?? []);
            $before = $row['read_group'] !== null && array_key_exists($row['file_path'], $cached->groupReads[$row['read_group']] ?? []);
            if ($now || $before) {
                $paths[] = $entry->filePath;
            }
        }

        return $paths;
    }

    /**
     * The plan with every owner those files reach invalidated, as if each had
     * been added to its scanner.
     *
     * @param array<string, list<string>> $pathsByScanner scanner id to its edited global files
     */
    public static function widened(ScanPlan $plan, array $pathsByScanner): ScanPlan
    {
        if ($pathsByScanner === [] || $plan->cachedReads === null) {
            return $plan;
        }

        return new ScanPlan(
            $plan->preparation,
            $plan->projectId,
            $plan->effectiveMode,
            $plan->cacheByScannerPath,
            $plan->deletedFiles,
            ScanPlanner::reachedOwners($plan->preparation, $plan->cachedReads, $pathsByScanner) + $plan->invalidatedOwners,
            $plan->hadActiveScan,
            $plan->cachedReads,
        );
    }
}
