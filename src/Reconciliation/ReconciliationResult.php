<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

final readonly class ReconciliationResult
{
    public function __construct(
        public string $projectId,
        public string $scanId,
        public int $files,
        public int $nodes,
        public int $edges,
        public int $diagnostics,
        public int $unresolvedNodes,
        /** @var array<string, float> */
        public array $phaseMilliseconds = [],
    ) {}
}
