<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\ScanContribution;

final readonly class ContributionCacheEntry
{
    public function __construct(
        public string $filePath,
        public string $contentHash,
        public string $scannerId,
        public string $scannerVersion,
        public string $configurationHash,
        public ScanContribution $contribution,
    ) {
        foreach ([$filePath, $contentHash, $scannerId, $scannerVersion, $configurationHash] as $value) {
            if ($value === '') {
                throw new InvalidArgumentException('Contribution cache metadata must not be empty.');
            }
        }
    }
}
