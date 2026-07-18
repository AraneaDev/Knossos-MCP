<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use InvalidArgumentException;
use Knossos\Boundary\BoundaryFact;
use Knossos\Classification\ClassificationFact;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;

final readonly class FullScanRequest
{
    /**
     * @param list<ScannerManifest> $scanners
     * @param list<ScanContribution> $contributions
     * @param array<string, mixed> $projectConfig
     * @param list<ClassificationFact> $classifications
     * @param list<BoundaryFact> $boundaries
     * @param list<ContributionCacheEntry> $contributionCache
     */
    public function __construct(
        public string $projectIdentity,
        public string $projectName,
        public DiscoveryResult $discovery,
        public array $scanners,
        public array $contributions,
        public array $projectConfig = [],
        public array $classifications = [],
        public array $boundaries = [],
        public string $mode = 'full',
        public array $contributionCache = [],
    ) {
        if ($projectIdentity === '' || $projectName === '') {
            throw new InvalidArgumentException('Project identity and name must not be empty.');
        }
        self::assertListOf($scanners, ScannerManifest::class, 'scanners');
        self::assertListOf($contributions, ScanContribution::class, 'contributions');
        self::assertListOf($classifications, ClassificationFact::class, 'classifications');
        self::assertListOf($boundaries, BoundaryFact::class, 'boundaries');
        if (!in_array($mode, ['full', 'incremental'], true)) {
            throw new InvalidArgumentException('Scan mode must be full or incremental.');
        }
        $retention = $projectConfig['snapshot_retention'] ?? 5;
        if (!is_int($retention) || $retention < 0 || $retention > 20) {
            throw new InvalidArgumentException('snapshot_retention must be an integer between 0 and 20.');
        }
        self::assertListOf($contributionCache, ContributionCacheEntry::class, 'contributionCache');
    }

    /** @param list<mixed> $values @param class-string $class */
    private static function assertListOf(array $values, string $class, string $field): void
    {
        if (!array_is_list($values)) {
            throw new InvalidArgumentException(sprintf('%s must be a list.', $field));
        }
        foreach ($values as $value) {
            if (!$value instanceof $class) {
                throw new InvalidArgumentException(sprintf('%s contains an invalid value.', $field));
            }
        }
    }
}
