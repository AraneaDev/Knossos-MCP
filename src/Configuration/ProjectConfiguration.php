<?php

declare(strict_types=1);

namespace Knossos\Configuration;

final readonly class ProjectConfiguration
{
    /**
     * @param list<string> $ignores
     * @param list<array<string, mixed>> $boundaries
     * @param list<string> $frameworks
     * @param list<array<string, mixed>> $policies
     * @param array<string, int> $qualityBudgets
     */
    public function __construct(
        public ?string $path = null,
        public array $ignores = [],
        public ?int $maxFiles = null,
        public ?int $maxFileBytes = null,
        public ?int $workerTimeoutMs = null,
        public array $boundaries = [],
        public array $frameworks = [],
        public ?int $snapshotRetention = null,
        public array $policies = [],
        public array $qualityBudgets = [],
    ) {}
}
