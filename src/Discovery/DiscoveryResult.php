<?php

declare(strict_types=1);

namespace Knossos\Discovery;

final readonly class DiscoveryResult
{
    /**
     * @param list<DiscoveredFile> $files
     * @param list<ProjectUnit> $units
     * @param list<DiscoveryDiagnostic> $diagnostics
     */
    public function __construct(
        public string $rootRealpath,
        public array $files,
        public array $units,
        public array $diagnostics,
        public string $inputHash,
        public string $configurationHash,
    ) {}
}
