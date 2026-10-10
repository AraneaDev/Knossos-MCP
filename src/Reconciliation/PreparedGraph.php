<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

/**
 * A scan's graph resolved and ready to write, before the transaction opens.
 *
 * Everything here is computed from the request alone, so preparing it holds
 * no lock; the transaction that persists it does no resolution.
 */
final readonly class PreparedGraph
{
    /**
     * @param array<string, string> $fileIds relative path to stable file id
     * @param array<string, array<string, mixed>> $nodes node id to node record, externals included
     * @param array<string, array<string, mixed>> $edges edge id to edge record
     * @param list<array<string, mixed>> $classifications
     * @param list<array<string, mixed>> $boundaries
     * @param list<array<string, string>> $warnings the reconciler's own diagnostics
     */
    public function __construct(
        public FullScanRequest $request,
        public string $projectId,
        public string $scanId,
        public string $scannerSetHash,
        public array $fileIds,
        public array $nodes,
        public array $edges,
        public array $classifications,
        public array $boundaries,
        public array $warnings,
        public int $unresolvedNodes,
    ) {}
}
