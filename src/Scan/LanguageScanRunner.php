<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Worker\WorkerException;
use Throwable;

final readonly class LanguageScanRunner
{
    /** @param list<LanguageDescriptor> $descriptors */
    public function __construct(
        private array $descriptors,
        private LanguageWorkerPool $pool,
        private ContributionCacheService $cache,
    ) {}

    public function run(ScanPlan $plan, CancellationToken $cancellation): LanguageScanResult
    {
        $manifests = $contributions = $cacheEntries = [];
        $parsed = $unchanged = $added = $changed = 0;
        $scannerMetadata = $stages = [];
        try {
            foreach ($this->descriptors as $descriptor) {
                $files = array_values(array_filter(
                    $plan->preparation->discovery->files,
                    static fn($file): bool => in_array($file->language, $descriptor->languages, true),
                ));
                if ($files === []) {
                    continue;
                }
                $started = hrtime(true);
                $cancellation->throwIfCancelled();
                $client = $this->pool->client($descriptor, $plan->preparation->executionPolicy);
                $manifest = $client->initialize();
                $manifests[] = $manifest;
                $partition = $this->cache->partition(
                    $files,
                    $manifest,
                    $plan->preparation->configurationHashes[$descriptor->key],
                    $plan->cacheByScannerPath,
                    $plan->effectiveMode === 'full',
                    $cancellation,
                );
                array_push($contributions, ...$partition->cached);
                array_push($cacheEntries, ...$partition->cacheEntries);
                $unchanged += count($partition->cached);
                $added += $partition->added;
                $changed += $partition->changed;
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
                $scanned = $paths === [] ? [] : iterator_to_array($client->scan($request, $cancellation->isCancelled(...)));
                $cancellation->throwIfCancelled();
                $parsed += count($scanned);
                if ($paths !== []) {
                    $scannerMetadata[$manifest->id] = $client->lastScanResult();
                }
                $recorded = $this->cache->entriesForScanned(
                    $scanned,
                    $partition->filesToScan,
                    $manifest,
                    $plan->preparation->configurationHashes[$descriptor->key],
                );
                array_push($contributions, ...$recorded['contributions']);
                array_push($cacheEntries, ...$recorded['cache_entries']);
                $stages[$descriptor->stage] = self::elapsedMilliseconds($started);
            }
        } catch (Throwable $error) {
            $this->pool->shutdown();
            // A worker request aborted because the caller cancelled is a cancellation,
            // not a worker failure: surface it as ScanCancelledException so the transport
            // layer suppresses the response instead of reporting an internal error. The
            // token may also have flipped between the RPC returning and this catch.
            if ($cancellation->isCancelled() || ($error instanceof WorkerException && $error->diagnosticCode === 'WORKER_CANCELLED')) {
                throw new ScanCancelledException('Scan was cancelled.', previous: $error);
            }
            throw $error;
        }

        return new LanguageScanResult($manifests, $contributions, $cacheEntries, $parsed, $unchanged, $added, $changed, $scannerMetadata, $stages);
    }

    private static function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }
}
