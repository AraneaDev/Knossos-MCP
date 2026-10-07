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
     *        The scan-request bounds each language ran under, keyed by owner
     *        (`knossos.php`) to match $workerDiagnostics and $scannerMetadata.
     *        `source_bytes_used` is the narrowest budget an ordinary retry of
     *        that language settled on, so a value below `source_bytes` means a
     *        batch outgrew the worker's output cap or memory and was re-split.
     *        Splits made only to find the one file behind an oversized frame
     *        do not lower it: no batch of the language settled on them.
     * @param array<string, string|null> $undiscoveredInputs
     *        What the kept languages' workers read of files discovery never
     *        hashed, path to SHA-256 hex or null for a failed read, for
     *        {@see UndiscoveredInputVerifier} to re-read before the scan commits
     *        and to retain successful dependency reads for later freshness checks.
     *        A degraded language's reads are left out with its facts.
     * @param array<string, array<string, ?string>> $readGroups
     *        The shared read sets the new cache entries name, group id to
     *        path and hash, for the writer to store beside them.
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
        public array $undiscoveredInputs = [],
        /**
         * Files left out of the graph because their own answer outgrew a
         * size limit, whether found this scan or reused from the cache.
         */
        public int $leftOut = 0,
        /** @var list<string> the paths of those files, in the order they were found */
        public array $leftOutPaths = [],
        public array $readGroups = [],
    ) {}
}
