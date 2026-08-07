<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Worker\WorkerException;
use Throwable;

/**
 * Runs each language's worker over the files it claims.
 *
 * A worker that fails or times out degrades to a diagnostic rather than failing
 * the scan: a PHP graph is still worth having when the TypeScript worker died,
 * and the caller is told the answer is partial.
 */
final readonly class LanguageScanRunner
{
    /** @param list<LanguageDescriptor> $descriptors */
    public function __construct(
        private array $descriptors,
        private LanguageWorkerPool $pool,
        private ContributionCacheService $cache,
    ) {}
    /** Run each language's worker over the files it claims, degrading a failure to a diagnostic. */

    public function run(ScanPlan $plan, CancellationToken $cancellation): LanguageScanResult
    {
        $manifests = $contributions = $cacheEntries = [];
        $parsed = $unchanged = $added = $changed = 0;
        $scannerMetadata = $stages = $workerDiagnostics = [];
        foreach ($this->descriptors as $descriptor) {
            $files = array_values(array_filter(
                $plan->preparation->discovery->files,
                static fn($file): bool => in_array($file->language, $descriptor->languages, true),
            ));
            if ($files === []) {
                continue;
            }
            try {
                $outcome = $this->runLanguage($descriptor, $files, $plan, $cancellation);
            } catch (Throwable $error) {
                // A cancellation is the caller's decision, not a worker fault: it
                // must reach the transport so the response is suppressed rather
                // than reported as a degraded scan. The token may also have
                // flipped between the RPC returning and this catch.
                $this->pool->shutdown();
                if ($cancellation->isCancelled() || ($error instanceof WorkerException && $error->diagnosticCode === 'WORKER_CANCELLED')) {
                    throw new ScanCancelledException('Scan was cancelled.', previous: $error);
                }
                // Everything else costs this language only. The other languages'
                // facts are already collected and are still worth a graph.
                $workerDiagnostics[] = [
                    'owner' => 'knossos.' . $descriptor->key,
                    'code' => $error instanceof WorkerException ? $error->diagnosticCode : 'WORKER_FAILED',
                    'message' => sprintf('%s scanner failed: %s', $descriptor->key, $error->getMessage()),
                ];
                continue;
            }
            $manifests[] = $outcome['manifest'];
            array_push($contributions, ...$outcome['contributions']);
            array_push($cacheEntries, ...$outcome['cache_entries']);
            $parsed += $outcome['parsed'];
            $unchanged += $outcome['unchanged'];
            $added += $outcome['added'];
            $changed += $outcome['changed'];
            $scannerMetadata += $outcome['scanner_metadata'];
            $stages[$descriptor->stage] = $outcome['milliseconds'];
        }

        return new LanguageScanResult($manifests, $contributions, $cacheEntries, $parsed, $unchanged, $added, $changed, $scannerMetadata, $stages, $workerDiagnostics);
    }

    /**
     * Analyse one language's files, returning everything the caller accumulates.
     *
     * Extracted from run() so the per-language try/catch guards one unit: a
     * failure here costs this language's facts and nothing else.
     *
     * @param list<object> $files
     * @return array{
     *     manifest: \Knossos\Scanner\Protocol\ScannerManifest,
     *     contributions: list<\Knossos\Scanner\Protocol\ScanContribution>,
     *     cache_entries: list<\Knossos\Reconciliation\ContributionCacheEntry>,
     *     parsed: int,
     *     unchanged: int,
     *     added: int,
     *     changed: int,
     *     scanner_metadata: array<string, mixed>,
     *     milliseconds: float
     * }
     */
    private function runLanguage(
        LanguageDescriptor $descriptor,
        array $files,
        ScanPlan $plan,
        CancellationToken $cancellation,
    ): array {
        $started = hrtime(true);
        $cancellation->throwIfCancelled();
        $client = $this->pool->client($descriptor, $plan->preparation->executionPolicy);
        $manifest = $client->initialize();
        $partition = $this->cache->partition(
            $files,
            $manifest,
            $plan->preparation->configurationHashes[$descriptor->key],
            $plan->cacheByScannerPath,
            $plan->effectiveMode === 'full',
            $cancellation,
        );
        $paths = array_map(static fn($file): string => $file->relativePath, $partition->filesToScan);
        $request = [
            'root' => $plan->preparation->discovery->rootRealpath,
            'files' => $paths,
            'limits' => ['max_files' => $plan->preparation->maxFiles, 'max_file_bytes' => $plan->preparation->maxFileBytes],
        ];
        if ($descriptor->key === 'php') {
            $request['frameworks'] = array_keys(array_filter(['laravel' => $plan->preparation->laravel, 'symfony' => $plan->preparation->symfony]));
        } elseif ($descriptor->key === 'typescript') {
            $request['config_files'] = array_values(array_map(
                static fn($unit): string => $unit->configPath,
                array_filter($plan->preparation->discovery->units, static fn($unit): bool => $unit->kind === 'typescript'),
            ));
        }
        // One request per batch: ScannerProtocolSession::scan() calls
        // beginRequest() per invocation, which resets both the cumulative
        // output-byte counter and the deadline. Sending the whole project in
        // one request made a 20 MB cap and a 30 s budget apply to the project
        // rather than to a batch, so a full scan of a mid-sized codebase failed
        // on limits sized for a batch.
        $scanned = $metadata = [];
        foreach (self::batches($partition->filesToScan, $descriptor) as $batch) {
            // `['files' => $batch] + $request` overrides `files` only: PHP's `+`
            // keeps the left operand's key, so `root`, `limits` and the
            // per-language extras above survive every batch.
            foreach ($client->scan(['files' => $batch] + $request, $cancellation->isCancelled(...)) as $contribution) {
                $scanned[] = $contribution;
            }
            $metadata = self::mergeScanResult($metadata, $client->lastScanResult());
            // Inside the loop so a cancelled scan stops at the next batch
            // boundary instead of running the language to completion.
            $cancellation->throwIfCancelled();
        }
        $recorded = $this->cache->entriesForScanned(
            $scanned,
            $partition->filesToScan,
            $manifest,
            $plan->preparation->configurationHashes[$descriptor->key],
        );

        return [
            'manifest' => $manifest,
            'contributions' => [...$partition->cached, ...$recorded['contributions']],
            'cache_entries' => [...$partition->cacheEntries, ...$recorded['cache_entries']],
            'parsed' => count($scanned),
            'unchanged' => count($partition->cached),
            'added' => $partition->added,
            'changed' => $partition->changed,
            'scanner_metadata' => $paths === [] ? [] : [$manifest->id => $metadata],
            'milliseconds' => self::elapsedMilliseconds($started),
        ];
    }
    /**
     * Split the files a language must scan into scan requests.
     *
     * Bounded on two axes because two different per-request budgets have to
     * hold. The file count guards the deadline, which for a per-file parser
     * tracks how many files were asked for. The cumulative source-byte total
     * guards `max_output_bytes`, which tracks how much source the request
     * covers rather than how many files: measured across TypeScript corpora of
     * very different density, protocol output stayed at ~15.5x source bytes
     * while file counts told nothing useful — 400 files of dense TypeScript
     * emitted 29.6 MB against the 20 MB cap where 400 sparse files emitted
     * 2.5 MB.
     *
     * A file bigger than the whole byte budget still gets a request of its own
     * rather than an empty one; nothing smaller can be sent.
     *
     * @param list<object> $files
     * @return list<list<string>>
     */
    private static function batches(array $files, LanguageDescriptor $descriptor): array
    {
        $batches = [];
        $current = [];
        $bytes = 0;
        foreach ($files as $file) {
            // Test fixtures and any non-DiscoveredFile input carry no size; a
            // missing size only relaxes the byte axis, never the count axis.
            $size = isset($file->size) && is_int($file->size) ? $file->size : 0;
            if ($current !== [] && (count($current) >= $descriptor->scanBatchFiles || $bytes + $size > $descriptor->scanBatchSourceBytes)) {
                $batches[] = $current;
                $current = [];
                $bytes = 0;
            }
            $current[] = $file->relativePath;
            $bytes += $size;
        }
        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * Fold one batch's scan reply into the language's running scanner metadata.
     *
     * Every integer a worker reports is a count of what THAT request did —
     * files_scanned, and TypeScript's programs and programs_reused — so they are
     * summed. Keeping the last batch's value would report a fraction of the
     * language's work as if it were the total. Non-integers (a parser name, a
     * version) are not counts, so the newest batch's value wins.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $batchResult
     * @return array<string, mixed>
     */
    private static function mergeScanResult(array $metadata, array $batchResult): array
    {
        foreach ($batchResult as $field => $value) {
            $running = $metadata[$field] ?? null;
            $metadata[$field] = is_int($value) && is_int($running) ? $running + $value : $value;
        }

        return $metadata;
    }
    /** Milliseconds since a hrtime() mark, for the stage timings. */

    private static function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }
}
