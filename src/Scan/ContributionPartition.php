<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Reconciliation\ContributionCacheEntry;
use Knossos\Scanner\Protocol\ScanContribution;

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
