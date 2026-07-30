<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Reconciliation\ContributionCacheEntry;
use Knossos\Scanner\Protocol\ScanContribution;

/**
 * The split an incremental scan starts from: what was reused, what must be scanned.
 *
 * Carries the added/changed counts alongside, because a scan that reused
 * everything and one that re-analysed the tree are indistinguishable from the
 * resulting graph alone.
 */
final readonly class ContributionPartition
{
    /** @param list<ScanContribution> $cached @param list<ContributionCacheEntry> $cacheEntries @param list<object> $filesToScan */
    public function __construct(
        public array $cached,
        public array $cacheEntries,
        public array $filesToScan,
        public int $added,
        public int $changed,
    ) {}
}
