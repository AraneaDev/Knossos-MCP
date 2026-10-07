<?php

declare(strict_types=1);

namespace Knossos\Scan;

/** The resolved decisions for one scan: mode, limits, and the files to analyse. */
final readonly class ScanPlan
{
    /**
     * @param array<string, array<string, mixed>> $cacheByScannerPath cache metadata rows keyed by scanner id and path, without payloads
     * @param array<string, true> $invalidatedOwners cached owners a change reached, rescanned whatever their own hash says
     */
    public function __construct(
        public ScanPreparation $preparation,
        public string $projectId,
        public string $effectiveMode,
        public array $cacheByScannerPath,
        public int $deletedFiles,
        public array $invalidatedOwners = [],
        /** True when the project already has an active scan whose graph a failed worker could prune. */
        public bool $hadActiveScan = false,
        /** The cached read sets, loaded for an incremental scan only. */
        public ?CachedReads $cachedReads = null,
    ) {}
}
