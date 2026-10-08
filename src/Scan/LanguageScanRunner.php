<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Discovery\IgnoreMatcher;
use Knossos\Discovery\ProjectUnit;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\Scanner\Worker\WorkerException;
use PDO;
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
        // Test-only: overrides ScanBatchQueue::requestCap() for every
        // language. The cap is provably unreachable within the 2n - b tree
        // bound, so only a lower one set here can exercise it. Production
        // never passes it.
        private ?int $maxRequestsPerLanguage = null,
        // Where the workers' own files live, which is what their cache key
        // follows. Empty only where no descriptor names any.
        private string $installationRoot = '',
        // Where the payloads of reused cache rows are read from. Null only in
        // tests whose plans carry the payloads in the rows themselves.
        private ?PDO $pdo = null,
    ) {}

    /** Run each language's worker over the files it claims, degrading a failure to a diagnostic. */
    public function run(ScanPlan $plan, CancellationToken $cancellation): LanguageScanResult
    {
        $workerDiagnostics = $batchBudgets = $outcomes = [];
        foreach ($this->descriptors as $descriptor) {
            $outcome = $this->attempt($descriptor, $plan, $cancellation, $workerDiagnostics, $batchBudgets);
            if ($outcome !== null) {
                $outcomes[$descriptor->key] = $outcome;
            }
        }
        $outcomes = $this->afterEnvironmentChanges($plan, $cancellation, $outcomes, $workerDiagnostics, $batchBudgets);

        $manifests = $contributions = $cacheEntries = [];
        $parsed = $unchanged = $added = $changed = 0;
        $leftOutPaths = [];
        $scannerMetadata = $stages = $readGroups = [];
        // One for the whole scan: the same file read with different results
        // by two languages fails the scan just as two requests of one do.
        $undiscoveredInputs = new UndiscoveredInputs();
        foreach ($this->descriptors as $descriptor) {
            $outcome = $outcomes[$descriptor->key] ?? null;
            if ($outcome === null) {
                continue;
            }
            // Only once the language is kept: a degraded language's facts are
            // dropped, so what it read has nothing left to vouch for. Outside
            // the worker's try, so a conflict fails the scan rather than
            // degrading the language that happened to arrive second.
            $undiscoveredInputs->add($outcome['undiscovered_inputs']);
            $manifests[] = $outcome['manifest'];
            array_push($contributions, ...$outcome['contributions']);
            array_push($cacheEntries, ...$outcome['cache_entries']);
            $parsed += $outcome['parsed'];
            $unchanged += $outcome['unchanged'];
            $added += $outcome['added'];
            $changed += $outcome['changed'];
            array_push($leftOutPaths, ...$outcome['left_out_paths']);
            $scannerMetadata += $outcome['scanner_metadata'];
            $readGroups += $outcome['read_groups'];
            $stages[$descriptor->stage] = $outcome['milliseconds'];
        }

        return new LanguageScanResult($manifests, $contributions, $cacheEntries, $parsed, $unchanged, $added, $changed, $scannerMetadata, $stages, $workerDiagnostics, $batchBudgets, $undiscoveredInputs->all(), count($leftOutPaths), $leftOutPaths, $readGroups);
    }

    /**
     * Run one language, or record why it is degraded and return null.
     *
     * @param list<array{owner: string, code: string, message: string}> $workerDiagnostics
     * @param array<string, array{files: int, source_bytes: int, source_bytes_used: int}> $batchBudgets
     * @return array<string, mixed>|null the language's outcome ({@see runLanguage()})
     */
    private function attempt(LanguageDescriptor $descriptor, ScanPlan $plan, CancellationToken $cancellation, array &$workerDiagnostics, array &$batchBudgets): ?array
    {
        $files = array_values(array_filter(
            $plan->preparation->discovery->files,
            static fn($file): bool => in_array($file->language, $descriptor->languages, true),
        ));
        // Keyed on the owner, like workerDiagnostics and scannerMetadata, so
        // a consumer can join the three. Recorded for every descriptor,
        // including one with nothing to scan, so the shape is stable. A
        // language run twice keeps the narrowest budget either pass used.
        $owner = $descriptor->scannerId();
        $batchBudgets[$owner] ??= [
            'files' => $descriptor->scanBatchFiles,
            'source_bytes' => $descriptor->scanBatchSourceBytes,
            'source_bytes_used' => $descriptor->scanBatchSourceBytes,
        ];
        if ($files === []) {
            return null;
        }
        // Read back by reference below: the narrowest budget an ordinary
        // retry in this language settled on. Lower than the configured
        // value means a batch outgrew the worker's output cap or memory
        // and was re-split; a search for one oversized frame does not
        // count, since no batch of the language settled on it.
        $sourceBytes = $batchBudgets[$owner]['source_bytes_used'];
        try {
            return $this->runLanguage($descriptor, $files, $plan, $cancellation, $sourceBytes);
        } catch (Throwable $error) {
            // A cancellation is the caller's decision, not a worker fault: it
            // must reach the transport so the response is suppressed rather
            // than reported as a degraded scan. The token may also have
            // flipped between the RPC returning and this catch.
            $this->pool->shutdown();
            if ($cancellation->isCancelled() || ($error instanceof WorkerException && $error->diagnosticCode === 'WORKER_CANCELLED')) {
                throw new ScanCancelledException('Scan was cancelled.', previous: $error);
            }
            // A changed tree is a fault of the whole scan, not of this
            // language. Degrading it would commit a graph missing this
            // language's facts while every recorded hash still matched disk.
            if ($error instanceof ScanSnapshotChangedException) {
                throw $error;
            }
            // Everything else costs this language only. The other languages'
            // facts are already collected and are still worth a graph.
            $workerDiagnostics[] = [
                'owner' => $descriptor->scannerId(),
                'code' => $error instanceof WorkerException ? $error->diagnosticCode : 'WORKER_FAILED',
                'message' => self::withRemedy(
                    sprintf('%s scanner failed: %s', $descriptor->key, $error->getMessage()),
                    $descriptor,
                    $plan->preparation->executionPolicy->workerMemoryMb,
                ),
            ];

            return null;
        } finally {
            // Updated on both paths: a degraded language is exactly the one
            // whose batch bounds the reader wants to see.
            $batchBudgets[$owner]['source_bytes_used'] = $sourceBytes;
        }
    }

    /**
     * Run again every language that contributions reused under a changed
     * program environment reach ({@see ProgramEnvironments}): their facts
     * were derived from global declarations the program no longer holds as
     * they were, which only the worker's answer for the rebuilt program shows.
     *
     * @param array<string, array<string, mixed>> $outcomes by descriptor key
     * @param list<array{owner: string, code: string, message: string}> $workerDiagnostics
     * @param array<string, array{files: int, source_bytes: int, source_bytes_used: int}> $batchBudgets
     * @return array<string, array<string, mixed>>
     */
    private function afterEnvironmentChanges(ScanPlan $plan, CancellationToken $cancellation, array $outcomes, array &$workerDiagnostics, array &$batchBudgets): array
    {
        $stale = [];
        foreach ($this->descriptors as $descriptor) {
            if ($descriptor->addedFilesAffectAll && isset($outcomes[$descriptor->key])) {
                $stale += ProgramEnvironments::staleOwners($outcomes[$descriptor->key]['cache_entries']);
            }
        }
        $wider = ProgramEnvironments::widened($plan, $stale);
        $reached = [];
        foreach (array_diff_key($wider->invalidatedOwners, $plan->invalidatedOwners) as $owner => $true) {
            $reached[$wider->cachedReads?->rows[$owner]['scanner_id'] ?? ''] = true;
        }
        foreach ($this->descriptors as $descriptor) {
            if (isset($outcomes[$descriptor->key], $reached[$descriptor->scannerId()])) {
                $first = $outcomes[$descriptor->key];
                unset($outcomes[$descriptor->key]);
                $outcome = $this->attempt($descriptor, $wider, $cancellation, $workerDiagnostics, $batchBudgets);
                if ($outcome !== null) {
                    // The language's time is both passes; its counts and
                    // facts are the second's, which replace the first's.
                    $outcome['milliseconds'] += $first['milliseconds'];
                    $outcomes[$descriptor->key] = $outcome;
                }
            }
        }

        return $outcomes;
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
     *     left_out_paths: list<string>,
     *     scanner_metadata: array<string, mixed>,
     *     milliseconds: float,
     *     undiscovered_inputs: array<string, string|null>,
     *     read_groups: array<string, array<string, ?string>>
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
        // Where a file left out of the graph is cached: see
        // ContributionCacheService::leftOutConfigurationHash().
        $leftOutHash = ContributionCacheService::leftOutConfigurationHash(
            $plan->preparation->configurationHashes[$descriptor->key],
            $plan->preparation->executionPolicy->limits(),
        );
        // The key follows the worker's own files, so editing one invalidates
        // what it produced without a number to bump.
        $analysisHash = AnalysisHash::of($this->installationRoot, $descriptor->analysisInputs);
        $partition = $this->cache->partition($files, $manifest, new PartitionContext(
            $plan->preparation->configurationHashes[$descriptor->key],
            $plan->cacheByScannerPath,
            // Only a full scan forces everything. An incremental one rescans
            // the owners a change reached, which the plan already named.
            $plan->effectiveMode === 'full',
            $analysisHash,
            $leftOutHash,
            $plan->invalidatedOwners,
            $this->pdo,
            $plan->projectId,
            $plan->cachedReads,
        ), $cancellation);
        $request = self::scanRequest($descriptor, $plan, $files);
        // One request per batch: ScannerProtocolSession::scan() calls
        // beginRequest() per invocation, which resets both the cumulative
        // output-byte counter and the deadline. Sending the whole project in
        // one request made a 20 MB cap and a 30 s budget apply to the project
        // rather than to a batch, so a full scan of a mid-sized codebase failed
        // on limits sized for a batch.
        $scanned = $metadata = [];
        $leftOut = new LeftOutFiles($this->cache, $manifest, $leftOutHash, $analysisHash);
        // Every path discovery hashed, not only this language's files: a worker
        // may read a file another language claims, or a manifest such as the
        // package.json module resolution reads or the Cargo.toml a crate is
        // named by, and that read is checked all the same.
        $discoveredByPath = $plan->preparation->discovery->hashedPaths();
        // Reads of anything else, checked across this language's requests as
        // they arrive and handed back for the pre-commit re-read.
        $undiscovered = new UndiscoveredInputs();
        // What each received contribution read, and the shared sets those
        // name. A file retried in a later batch is overwritten there, like
        // its contribution.
        $readsByOwner = $groups = [];
        $queue = new ScanBatchQueue($partition->filesToScan, $descriptor, $sourceBytes, $this->maxRequestsPerLanguage);
        while (($item = $queue->next()) !== null) {
            // Held aside rather than appended directly: an overflowing
            // request has already streamed some contributions, and the
            // retry re-sends those files, so keeping them would double-count
            // both `parsed` and the reconciled facts. Read after a failure
            // too, to tell which files the worker had already answered.
            /** @var list<ScanContribution> $received */
            $received = [];
            try {
                $requested = array_map(static fn(object $file): string => $file->relativePath, $item['files']);
                foreach ($client->scan(['files' => $requested] + $request, $cancellation->isCancelled(...)) as $contribution) {
                    $received[] = $contribution;
                }
            } catch (WorkerException $error) {
                $client = $this->afterFailure($queue, $leftOut, $item, $error, $received, $descriptor, $plan, $request, $cancellation, $manifest->id);
                continue;
            }
            $batchResult = $client->lastScanResult();
            // Before this batch's contributions are kept: facts resolved against
            // another file's bytes must match what discovery hashed for it too.
            $verified = ScanInputHashes::verifyAll($batchResult, $manifest, $discoveredByPath);
            $undiscovered->add($verified['undiscovered']);
            $requestReads = RequestReads::forRequest($batchResult, $manifest, $verified['all'], $received, $requested);
            $readsByOwner = $requestReads['owners'] + $readsByOwner;
            $groups += $requestReads['groups'];
            // Evidence for these checks only, not statistics: kept out of the
            // scanner metadata a scan report carries.
            unset($batchResult['input_hashes'], $batchResult['reads']);
            foreach ($received as $contribution) {
                $scanned[] = $contribution;
            }
            $metadata = self::mergeScanResult($metadata, $batchResult);
            // Inside the loop so a cancelled scan stops at the next batch
            // boundary instead of running the language to completion.
            $cancellation->throwIfCancelled();
        }
        self::assertNotEveryFileLeftOut(count($partition->filesToScan), count($leftOut->paths()));
        $recorded = $this->cache->entriesForScanned(
            $scanned,
            array_values(array_filter(
                $partition->filesToScan,
                static fn(object $file): bool => !$leftOut->has($file->relativePath),
            )),
            $manifest,
            $plan->preparation->configurationHashes[$descriptor->key],
            $analysisHash,
            $readsByOwner,
        );

        // In owner order, not cached-then-scanned: when two files declare one
        // symbol, the declaration the graph keeps must not depend on which of
        // them this scan happened to reuse.
        $contributions = [...$partition->cached, ...$recorded['contributions'], ...$leftOut->contributions()];
        usort($contributions, static fn(ScanContribution $a, ScanContribution $b): int => strcmp($a->ownerKey, $b->ownerKey));

        return [
            'manifest' => $manifest,
            'contributions' => $contributions,
            'cache_entries' => [...$partition->cacheEntries, ...$recorded['cache_entries'], ...$leftOut->cacheEntries()],
            'left_out_paths' => [...$partition->leftOutPaths, ...$leftOut->paths()],
            'parsed' => count($scanned),
            // A left-out file is counted only as left out, on the scan that
            // found it (where it is not parsed) and on every reuse after it.
            'unchanged' => count($partition->cached) - count($partition->leftOutPaths),
            'added' => $partition->added,
            'changed' => $partition->changed,
            'scanner_metadata' => $partition->filesToScan === [] ? [] : [$manifest->id => $metadata],
            'milliseconds' => self::elapsedMilliseconds($started),
            'undiscovered_inputs' => $undiscovered->all(),
            'read_groups' => $groups,
        ];
    }

    /**
     * Everything a scan request carries except `files`, which each batch
     * supplies for itself.
     *
     * @param list<object> $files
     * @return array<string, mixed>
     */
    private static function scanRequest(LanguageDescriptor $descriptor, ScanPlan $plan, array $files): array
    {
        $request = [
            'root' => $plan->preparation->discovery->rootRealpath,
            'limits' => ['max_files' => $plan->preparation->maxFiles, 'max_file_bytes' => $plan->preparation->maxFileBytes],
        ];
        if (in_array($descriptor->key, ['typescript', 'python'], true)) {
            // What discovery leaves out, so a worker resolving imports leaves
            // it out too instead of keeping a copy of the rules that drifts.
            $request['exclusions'] = (new IgnoreMatcher($plan->preparation->configuration->ignores))->workerRules();
        }
        if ($descriptor->key === 'php') {
            $request['frameworks'] = array_keys(array_filter(['laravel' => $plan->preparation->laravel, 'symfony' => $plan->preparation->symfony]));
        } elseif ($descriptor->key === 'typescript') {
            $request['config_files'] = array_values(array_map(
                static fn($unit): string => $unit->configPath,
                array_filter($plan->preparation->discovery->units, static fn($unit): bool => $unit->kind === 'typescript'),
            ));
            $versions = self::typescriptVersions($plan->preparation->discovery->units);
            if ($versions !== []) {
                $request['typescript_versions'] = (object) $versions;
            }
            $vue = self::vueProjects($plan->preparation->discovery->units);
            if ($vue !== []) {
                $request['vue_projects'] = $vue;
            }
            $packages = self::packageDirectories($plan->preparation->discovery->units);
            if ($packages !== []) {
                $request['package_directories'] = $packages;
            }
            $declarations = self::declarationFiles($files);
            if ($declarations !== []) {
                $request['declaration_files'] = $declarations;
            }
        } elseif ($descriptor->key === 'python') {
            $request['frameworks'] = $plan->preparation->pythonFrameworks;
        } elseif ($descriptor->key === 'rust') {
            $request['frameworks'] = $plan->preparation->rustFrameworks;
            $request['config_files'] = array_values(array_map(
                static fn($unit): string => $unit->configPath,
                array_filter($plan->preparation->discovery->units, static fn($unit): bool => $unit->kind === 'cargo'),
            ));
        }

        return $request;
    }

    /**
     * Decide what a failed batch costs, and return the client to carry on with.
     *
     * A failure a smaller batch can get past (see OversizedBatch) is retried
     * on a fresh worker; anything else, and above all a cancellation, is
     * rethrown for run() to degrade or propagate. A file whose own answer
     * outgrew a size limit is left out, since it would in any batch, and
     * failing the language for it threw away every other file's facts.
     *
     * @param array{files: list<object>, budget: int, halvings: int, retries: int} $item
     * @param list<ScanContribution> $received what the worker answered before failing
     * @param array<string, mixed> $request
     */
    private function afterFailure(
        ScanBatchQueue $queue,
        LeftOutFiles $leftOut,
        array $item,
        WorkerException $error,
        array $received,
        LanguageDescriptor $descriptor,
        ScanPlan $plan,
        array $request,
        CancellationToken $cancellation,
        string $scannerId,
    ): ProcessScannerClient {
        $queue->failed($error);
        if ($error->diagnosticCode === 'WORKER_FRAME_TOO_LARGE') {
            $file = $queue->searchFrame($item, $error, $received, $scannerId);
            if ($cancellation->isCancelled()) {
                throw $error;
            }
            if ($file !== null) {
                $leftOut->add($file, $error);
            }

            return $this->pool->restart($descriptor, $plan->preparation->executionPolicy);
        }
        $retryable = OversizedBatch::signalledBy($descriptor, $error, $request) && !$cancellation->isCancelled();
        if ($retryable && count($item['files']) === 1 && OversizedBatch::leavesAFileOut($error)) {
            $leftOut->add($item['files'][0], $error);

            return $this->pool->restart($descriptor, $plan->preparation->executionPolicy);
        }
        // A single file cannot be split any further, so retrying it would
        // only burn worker restarts on the same failure.
        if (!$retryable || count($item['files']) <= 1) {
            throw ScanBatchQueue::givenUp($item, $error);
        }
        $queue->retry($item, $error);

        // The failed request closed its session, so this language needs a
        // fresh worker before the smaller batches can be sent.
        return $this->pool->restart($descriptor, $plan->preparation->executionPolicy);
    }

    /**
     * Fail a language whose every file (two or more) sent in this scan was
     * left out.
     *
     * Files reused from the cache as left out are not counted: they were not
     * sent, so they say nothing about the limits of this scan, and counting
     * them failed the language again on every rescan with no worker call.
     *
     * That is not that many oversized files: it is a limit set too low or a
     * broken worker, and a language with no facts at all must read as failed
     * rather than as a quiet success.
     */
    private static function assertNotEveryFileLeftOut(int $sent, int $leftOut): void
    {
        if ($sent < 2 || $leftOut !== $sent) {
            return;
        }

        throw new WorkerException('WORKER_EVERY_FILE_LEFT_OUT', sprintf(
            'Every one of the %1$d files was left out because its own answer outgrew a size limit. That points to '
            . 'a limit set too low or a broken worker rather than %1$d oversized files, so the language is reported '
            . 'as failed.',
            $sent,
        ));
    }

    /**
     * Name the setting that fixes a worker killed by its heap cap.
     *
     * A worker that exhausts its heap says so, but only in the words V8 uses,
     * and the reader is then left to discover on their own that the cap is a
     * setting and what it is called. The failure is not a loud one either: the
     * scan still commits, with that language's facts missing. So when the
     * cause is recognisably heap exhaustion, the diagnostic carries the fix.
     */
    private static function withRemedy(string $message, LanguageDescriptor $descriptor, ?int $memoryMb): string
    {
        // A descriptor with no cap of its own encodes no memory flag, so
        // LanguageDescriptor::withMemoryMb() returns it unchanged and a
        // configured value never reaches that worker. Quoting the value at it
        // would state a cap it never ran under, and name a setting that would
        // not have helped.
        if ($descriptor->workerMemoryMb === null) {
            return $message;
        }
        $memoryMb ??= $descriptor->workerMemoryMb;
        if (!preg_match('/heap (?:limit|out of memory)|out of memory/i', $message)) {
            return $message;
        }

        return $message . sprintf(
            ' The %s worker ran with a %d MB heap cap; raise limits.worker_memory_mb in knossos.json (or pass --worker-memory-mb) if this project needs more.',
            $descriptor->key,
            $memoryMb,
        );
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

    /**
     * The directories (`''` for the root) whose package.json depends on Vue.
     *
     * Its bundlers resolve `./Card` to `Card.vue`, and the worker needs to know
     * where that applies from manifests discovery hashed, not from which files
     * a request holds.
     *
     * @param list<ProjectUnit> $units
     * @return list<string>
     */
    private static function vueProjects(array $units): array
    {
        $directories = [];
        foreach ($units as $unit) {
            if ($unit->kind === 'node' && ($unit->metadata['vue'] ?? false) === true) {
                $directory = dirname($unit->configPath);
                $directories[] = $directory === '.' ? '' : $directory;
            }
        }
        sort($directories, SORT_STRING);

        return $directories;
    }

    /**
     * Every declaration file (`.d.ts`, `.d.mts`, `.d.cts`) among the
     * language's files, sorted, whether or not this scan reads it again.
     *
     * An ambient `declare module 'x'` satisfies `import … from 'x'` only when
     * its file is in the importer's program. A tsconfig's program lists its
     * own declarations, but a file no tsconfig includes is read in a program of
     * the files requested with it, and an incremental scan of the importer
     * alone, or a batch that split the two, left the declaration out.
     *
     * @param list<object> $files
     * @return list<string>
     */
    private static function declarationFiles(array $files): array
    {
        $declarations = [];
        foreach ($files as $file) {
            $path = (string) $file->relativePath;
            if (preg_match('/\.d\.[cm]?ts$/', $path) === 1) {
                $declarations[] = $path;
            }
        }
        sort($declarations, SORT_STRING);

        return array_values(array_unique($declarations));
    }

    /**
     * The directory of every package.json (`''` for the root), sorted.
     *
     * A file no tsconfig includes is read as part of the package it sits in:
     * that package's TypeScript and installed types, not the root's.
     *
     * @param list<ProjectUnit> $units
     * @return list<string>
     */
    private static function packageDirectories(array $units): array
    {
        $directories = [];
        foreach ($units as $unit) {
            if ($unit->kind === 'node') {
                $directory = dirname($unit->configPath);
                $directories[] = $directory === '.' ? '' : $directory;
            }
        }
        sort($directories, SORT_STRING);

        return array_values(array_unique($directories));
    }

    /**
     * The TypeScript major each package.json declares, keyed by its directory (`''` for the root).
     *
     * TypeScript 6.0 changed defaults such as `types` and `strict`, so a
     * project on 5.x checked under the worker's bundled 6.x reported errors its
     * own compiler never does. The ranges come from manifests discovery already
     * hashed, so the worker decides which defaults apply without reading more.
     * A range naming no number, such as `latest`, is left out.
     *
     * @param list<ProjectUnit> $units
     * @return array<string, int>
     */
    private static function typescriptVersions(array $units): array
    {
        $versions = [];
        foreach ($units as $unit) {
            $range = $unit->kind === 'node' ? ($unit->metadata['typescript_range'] ?? null) : null;
            if (is_string($range) && preg_match('/(\d+)/', $range, $match) === 1) {
                $directory = dirname($unit->configPath);
                $versions[$directory === '.' ? '' : $directory] = (int) $match[1];
            }
        }
        ksort($versions, SORT_STRING);

        return $versions;
    }
}
