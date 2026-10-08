<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Reconciliation\ContributionCacheEntry;

/**
 * Contributions reused from the cache whose program's global declarations
 * changed under them.
 *
 * A file's facts depend on every name its program declares globally (a
 * script, a module that augments the global scope or another module, a UMD
 * global, a type library a config names), and no read of the file names a
 * global that was not there yet, or one an edit elsewhere took out of the
 * program. The TypeScript worker therefore labels each contribution with its
 * `program` and that program's `environment`, a digest of every global
 * declaration file it held with its hash. Once a scan has rebuilt a program,
 * a contribution reused from an earlier build of the same program is current
 * only if its stored environment equals the new one; one that names a
 * program but no environment cannot show it is current and is rebuilt. A
 * contribution derived in no program (a file the worker could not scan, or one
 * the core left out) has no environment to compare. Contributions cached by a
 * worker from before these fields are never reused: the cache key follows the
 * worker's own files ({@see AnalysisHash}).
 */
final class ProgramEnvironments
{
    private function __construct() {}

    /**
     * The reused owners whose program this scan built with another environment.
     *
     * @param list<ContributionCacheEntry> $entries one scanner's cache entries from this scan
     * @return array<string, true>
     */
    public static function staleOwners(array $entries): array
    {
        $current = [];
        foreach ($entries as $entry) {
            $contribution = $entry->contribution;
            if (!$entry->fromCache && $contribution->program !== null && $contribution->environment !== null) {
                $current[$contribution->program] = $contribution->environment;
            }
        }
        if ($current === []) {
            return [];
        }
        $stale = [];
        foreach ($entries as $entry) {
            $contribution = $entry->contribution;
            if (!$entry->fromCache) {
                continue;
            }
            $program = $contribution->program;
            if ($program !== null && array_key_exists($program, $current) && $contribution->environment !== $current[$program]) {
                $stale[$contribution->ownerKey] = true;
            }
        }

        return $stale;
    }

    /**
     * The plan with those owners, and everything that read them, invalidated.
     *
     * @param array<string, true> $stale
     */
    public static function widened(ScanPlan $plan, array $stale): ScanPlan
    {
        if ($stale === [] || $plan->cachedReads === null) {
            return $plan;
        }

        return new ScanPlan(
            $plan->preparation,
            $plan->projectId,
            $plan->effectiveMode,
            $plan->cacheByScannerPath,
            $plan->deletedFiles,
            ScanPlanner::reachedOwners($plan->preparation, $plan->cachedReads, [], $stale) + $plan->invalidatedOwners,
            $plan->hadActiveScan,
            $plan->cachedReads,
        );
    }
}
