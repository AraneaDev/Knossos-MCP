<?php

declare(strict_types=1);

namespace Knossos\Scan;

final readonly class ScanPlan
{
    /** @param array<string, array<string, mixed>> $cacheByScannerPath */
    public function __construct(
        public ScanPreparation $preparation,
        public string $projectId,
        public string $effectiveMode,
        public array $cacheByScannerPath,
        public int $deletedFiles,
    ) {}
}
