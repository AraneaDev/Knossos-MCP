<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Worker\WorkerException;
use Knossos\Store\ContributionCacheEntry;

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
 * declaration file it held with its hash, and reports the environment of
 * every program a request built on the result, since a program built for a
 * file another program emitted labels no contribution of its own. Once a
 * scan has built a program, a contribution reused from an earlier build of
 * the same program is current only if its stored environment equals the new
 * one; one that names a program but no environment cannot show it is current
 * and is rebuilt. A contribution derived in no program (a file the worker
 * could not scan, or one the core left out) has no environment to compare.
 * The Python worker labels its contributions the same way, its environment
 * being the source roots its imports searched: a new top-level directory is
 * a source root no read could have named.
 * Contributions cached by a worker from before these fields are never reused:
 * the cache key follows the worker's own files ({@see AnalysisHash}).
 *
 * A file no config lists has no program of its own: whichever program reaches
 * it first through imports emits it, and the fallback program of its group
 * takes what none did. Its facts therefore follow other files' imports, which
 * no read of its own records, so the worker marks its contribution `listed:
 * false`, and once a scan rebuilds anything derived in a program a config
 * describes, every reused contribution so marked is rebuilt too
 * ({@see unlistedOwners()}). A change that reaches only files the fallback
 * emitted cannot move a file between programs: a config's program is driven
 * from its root files and their imports, which hold no fallback file, and a
 * fallback program holds its whole group whatever the imports say.
 */
final class ProgramEnvironments
{
    /** The result field holding the environment of every program a request built. */
    public const FIELD = 'environments';

    /** What the key of a program built for files no config describes starts with. */
    public const FALLBACK_PROGRAM_PREFIX = 'fallback:';

    private function __construct() {}

    /**
     * Validate a result's `environments`: program keys to environment digests.
     *
     * @param array<string, mixed> $result one request's final result
     * @return array<string, string>
     * @throws WorkerException WORKER_RESPONSE_INVALID for a field that is not an object of non-empty keys to SHA-256 digests
     */
    public static function fromResult(array $result, string $workerId): array
    {
        if (!array_key_exists(self::FIELD, $result)) {
            return [];
        }
        $value = $result[self::FIELD];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new WorkerException('WORKER_RESPONSE_INVALID', sprintf('%s sent %s that is not an object keyed by program.', $workerId, self::FIELD));
        }
        $environments = [];
        foreach ($value as $program => $environment) {
            $program = (string) $program;
            if ($program === '' || !is_string($environment) || preg_match('/\A[0-9a-f]{64}\z/', $environment) !== 1) {
                throw new WorkerException('WORKER_RESPONSE_INVALID', sprintf('%s sent %s whose entry for program "%s" is not a lowercase SHA-256 hex digest.', $workerId, self::FIELD, $program));
            }
            $environments[$program] = $environment;
        }

        return $environments;
    }

    /**
     * The reused owners whose program this scan built with another environment.
     *
     * @param list<ContributionCacheEntry> $entries one scanner's cache entries from this scan
     * @param array<string, string> $built the environment of every program the scan's requests reported building
     * @return array<string, true>
     */
    public static function staleOwners(array $entries, array $built = []): array
    {
        $current = $built;
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
     * The reused owners of files no config lists, once this scan rebuilt a
     * contribution in a program a config describes.
     *
     * A rebuilt contribution with no program at all counts as well: a file
     * the worker could not scan says nothing about where the others belong.
     *
     * @param list<ContributionCacheEntry> $entries one scanner's cache entries from this scan
     * @return array<string, true>
     */
    public static function unlistedOwners(array $entries): array
    {
        $rebuilt = false;
        foreach ($entries as $entry) {
            $program = $entry->contribution->program;
            if (!$entry->fromCache && ($program === null || !str_starts_with($program, self::FALLBACK_PROGRAM_PREFIX))) {
                $rebuilt = true;
                break;
            }
        }
        if (!$rebuilt) {
            return [];
        }
        $stale = [];
        foreach ($entries as $entry) {
            if ($entry->fromCache && !$entry->contribution->listed) {
                $stale[$entry->contribution->ownerKey] = true;
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
