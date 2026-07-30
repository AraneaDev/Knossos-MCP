<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

/** What reconciliation wrote: node, edge, and diagnostic counts for the scan report. */
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
