<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Worker\WorkerException;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
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
        $scannerMetadata = $stages = $workerDiagnostics = $batchBudgets = [];
        foreach ($this->descriptors as $descriptor) {
            $files = array_values(array_filter(
                $plan->preparation->discovery->files,
                static fn($file): bool => in_array($file->language, $descriptor->languages, true),
            ));
            if ($files === []) {
                continue;
            }
            // Read back by reference below, so the reported budget is the one
            // the scan settled on rather than the one it started with — which
            // is the number an operator needs when a language overflowed.
            $sourceBytes = $descriptor->scanBatchSourceBytes;
            try {
                $outcome = $this->runLanguage($descriptor, $files, $plan, $cancellation, $sourceBytes);
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
            } finally {
                // Recorded on both paths: a degraded language is exactly the one
                // whose batch bounds the reader wants to see.
                $batchBudgets[$descriptor->key] = [
                    'files' => $descriptor->scanBatchFiles,
                    'source_bytes' => $descriptor->scanBatchSourceBytes,
                    'source_bytes_used' => $sourceBytes,
                ];
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

        return new LanguageScanResult($manifests, $contributions, $cacheEntries, $parsed, $unchanged, $added, $changed, $scannerMetadata, $stages, $workerDiagnostics, $batchBudgets);
    }

    /**
     * Analyse one language's files, returning everything the caller accumulates.
     *
     * Extracted from run() so the per-language try/catch guards one unit: a
     * failure here costs this language's facts and nothing else.
     *
     * @param list<object> $files
     * @param int $sourceBytes the language's source-byte batch budget, read on
     *        entry and written back as it is halved, so the caller can report
     *        what the scan actually settled on even when this throws
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
        int &$sourceBytes,
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
        $pending = self::batches($partition->filesToScan, $descriptor->scanBatchFiles, $sourceBytes);
        $halvings = 0;
        while ($pending !== []) {
            $batch = array_shift($pending);
            try {
                // Held aside rather than appended directly: an overflowing
                // request has already streamed some contributions, and the
                // retry re-sends those files, so keeping them would double-count
                // both `parsed` and the reconciled facts.
                $received = [];
                // `['files' => ...] + $request` overrides `files` only: PHP's
                // `+` keeps the left operand's key, so `root`, `limits` and the
                // per-language extras above survive every batch.
                $requested = array_map(static fn(object $file): string => $file->relativePath, $batch);
                foreach ($client->scan(['files' => $requested] + $request, $cancellation->isCancelled(...)) as $contribution) {
                    $received[] = $contribution;
                }
            } catch (WorkerException $error) {
                // WORKER_OUTPUT_LIMIT is the one failure this loop can answer,
                // because it says the batch was too big rather than that the
                // worker is broken. Everything else — a crash, a timeout, and
                // above all a cancellation — keeps the per-language behaviour it
                // already had: rethrow, and let run() degrade or propagate it.
                if ($error->diagnosticCode !== 'WORKER_OUTPUT_LIMIT'
                    || $halvings >= WorkerExecutionPolicy::MAX_SCAN_BATCH_HALVINGS
                    || $cancellation->isCancelled()) {
                    throw $error;
                }
                ++$halvings;
                $sourceBytes = max(1, intdiv($sourceBytes, 2));
                // The failed request closed its session, so this language needs
                // a fresh worker before the smaller batches can be sent.
                $client = $this->pool->restart($descriptor, $plan->preparation->executionPolicy);
                // Re-split the failed batch AND everything still queued: the
                // queued batches were sized by the budget that just proved too
                // large, so leaving them would spend one doomed request each.
                // Order is preserved, so a retry re-sends the same files.
                $pending = self::batches(array_merge($batch, ...$pending), $descriptor->scanBatchFiles, $sourceBytes);
                continue;
            }
            foreach ($received as $contribution) {
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
     * Bounded on two axes because protocol output has two terms. A fixed cost
     * per file — measured at roughly 1.8 KB — dominates on a project of many
     * tiny files, which is why the file count is capped; the rest scales with
     * how much source the request covers, which is why the cumulative byte
     * total is capped too. Neither alone is sufficient: the same 400 TypeScript
     * files emitted 2.5 MB or 29.6 MB depending only on declared-symbol
     * density, and generated 130-byte files expand 15x where the real sources
     * they imitate expand under 2x.
     *
     * Returns the file objects rather than their paths so a batch the worker
     * rejected as too large can be re-split at a smaller budget.
     *
     * A file bigger than the whole byte budget still gets a request of its own
     * rather than an empty one; nothing smaller can be sent.
     *
     * @param list<object> $files
     * @return list<list<object>>
     */
    private static function batches(array $files, int $maxFiles, int $maxSourceBytes): array
    {
        $batches = [];
        $current = [];
        $bytes = 0;
        foreach ($files as $file) {
            // Test fixtures and any non-DiscoveredFile input carry no size; a
            // missing size only relaxes the byte axis, never the count axis.
            $size = isset($file->size) && is_int($file->size) ? $file->size : 0;
            if ($current !== [] && (count($current) >= $maxFiles || $bytes + $size > $maxSourceBytes)) {
                $batches[] = $current;
                $current = [];
                $bytes = 0;
            }
            $current[] = $file;
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
