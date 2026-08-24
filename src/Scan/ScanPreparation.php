<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;

/** A prepared scan: its plan, discovery result, and the timings spent getting there. */
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
        /**
         * Frameworks declared or detected for the Python worker, by short
         * name (`fastapi`, `django`, `flask`). Sent in the scan request so the
         * worker can gate enrichment on what the project actually uses.
         *
         * @var list<string>
         */
        public array $pythonFrameworks = [],
        /**
         * Frameworks declared or detected for the Rust worker, by short name
         * (`axum`, `actix`, `rocket`).
         *
         * @var list<string>
         */
        public array $rustFrameworks = [],
    ) {}
}
