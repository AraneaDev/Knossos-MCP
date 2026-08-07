<?php

declare(strict_types=1);

namespace Knossos\Scan;

/** One language's contributions and diagnostics from a scan. */
final readonly class LanguageScanResult
{
    /**
     * @param list<object> $manifests
     * @param list<object> $contributions
     * @param list<object> $cacheEntries
     * @param array<string, mixed> $scannerMetadata
     * @param array<string, float> $stageMilliseconds
     * @param list<array{owner: string, code: string, message: string}> $workerDiagnostics
     *        Languages whose worker failed. Not Diagnostic objects: a worker
     *        failure has no source file to point at, and Evidence requires one.
     * @param array<string, array{files: int, source_bytes: int, source_bytes_used: int}> $batchBudgets
     *        The scan-request bounds each language ran under, keyed by language
     *        key. `source_bytes_used` differs from `source_bytes` when a batch
     *        overflowed the worker's output cap and the budget was halved.
     */
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
        public array $workerDiagnostics = [],
        public array $batchBudgets = [],
    ) {}
}
