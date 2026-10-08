<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Reconciliation\ContributionCacheEntry;

/**
 * Edits that change which global declarations a scanner's files see.
 *
 * A worker that attributes its reads shares a file that declares globally (a
 * script, a module that augments the global scope or another module, a UMD
 * global) with every file of its request, and the planner rebuilds those files
 * when it changes. Two kinds of edit escape that, because no stored read of
 * the files they affect names what changed:
 *
 * - an edit that makes the edited file itself declare globally, or stop doing
 *   so: only the worker's answer says so, through the file's own path in the
 *   reads its request now shares, or in the group it shared before;
 * - an edit that brings a global file into a program or takes one out, by an
 *   import, a triple-slash reference or anything else the edited file
 *   resolves. Every file a program holds is named in some read of its request,
 *   so the request's footprint before (its shared group and the reads of
 *   every file that shared it) and after (this scan's shared reads and the
 *   reads of the files it rescanned) bound the program. A shared read now that
 *   the edited file's request did not hold before entered the program; a file
 *   the edited file read before that nothing in this scan read left it.
 *
 * Either way the edited file is treated as added to its scanner: every row of
 * the scanner, and each reader of those rows in other scanners, is rebuilt in a
 * second pass. A file that stays global and does not change triggers nothing,
 * and neither does a scan that edits no file. The second rule over-approximates
 * when this scan builds programs the earlier request did not, or when the last
 * importer of a package that declares nothing global drops it; the second pass
 * then rebuilds more than the edit reached, never less.
 */
final class GlobalDeclarationEdits
{
    private function __construct() {}

    /**
     * The edited files of one scanner that changed what declares globally.
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
        $shared = [];
        foreach ($readGroups as $reads) {
            $shared += $reads;
        }
        $footprint = $shared;
        foreach ($entries as $entry) {
            if (!$entry->fromCache) {
                $footprint += $entry->reads + [$entry->filePath => $entry->contentHash];
            }
        }
        $before = [];
        $paths = [];
        foreach ($entries as $entry) {
            $owner = $entry->contribution->ownerKey;
            $row = $cached->rows[$owner] ?? null;
            if ($entry->fromCache || $row === null || $row['content_hash'] === $entry->contentHash) {
                continue;
            }
            $now = $entry->readGroup !== null && array_key_exists($entry->filePath, $readGroups[$entry->readGroup] ?? []);
            $was = $row['read_group'] !== null && array_key_exists($row['file_path'], $cached->groupReads[$row['read_group']] ?? []);
            $group = $row['read_group'] ?? '';
            $before[$group] ??= self::footprintOf($cached, $row['read_group']);
            $entered = array_diff_key($shared, $before[$group]) !== [];
            $left = array_diff_key(array_filter($cached->ownerReads[$owner] ?? [], static fn(?string $hash): bool => $hash !== null), $footprint) !== [];
            if ($now || $was || $entered || $left) {
                $paths[] = $entry->filePath;
            }
        }

        return $paths;
    }

    /**
     * What an earlier request read: its shared group, and the reads and own
     * path of every file that shared it.
     *
     * @return array<string, mixed>
     */
    private static function footprintOf(CachedReads $cached, ?string $group): array
    {
        $footprint = $group === null ? [] : ($cached->groupReads[$group] ?? []);
        foreach ($cached->rows as $owner => $row) {
            if ($row['read_group'] === $group) {
                $footprint += ($cached->ownerReads[(string) $owner] ?? []) + [$row['file_path'] => true];
            }
        }

        return $footprint;
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
