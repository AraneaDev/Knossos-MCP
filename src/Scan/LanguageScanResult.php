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
    ) {}
}
