<?php

declare(strict_types=1);

namespace Knossos\Tests\Scan;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ContributionCacheService;
use Knossos\Scan\LanguageDescriptor;
use Knossos\Scan\LanguageScanResult;
use Knossos\Scan\LanguageScanRunner;
use Knossos\Scan\LanguageWorkerPool;
use Knossos\Scan\ScanCancelledException;
use Knossos\Scan\ScanPlan;
use Knossos\Scan\ScanPreparation;
use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use Knossos\Scanner\Worker\WorkerLimits;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('scan-runner')]
final class LanguageScanRunnerTest extends TestCase
{
    /** Where the recording worker appends one line per scan request it received. */
    private ?string $recordPath = null;

    protected function tearDown(): void
    {
        if ($this->recordPath !== null && is_file($this->recordPath)) {
            unlink($this->recordPath);
        }
        $this->recordPath = null;
    }

    private function makePreparation(): ScanPreparation
    {
        return new ScanPreparation(
            configuration: new ProjectConfiguration(),
            discovery: new DiscoveryResult(
                rootRealpath: '/tmp/foo',
                files: [],
                units: [],
                diagnostics: [],
                inputHash: '',
                configurationHash: '',
            ),
            maxFiles: 0,
            maxFileBytes: 0,
            explicitBoundaries: [],
            requestedMode: 'fast',
            snapshotRetention: 0,
            executionPolicy: new WorkerExecutionPolicy(),
            laravel: false,
            symfony: false,
            configurationHashes: ['php' => '', 'typescript' => '', 'python' => ''],
            configurationMilliseconds: 0.0,
            discoveryMilliseconds: 0.0,
            planningMilliseconds: 0.0,
        );
    }

    private function makePlan(?ScanPreparation $preparation = null): ScanPlan
    {
        return new ScanPlan(
            preparation: $preparation ?? $this->makePreparation(),
            projectId: 'plan-default',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
    }

    private function makePreparationWithFiles(array $files, ?array $configurationHashes = null): ScanPreparation
    {
        $base = $this->makePreparation();
        return new ScanPreparation(
            configuration: $base->configuration,
            discovery: new DiscoveryResult(
                rootRealpath: $base->discovery->rootRealpath,
                files: $files,
                units: [],
                diagnostics: [],
                inputHash: $base->discovery->inputHash,
                configurationHash: $base->discovery->configurationHash,
            ),
            maxFiles: $base->maxFiles,
            maxFileBytes: $base->maxFileBytes,
            explicitBoundaries: $base->explicitBoundaries,
            requestedMode: $base->requestedMode,
            snapshotRetention: $base->snapshotRetention,
            executionPolicy: $base->executionPolicy,
            laravel: $base->laravel,
            symfony: $base->symfony,
            configurationHashes: $configurationHashes ?? $base->configurationHashes,
            configurationMilliseconds: $base->configurationMilliseconds,
            discoveryMilliseconds: $base->discoveryMilliseconds,
            planningMilliseconds: $base->planningMilliseconds,
        );
    }

    public function testRunWithEmptyDescriptorsReturnsEmptyLanguageScanResult(): void
    {
        $pool = $this->createStub(LanguageWorkerPool::class);
        $cache = new ContributionCacheService();
        $runner = new LanguageScanRunner([], $pool, $cache);

        $result = $runner->run($this->makePlan(), new CancellationToken());

        assertSame(true, $result instanceof LanguageScanResult);
        assertSame(0, $result->parsed);
        assertSame(0, $result->unchanged);
        assertSame(0, $result->added);
        assertSame(0, $result->changed);
        assertSame([], $result->manifests);
        assertSame([], $result->contributions);
        assertSame([], $result->cacheEntries);
        assertSame([], $result->stageMilliseconds);
        assertSame([], $result->scannerMetadata);
    }

    public function testRunThrowsScanCancelledExceptionWhenTokenPreCancelled(): void
    {
        $pool = $this->createStub(LanguageWorkerPool::class);
        $cache = new ContributionCacheService();
        $descriptor = new LanguageDescriptor(
            key: 'php',
            stage: 'php-analysis',
            languages: ['php'],
            command: ['php', '-r', 'echo 1'],
        );
        // stdClass fixture mimics DiscoveredFile (language/relativePath/contentHash access)
        $file = new \stdClass();
        $file->language = 'php';
        $file->relativePath = 'src/Foo.php';
        $file->contentHash = 'hashfoo';

        $runner = new LanguageScanRunner([$descriptor], $pool, $cache);

        $token = new CancellationToken();
        $token->cancel();

        $error = captureThrows(
            fn(): LanguageScanResult => $runner->run(
                new ScanPlan(
                    preparation: $this->makePreparationWithFiles([$file]),
                    projectId: 'plan-cancel',
                    effectiveMode: 'fast',
                    cacheByScannerPath: [],
                    deletedFiles: 0,
                ),
                $token,
            ),
            ScanCancelledException::class,
        );

        assertSame(true, $error instanceof ScanCancelledException);
    }

    private function phpDescriptor(): LanguageDescriptor
    {
        return new LanguageDescriptor(
            key: 'php',
            stage: 'php-analysis',
            languages: ['php'],
            command: ['php', '-r', 'echo 1'],
        );
    }

    private function planWithOneFile(): ScanPlan
    {
        $file = new \stdClass();
        $file->language = 'php';
        $file->relativePath = 'src/Foo.php';
        $file->contentHash = 'hashfoo';

        return new ScanPlan(
            preparation: $this->makePreparationWithFiles([$file]),
            projectId: 'plan-worker',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
    }

    public function testWorkerCancelledExceptionIsTranslatedToScanCancelled(): void
    {
        $pool = $this->createStub(LanguageWorkerPool::class);
        // A worker request aborting with WORKER_CANCELLED is a cancellation, even
        // though the local token was never flipped (the worker saw the cancel first).
        $pool->method('client')->willThrowException(
            new WorkerException('WORKER_CANCELLED', 'Scanner worker request was cancelled.'),
        );
        $runner = new LanguageScanRunner([$this->phpDescriptor()], $pool, new ContributionCacheService());

        $error = captureThrows(
            fn(): LanguageScanResult => $runner->run($this->planWithOneFile(), new CancellationToken()),
            ScanCancelledException::class,
        );

        assertSame(true, $error instanceof ScanCancelledException);
        assertSame(true, $error->getPrevious() instanceof WorkerException);
    }

    public function testGenericWorkerExceptionDegradesToADiagnosticWhenNotCancelled(): void
    {
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willThrowException(
            new WorkerException('WORKER_EXITED', 'Scanner worker exited unexpectedly.'),
        );
        $runner = new LanguageScanRunner([$this->phpDescriptor()], $pool, new ContributionCacheService());

        $result = $runner->run($this->planWithOneFile(), new CancellationToken());

        assertSame([], $result->manifests);
        assertSame([], $result->contributions);
        assertSame(1, count($result->workerDiagnostics));
        assertSame('knossos.php', $result->workerDiagnostics[0]['owner']);
        assertSame('WORKER_EXITED', $result->workerDiagnostics[0]['code']);
        assertSame(
            'php scanner failed: Scanner worker exited unexpectedly.',
            $result->workerDiagnostics[0]['message'],
        );
    }

    public function testNonWorkerFailureDegradesUnderTheGenericCode(): void
    {
        // Anything that is not a WorkerException has no diagnostic code of its
        // own, so the runner has to name the failure itself.
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willThrowException(new RuntimeException('broken pipe'));
        $runner = new LanguageScanRunner([$this->phpDescriptor()], $pool, new ContributionCacheService());

        $result = $runner->run($this->planWithOneFile(), new CancellationToken());

        assertSame(1, count($result->workerDiagnostics));
        assertSame('WORKER_FAILED', $result->workerDiagnostics[0]['code']);
    }

    public function testFailureIsTranslatedWhenTokenFlippedDuringRun(): void
    {
        $token = new CancellationToken();
        $pool = $this->createStub(LanguageWorkerPool::class);
        // Worker fails with a generic error, but the caller cancelled concurrently:
        // the flipped token makes this a cancellation.
        $pool->method('client')->willReturnCallback(function () use ($token): never {
            $token->cancel();
            throw new RuntimeException('broken pipe');
        });
        $runner = new LanguageScanRunner([$this->phpDescriptor()], $pool, new ContributionCacheService());

        $error = captureThrows(
            fn(): LanguageScanResult => $runner->run($this->planWithOneFile(), $token),
            ScanCancelledException::class,
        );

        assertSame(true, $error instanceof ScanCancelledException);
    }

    public function testRunWithFreshCancellationTokenDoesNotThrow(): void
    {
        $pool = $this->createStub(LanguageWorkerPool::class);
        $cache = new ContributionCacheService();
        $runner = new LanguageScanRunner([], $pool, $cache);

        $token = new CancellationToken();
        assertSame(false, $token->isCancelled());

        $result = $runner->run($this->makePlan(), $token);

        assertSame(false, $token->isCancelled());
        assertSame(true, $result instanceof LanguageScanResult);
        assertSame(0, $result->parsed);
    }

    private function typescriptDescriptor(): LanguageDescriptor
    {
        return new LanguageDescriptor(
            key: 'typescript',
            stage: 'typescript-analysis',
            languages: ['typescript'],
            command: ['node', '-e', 'process.exit(0)'],
        );
    }

    /**
     * A live worker speaking the NDJSON protocol. LanguageWorkerPool::client()
     * returns the final ProcessScannerClient, which cannot be doubled, so the
     * surviving language in the degradation test needs a real process.
     */
    private function fakeWorkerClient(): ProcessScannerClient
    {
        return new ProcessScannerClient(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/workers/fake-worker.php', 'compliant'],
        );
    }

    /**
     * A file fixture standing in for DiscoveredFile, as the tests above do.
     *
     * `size` is only set when a test asks for it: a fixture without one proves
     * the runner still batches a non-DiscoveredFile input on the count axis.
     */
    private function fileFixture(string $relativePath, string $language, int $size = 0): \stdClass
    {
        $file = new \stdClass();
        $file->language = $language;
        $file->relativePath = $relativePath;
        $file->contentHash = 'hash-' . $relativePath;
        if ($size > 0) {
            $file->size = $size;
        }

        return $file;
    }

    /**
     * A plan whose PHP file is already cached against the fake worker's manifest,
     * so the surviving language contributes facts without a scan round trip.
     *
     * @param list<\stdClass> $files
     */
    private function planWithCachedPhpFile(array $files): ScanPlan
    {
        $payload = json_encode(
            ['owner_key' => 'knossos.fake:file:src/Foo.php', 'nodes' => [], 'edges' => [], 'diagnostics' => []],
            JSON_THROW_ON_ERROR,
        );

        // A cache entry rejects an empty configuration hash, so the preparation
        // has to carry a real one for the reuse path to complete.
        $hashes = ['php' => 'cfg-php', 'typescript' => 'cfg-ts', 'python' => 'cfg-py'];

        return new ScanPlan(
            preparation: $this->makePreparationWithFiles($files, $hashes),
            projectId: 'plan-degraded',
            effectiveMode: 'fast',
            cacheByScannerPath: ["knossos.fake\0src/Foo.php" => [
                'content_hash' => 'hash-src/Foo.php',
                'scanner_version' => '0.1.0',
                'configuration_hash' => 'cfg-php',
                'payload_json' => $payload,
            ]],
            deletedFiles: 0,
        );
    }

    /**
     * A runner whose TypeScript worker times out and whose PHP worker is live.
     *
     * @param list<LanguageDescriptor> $descriptors in the order the runner walks them
     */
    private function runnerWithFailingTypescript(array $descriptors): LanguageScanRunner
    {
        $pool = $this->createStub(LanguageWorkerPool::class);
        $client = $this->fakeWorkerClient();
        $pool->method('client')->willReturnCallback(
            function (LanguageDescriptor $descriptor) use ($client): ProcessScannerClient {
                if ($descriptor->key === 'typescript') {
                    throw new WorkerException('WORKER_TIMEOUT', 'Scanner worker request timed out.');
                }

                return $client;
            },
        );

        return new LanguageScanRunner($descriptors, $pool, new ContributionCacheService());
    }

    /** The two-language plan both isolation tests run, PHP cached and TypeScript fresh. */
    private function twoLanguagePlan(): ScanPlan
    {
        return $this->planWithCachedPhpFile([
            $this->fileFixture('src/Foo.php', 'php'),
            $this->fileFixture('src/a.ts', 'typescript'),
        ]);
    }

    /**
     * Both isolation tests assert the same surviving facts; only the descriptor
     * order differs.
     */
    private function assertPhpSurvivedTypescriptTimeout(LanguageScanResult $result): void
    {
        assertSame(1, count($result->contributions));
        assertSame(1, count($result->manifests));
        assertSame(1, $result->unchanged);
        assertSame(1, count($result->workerDiagnostics));
        assertSame('WORKER_TIMEOUT', $result->workerDiagnostics[0]['code']);
        assertSame('knossos.typescript', $result->workerDiagnostics[0]['owner']);
    }

    public function testOneFailingWorkerDoesNotDiscardAnotherLanguagesFacts(): void
    {
        // PHP succeeds, TypeScript throws. The PHP graph must survive and the
        // TypeScript failure must arrive as a diagnostic, not an exception.
        $runner = $this->runnerWithFailingTypescript([$this->phpDescriptor(), $this->typescriptDescriptor()]);

        $this->assertPhpSurvivedTypescriptTimeout($runner->run($this->twoLanguagePlan(), new CancellationToken()));
    }

    public function testAFailingWorkerDoesNotStopTheLanguagesAfterIt(): void
    {
        // The same scenario with the failure FIRST. Without this ordering a
        // `break` or `return` in place of the catch's `continue` would leave the
        // suite green while defeating the whole point of the isolation.
        $runner = $this->runnerWithFailingTypescript([$this->typescriptDescriptor(), $this->phpDescriptor()]);

        $this->assertPhpSurvivedTypescriptTimeout($runner->run($this->twoLanguagePlan(), new CancellationToken()));
    }

    public function testCancellationStillPropagatesRatherThanDegrading(): void
    {
        // Two descriptors: were cancellation degraded like a worker fault, the
        // runner would carry on to TypeScript and return a partial result.
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willThrowException(
            new WorkerException('WORKER_CANCELLED', 'Scanner worker request was cancelled.'),
        );
        $runner = new LanguageScanRunner(
            [$this->phpDescriptor(), $this->typescriptDescriptor()],
            $pool,
            new ContributionCacheService(),
        );

        $error = captureThrows(
            fn(): LanguageScanResult => $runner->run(
                $this->planWithCachedPhpFile([
                    $this->fileFixture('src/Foo.php', 'php'),
                    $this->fileFixture('src/a.ts', 'typescript'),
                ]),
                new CancellationToken(),
            ),
            ScanCancelledException::class,
        );

        assertSame(true, $error instanceof ScanCancelledException);
    }

    /**
     * A live worker that answers one contribution per requested file and appends
     * every request's file count to $this->recordPath. Going through the real
     * NDJSON protocol is what makes the batching observable: each request is a
     * separate `beginRequest()`, which is the whole point of the split.
     */
    private function recordingClient(): ProcessScannerClient
    {
        $this->allocateRecordPath();

        return $this->workerClient('per_file');
    }

    /** Open the file every worker in this test appends its request sizes to. */
    private function allocateRecordPath(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-scan-batches-');
        if ($path === false) {
            throw new RuntimeException('Unable to create the batch record file.');
        }
        $this->recordPath = $path;
    }

    /**
     * A recording worker in one of the `per_file*` modes, reusing the record
     * file already opened by recordingClient() so a restarted worker keeps
     * appending to the same log.
     *
     * @param int $threshold requests for more files than this are refused by the
     *        overflow and exit modes
     * @param bool $tightCap give the client an output cap the fixture's flood
     *        crosses in a few frames, instead of the production 20 MB
     */
    private function workerClient(string $mode, int $threshold = 0, bool $tightCap = false): ProcessScannerClient
    {
        return new ProcessScannerClient(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/Fixtures/workers/fake-worker.php',
                $mode,
                (string) $this->recordPath,
                (string) $threshold,
            ],
            // maxOutputBytes may not fall below maxLineBytes, so both come down.
            $tightCap ? new WorkerLimits(maxLineBytes: 100_000, maxOutputBytes: 200_000) : new WorkerLimits(),
        );
    }

    /**
     * A runner whose pool serves — and restarts — one language's worker from a
     * factory, so a retry after a closed session gets a live process the way
     * production does.
     */
    private function runnerWithWorkerFactory(callable $factory, LanguageDescriptor $descriptor): LanguageScanRunner
    {
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willReturnCallback($factory);
        $pool->method('restart')->willReturnCallback($factory);

        return new LanguageScanRunner([$descriptor], $pool, new ContributionCacheService());
    }

    /**
     * The file count of every scan request the recording worker received, in order.
     *
     * @return list<int>
     */
    private function recordedBatches(): array
    {
        $contents = $this->recordPath === null ? '' : (string) file_get_contents($this->recordPath);
        $lines = array_filter(explode("\n", $contents), static fn(string $line): bool => $line !== '');

        return array_map(intval(...), array_values($lines));
    }

    /**
     * A descriptor for one language key, claiming files of the same-named
     * language, with the packaged batch bounds unless a test overrides them.
     */
    private function descriptorFor(string $key, ?int $batchFiles = null, ?int $batchSourceBytes = null): LanguageDescriptor
    {
        return new LanguageDescriptor(
            key: $key,
            stage: $key . '-analysis',
            languages: [$key],
            command: ['php', '-r', 'echo 1'],
            scanBatchFiles: $batchFiles ?? WorkerExecutionPolicy::SCAN_BATCH_FILES,
            scanBatchSourceBytes: $batchSourceBytes ?? WorkerExecutionPolicy::SCAN_BATCH_SOURCE_BYTES,
        );
    }

    /**
     * A runner whose pool hands back the given client for each language key.
     *
     * @param array<string, ProcessScannerClient> $clients keyed by descriptor key
     * @param list<LanguageDescriptor> $descriptors overriding the default one-per-key set
     */
    private function runnerWithClients(array $clients, array $descriptors = []): LanguageScanRunner
    {
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willReturnCallback(
            static fn(LanguageDescriptor $descriptor): ProcessScannerClient => $clients[$descriptor->key],
        );

        return new LanguageScanRunner(
            $descriptors === [] ? array_map($this->descriptorFor(...), array_keys($clients)) : $descriptors,
            $pool,
            new ContributionCacheService(),
        );
    }

    /**
     * A plan whose discovered files are exactly the given paths, none cached.
     *
     * @param array<string, string> $files relative path => language
     * @param int $size byte size reported for every file, for the byte axis
     */
    private function planForFiles(array $files, int $size = 0): ScanPlan
    {
        $fixtures = array_map(
            fn(string $language, string $path): \stdClass => $this->fileFixture($path, $language, $size),
            array_values($files),
            array_keys($files),
        );

        return new ScanPlan(
            // A cache entry rejects an empty configuration hash, so the
            // preparation carries real ones for the recorded scan to complete.
            preparation: $this->makePreparationWithFiles(
                $fixtures,
                ['php' => 'cfg-php', 'typescript' => 'cfg-ts', 'python' => 'cfg-py'],
            ),
            projectId: 'plan-batches',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
    }

    /**
     * 900 distinct PHP paths, enough to need three batches at 400 per request.
     *
     * @return array<string, string>
     */
    private function nineHundredPhpFiles(): array
    {
        $files = [];
        for ($index = 0; $index < 900; ++$index) {
            $files[sprintf('src/File%03d.php', $index)] = 'php';
        }

        return $files;
    }

    public function testAScanIsSplitIntoBoundedBatches(): void
    {
        // Each batch gets its own beginRequest(), so the byte cap and the
        // deadline are per batch rather than per project.
        $runner = $this->runnerWithClients(['php' => $this->recordingClient()]);

        $runner->run($this->planForFiles($this->nineHundredPhpFiles()), new CancellationToken());

        // 900 files at 400 per batch = 3 scan calls.
        assertSame([400, 400, 100], $this->recordedBatches());
    }

    public function testASingleBatchIsStillOneRequest(): void
    {
        $runner = $this->runnerWithClients(['php' => $this->recordingClient()]);

        $runner->run($this->planForFiles(['src/Foo.php' => 'php']), new CancellationToken());

        assertSame([1], $this->recordedBatches());
    }

    public function testEveryBatchCarriesTheRequestFieldsBesidesFiles(): void
    {
        // `['files' => $batch] + $request` must override only `files`: the wrong
        // operand order silently resends the whole file list every batch, and
        // dropping the rest would strip `root`, `limits` and the language extras.
        $runner = $this->runnerWithClients(['php' => $this->recordingClient()]);

        $result = $runner->run($this->planForFiles($this->nineHundredPhpFiles()), new CancellationToken());

        assertSame(900, count($result->contributions));
        assertSame(900, $result->parsed);
    }

    public function testScannerMetadataSumsEveryCounterAcrossBatches(): void
    {
        $result = $this->runnerWithClients(['php' => $this->recordingClient()])
            ->run($this->planForFiles($this->nineHundredPhpFiles()), new CancellationToken());

        $metadata = $result->scannerMetadata['knossos.fake'];
        // Every integer a worker reports counts what THAT request did, so all of
        // them are summed — not just files_scanned. The real TypeScript worker
        // returns programs and programs_reused exactly this way, and keeping the
        // last batch's value would report one batch as the language total.
        assertSame(900, $metadata['files_scanned']);
        assertSame(3, $metadata['programs']);
        assertSame(2, $metadata['programs_reused']);
        // A non-integer is a name, not a count: the newest batch's value wins.
        assertSame('fake-3', $metadata['parser']);
    }

    public function testABatchIsAlsoBoundedByCumulativeSourceBytes(): void
    {
        // Protocol output tracks source size, not file count, so the byte axis
        // has to be able to split a batch well before the file cap: 12 files of
        // 100 KB against a 300 KB budget is 3 files per request, not one
        // request of 12.
        $files = [];
        for ($index = 0; $index < 12; ++$index) {
            $files[sprintf('src/Big%02d.php', $index)] = 'php';
        }
        $runner = $this->runnerWithClients(
            ['php' => $this->recordingClient()],
            [$this->descriptorFor('php', batchSourceBytes: 300_000)],
        );

        $runner->run($this->planForFiles($files, 100_000), new CancellationToken());

        assertSame([3, 3, 3, 3], $this->recordedBatches());
    }

    public function testAFileLargerThanTheWholeByteBudgetStillGetsARequest(): void
    {
        // Nothing smaller than one file can be sent, so an oversized file must
        // occupy a request of its own rather than produce an empty batch or an
        // endless loop.
        $runner = $this->runnerWithClients(
            ['php' => $this->recordingClient()],
            [$this->descriptorFor('php', batchSourceBytes: 1_000)],
        );

        $runner->run(
            $this->planForFiles(['src/A.php' => 'php', 'src/B.php' => 'php'], 50_000),
            new CancellationToken(),
        );

        assertSame([1, 1], $this->recordedBatches());
    }

    public function testADescriptorCanRaiseItsOwnFileBatchSize(): void
    {
        // TypeScript pays a whole-program cost on every request regardless of
        // how many files it was asked for, so its descriptor raises the file cap
        // to keep a normal project in one request. The bound has to come from
        // the descriptor, not from a single global constant.
        $runner = $this->runnerWithClients(
            ['php' => $this->recordingClient()],
            [$this->descriptorFor('php', batchFiles: 2_000)],
        );

        $runner->run($this->planForFiles($this->nineHundredPhpFiles()), new CancellationToken());

        assertSame([900], $this->recordedBatches());
    }

    public function testCancellationDuringABatchStopsTheRemainingRequests(): void
    {
        // A cancelled scan must abandon the language's remaining batches rather
        // than run all 900 files to completion. The poll closure latches as soon
        // as the worker records the first request, so cancellation lands while
        // batch 1 is still streaming and batch 2 is never sent.
        //
        // Note this pins the outcome, not the in-loop throwIfCancelled() by
        // itself: ScannerProtocolSession consults the same token per frame and
        // in send(), so with a live protocol client the loop checkpoint is
        // belt-and-braces for the gap between batches, where no request is in
        // flight to carry the callback.
        $recorded = fn(): int => count($this->recordedBatches());
        $token = new CancellationToken(static fn(): bool => $recorded() >= 1);
        $runner = $this->runnerWithClients(['php' => $this->recordingClient()]);

        $error = captureThrows(
            fn(): LanguageScanResult => $runner->run($this->planForFiles($this->nineHundredPhpFiles()), $token),
            ScanCancelledException::class,
        );

        assertSame(true, $error instanceof ScanCancelledException);
        assertSame([400], $this->recordedBatches());
    }

    /**
     * Eight files of 100 KB, so a 400 KB budget puts four in a request.
     *
     * @return array<string, string>
     */
    private function eightBigPhpFiles(): array
    {
        $files = [];
        for ($index = 0; $index < 8; ++$index) {
            $files[sprintf('src/Big%d.php', $index)] = 'php';
        }

        return $files;
    }

    public function testAnOverflowingBatchHalvesTheBudgetAndRetries(): void
    {
        // No static estimate of protocol output holds for every codebase, so the
        // budget is optimistic and corrects itself against the cap actually
        // being hit. The worker here refuses any request for more than two
        // files, so the first 4-file request overflows, the budget halves to
        // 200 KB, and the work is re-split into 2-file requests that succeed.
        $this->allocateRecordPath();
        $descriptor = $this->descriptorFor('php', batchSourceBytes: 400_000);
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_overflow', threshold: 2, tightCap: true),
            $descriptor,
        );

        $result = $runner->run($this->planForFiles($this->eightBigPhpFiles(), 100_000), new CancellationToken());

        // One doomed 4-file request, then the whole language re-split at 2.
        assertSame([4, 2, 2, 2, 2], $this->recordedBatches());
        assertSame([], $result->workerDiagnostics);
        assertSame(8, $result->parsed);
        // The overflowing request had already streamed frames; counting them
        // would double-count the files the retry re-sends.
        assertSame(8, $result->scannerMetadata['knossos.fake']['files_scanned']);
        // The settled budget is reported, not the one the scan started with.
        assertSame(400_000, $result->batchBudgets['php']['source_bytes']);
        assertSame(200_000, $result->batchBudgets['php']['source_bytes_used']);
    }

    public function testHalvingIsBoundedAndThenDegradesTheLanguage(): void
    {
        // A worker that refuses every request must not be retried forever. After
        // the bounded halvings the failure falls through to the per-language
        // degrade path exactly as any other worker fault does.
        $this->allocateRecordPath();
        $descriptor = $this->descriptorFor('php', batchSourceBytes: 400_000);
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_overflow', threshold: 0, tightCap: true),
            $descriptor,
        );

        $result = $runner->run($this->planForFiles($this->eightBigPhpFiles(), 100_000), new CancellationToken());

        // The initial attempt plus MAX_SCAN_BATCH_HALVINGS retries, then stop.
        assertSame(1 + WorkerExecutionPolicy::MAX_SCAN_BATCH_HALVINGS, count($this->recordedBatches()));
        assertSame(1, count($result->workerDiagnostics));
        assertSame('WORKER_OUTPUT_LIMIT', $result->workerDiagnostics[0]['code']);
        assertSame('knossos.php', $result->workerDiagnostics[0]['owner']);
        // Reported even though the language failed: how far the budget fell is
        // exactly what an operator needs to see here.
        assertSame(25_000, $result->batchBudgets['php']['source_bytes_used']);
    }

    public function testAFailureThatIsNotAnOutputOverflowIsNeverRetried(): void
    {
        // WORKER_OUTPUT_LIMIT is the only code that says "the batch was too
        // big". Anything else says the worker is broken, and retrying it would
        // multiply the cost of a failure Task 4 already handles.
        $this->allocateRecordPath();
        $descriptor = $this->descriptorFor('php', batchSourceBytes: 400_000);
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_exit', threshold: 0),
            $descriptor,
        );

        $result = $runner->run($this->planForFiles($this->eightBigPhpFiles(), 100_000), new CancellationToken());

        assertSame(1, count($this->recordedBatches()));
        assertSame(1, count($result->workerDiagnostics));
        assertSame('knossos.php', $result->workerDiagnostics[0]['owner']);
        assertSame(400_000, $result->batchBudgets['php']['source_bytes_used']);
    }

    public function testBatchBudgetsAreReportedForALanguageThatNeededNoRetry(): void
    {
        $result = $this->runnerWithClients(['php' => $this->recordingClient()])
            ->run($this->planForFiles($this->nineHundredPhpFiles()), new CancellationToken());

        assertSame(
            ['files' => 400, 'source_bytes' => 4_000_000, 'source_bytes_used' => 4_000_000],
            $result->batchBudgets['php'],
        );
    }
}
