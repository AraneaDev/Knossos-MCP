<?php

declare(strict_types=1);

namespace Knossos\Configuration;

/** A project's resolved `knossos.json` settings: limits, ignores, boundaries, frameworks. */
final readonly class ProjectConfiguration
{
    /**
     * @param list<string> $ignores
     * @param list<array<string, mixed>> $boundaries
     * @param list<string> $frameworks
     * @param list<array<string, mixed>> $policies
     * @param array<string, int> $qualityBudgets
     * @param list<string> $deadCodeSuppressions
     * @param int|null $watchScanTimeoutMs how long one scan of the shared watcher may run; null for its default
     */
    public function __construct(
        public ?string $path = null,
        public array $ignores = [],
        public ?int $maxFiles = null,
        public ?int $maxFileBytes = null,
        public ?int $workerTimeoutMs = null,
        public ?int $workerMemoryMb = null,
        public array $boundaries = [],
        public array $frameworks = [],
        public ?int $snapshotRetention = null,
        public array $policies = [],
        public array $qualityBudgets = [],
        public array $deadCodeSuppressions = [],
        public ?int $watchScanTimeoutMs = null,
    ) {}
}
