<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;

final readonly class ScanPreparation
{
    /** @param list<array<string, mixed>> $explicitBoundaries */
    public function __construct(
        public ProjectConfiguration $configuration,
        public DiscoveryResult $discovery,
        public int $maxFiles,
        public int $maxFileBytes,
        public array $explicitBoundaries,
        public string $requestedMode,
        public int $snapshotRetention,
        public WorkerExecutionPolicy $executionPolicy,
        public bool $laravel,
        public bool $symfony,
        /** @var array<string, string> */
        public array $configurationHashes,
        public float $configurationMilliseconds,
        public float $discoveryMilliseconds,
        public float $planningMilliseconds,
    ) {}
}
