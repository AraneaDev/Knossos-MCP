<?php

declare(strict_types=1);

namespace Knossos\Scan;

final readonly class LanguageScanResult
{
    /** @param list<object> $manifests @param list<object> $contributions @param list<object> $cacheEntries @param array<string, mixed> $scannerMetadata @param array<string, float> $stageMilliseconds */
    public function __construct(
        public array $manifests,
        public array $contributions,
        public array $cacheEntries,
        public int $parsed,
        public int $unchanged,
        public int $added,
        public int $changed,
        public array $scannerMetadata,
        public array $stageMilliseconds,
    ) {}
}
