<?php

declare(strict_types=1);

namespace Knossos\Tests\Scan;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Discovery\IgnoreMatcher;
use Knossos\Discovery\ProjectUnit;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\AnalysisHash;
use Knossos\Scan\ContributionCacheService;
use Knossos\Scan\LanguageDescriptor;
use Knossos\Scan\LanguageScanResult;
use Knossos\Scan\LanguageScanRunner;
use Knossos\Scan\LanguageWorkerPool;
use Knossos\Scan\ScanBatchQueue;
use Knossos\Scan\ScanCancelledException;
use Knossos\Scan\ScanPlan;
use Knossos\Scan\ScanPreparation;
use Knossos\Scan\ScanSnapshotChangedException;
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
        // The sibling marker too: the `per_file_overflow_once` fixture writes
        // `<record>.overflowed` next to the record, so deleting only the record
        // left one file in the system temp directory per run of
        // testAReducedBudgetDoesNotPinTheRestOfTheLanguage().
        if ($this->recordPath !== null) {
            foreach ([$this->recordPath, $this->recordPath . '.overflowed', $this->recordPath . '.oomed', $this->recordPath . '.framed', $this->recordPath . '.sigtermed', $this->recordPath . '.request'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
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

    private function makePreparationWithFiles(array $files, ?array $configurationHashes = null, ?WorkerExecutionPolicy $policy = null): ScanPreparation
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
            executionPolicy: $policy ?? $base->executionPolicy,
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
        // Before any worker is asked for: starting one only to cancel it costs a
        // process spawn per language, for a scan nobody wants any more.
        $pool = $this->createMock(LanguageWorkerPool::class);
        $pool->expects($this->never())->method('client');
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

    private function planWithOneFile(string $language = 'php', ?WorkerExecutionPolicy $policy = null): ScanPlan
    {
        $file = new \stdClass();
        $file->language = $language;
        $file->relativePath = match ($language) {
            'php' => 'src/Foo.php', 'python' => 'src/foo.py', default => 'src/foo.ts',
        };
        $file->contentHash = 'hashfoo';

        return new ScanPlan(
            preparation: $this->makePreparationWithFiles([$file], policy: $policy),
            projectId: 'plan-worker',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
    }

    /**
     * A cached contribution is keyed on the bytes of the worker's own files, so
     * editing one of them re-analyses what that worker produced, on the next
     * scan of the same long-running process.
     */
    public function testEditingAWorkerFileInvalidatesItsCachedContributions(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/worker', 0o777, true);
        file_put_contents($root . '/worker/analysis.php', "<?php // one\n");
        try {
            $descriptor = new LanguageDescriptor(
                key: 'php',
                languages: ['php'],
                command: ['php', '-r', 'echo 1'],
                stage: 'php-analysis',
                analysisInputs: ['worker/analysis.php'],
            );
            $pool = $this->createStub(LanguageWorkerPool::class);
            $pool->method('client')->willReturn($this->fakeWorkerClient());
            $runner = new LanguageScanRunner([$descriptor], $pool, new ContributionCacheService(), installationRoot: $root);
            $stored = '0.1.0+' . substr(AnalysisHash::of($root, ['worker/analysis.php']), 0, 16);
            $plan = $this->planWithCachedPhpFile([$this->fileFixture('src/Foo.php', 'php')], $stored);

            $reused = $runner->run($plan, new CancellationToken());

            assertSame(1, $reused->unchanged);

            file_put_contents($root . '/worker/analysis.php', "<?php // two\n");
            $rescanned = $runner->run($plan, new CancellationToken());

            assertSame(0, $rescanned->unchanged);
            assertSame(1, $rescanned->changed);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
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

    public function testAHeapExhaustionFailureNamesTheSettingThatFixesIt(): void
    {
        // V8's own words do not mention that the cap is a setting, or what it
        // is called, and the scan still commits with the language missing. A
        // reader who is not told has to work it out from the source.
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willThrowException(new WorkerException(
            'WORKER_EXITED',
            'Scanner worker exited before responding (exit 134). Worker stderr: FATAL ERROR: '
                . 'Reached heap limit Allocation failed - JavaScript heap out of memory',
        ));
        $descriptor = new LanguageDescriptor(
            key: 'typescript',
            stage: 'typescript-analysis',
            languages: ['typescript'],
            command: ['node', '--max-old-space-size=2048', 'worker.js'],
            workerMemoryMb: 2048,
        );
        $runner = new LanguageScanRunner([$descriptor], $pool, new ContributionCacheService());

        $result = $runner->run($this->planWithOneFile('typescript'), new CancellationToken());

        $message = $result->workerDiagnostics[0]['message'] ?? '';
        assertContains('JavaScript heap out of memory', $message);
        assertContains('limits.worker_memory_mb', $message);
        assertContains('2048 MB', $message);
    }

    public function testAWorkerThatEnforcesNoCapIsNotToldItRanWithOne(): void
    {
        // Python and Rust encode no memory flag, so LanguageDescriptor::withMemoryMb()
        // returns them unchanged and a configured cap never reaches them. Telling
        // such a worker it "ran with a 2048 MB heap cap" states something that
        // never happened, and points at a setting that would not have helped.
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willThrowException(new WorkerException(
            'WORKER_EXITED',
            'Scanner worker exited before responding. Worker stderr: out of memory',
        ));
        $uncapped = new LanguageDescriptor(
            key: 'python',
            stage: 'python-analysis',
            languages: ['python'],
            command: ['python3', 'worker.py'],
        );
        $runner = new LanguageScanRunner([$uncapped], $pool, new ContributionCacheService());

        $result = $runner->run(
            $this->planWithOneFile('python', new WorkerExecutionPolicy(30_000, workerMemoryMb: 2048)),
            new CancellationToken(),
        );

        $message = $result->workerDiagnostics[0]['message'] ?? '';
        assertContains('out of memory', $message);
        assertSame(false, str_contains($message, 'worker_memory_mb'));
        assertSame(false, str_contains($message, '2048'));
    }

    public function testAnUnrelatedWorkerFailureCarriesNoMemoryAdvice(): void
    {
        // The advice is only right when the heap is what killed it. Attaching
        // it to every failure would train the reader to ignore it.
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willThrowException(
            new WorkerException('WORKER_EXITED', 'Scanner worker exited unexpectedly.'),
        );
        $runner = new LanguageScanRunner([$this->phpDescriptor()], $pool, new ContributionCacheService());

        $result = $runner->run($this->planWithOneFile(), new CancellationToken());

        assertSame(
            'php scanner failed: Scanner worker exited unexpectedly.',
            $result->workerDiagnostics[0]['message'],
        );
    }

    /**
     * Degrading this would be worse than the race it reports: the language's
     * facts would be dropped and the graph committed without them while every
     * file hash still matched disk, so the scan would read as fresh.
     */
    public function testASnapshotChangeFailsTheScanRatherThanDegradingTheLanguage(): void
    {
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willThrowException(ScanSnapshotChangedException::parsedDifferently('src/Foo.php'));
        $runner = new LanguageScanRunner([$this->phpDescriptor()], $pool, new ContributionCacheService());

        $error = captureThrows(
            fn(): LanguageScanResult => $runner->run($this->planWithOneFile(), new CancellationToken()),
            ScanSnapshotChangedException::class,
        );

        assertContains('src/Foo.php', $error->getMessage());
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
    private function planWithCachedPhpFile(array $files, ?string $cachedVersion = null): ScanPlan
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
                'scanner_version' => $cachedVersion ?? '0.1.0+' . substr(AnalysisHash::of('', []), 0, 16),
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

        // Each 4-file batch overflows once and is then re-split into 2s. The
        // second batch pays its own doomed request because the reduction is
        // scoped to the batch that provoked it — one wasted request per
        // oversized batch, rather than pinning the whole language.
        assertSame([4, 2, 2, 4, 2, 2], $this->recordedBatches());
        assertSame([], $result->workerDiagnostics);
        assertSame(8, $result->parsed);
        // The overflowing request had already streamed frames; counting them
        // would double-count the files the retry re-sends.
        assertSame(8, $result->scannerMetadata['knossos.fake']['files_scanned']);
        // The narrowest budget any request ran at is reported, not the start.
        assertSame(400_000, $result->batchBudgets['knossos.php']['source_bytes']);
        assertSame(200_000, $result->batchBudgets['knossos.php']['source_bytes_used']);
    }

    public function testTypeScriptHeapExhaustionSplitsTheBatchAndRecovers(): void
    {
        $this->allocateRecordPath();
        $descriptor = $this->descriptorFor('typescript', batchSourceBytes: 400_000);
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_oom_once', threshold: 2, tightCap: true),
            $descriptor,
        );

        $files = array_fill_keys(array_keys($this->eightBigPhpFiles()), 'typescript');
        $result = $runner->run($this->planForFiles($files, 100_000), new CancellationToken());

        assertSame([4, 2, 2, 4], $this->recordedBatches());
        assertSame([], $result->workerDiagnostics);
        assertSame(8, $result->parsed);
        assertSame(200_000, $result->batchBudgets['knossos.typescript']['source_bytes_used']);
    }

    public function testAnOrdinaryTypeScriptWorkerExitIsNotRetried(): void
    {
        $this->allocateRecordPath();
        $descriptor = $this->descriptorFor('typescript', batchSourceBytes: 400_000);
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_exit', threshold: 2),
            $descriptor,
        );

        $files = array_fill_keys(array_keys($this->eightBigPhpFiles()), 'typescript');
        $result = $runner->run($this->planForFiles($files, 100_000), new CancellationToken());

        assertSame([4], $this->recordedBatches());
        assertSame('WORKER_EXITED', $result->workerDiagnostics[0]['code']);
    }

    public function testFrameTooLargeSplitsTheBatchAndRecovers(): void
    {
        $this->allocateRecordPath();
        $descriptor = $this->descriptorFor('php', batchSourceBytes: 400_000);
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_frame_too_large_once', threshold: 2, tightCap: true),
            $descriptor,
        );

        $files = array_fill_keys(array_keys($this->eightBigPhpFiles()), 'php');
        $result = $runner->run($this->planForFiles($files, 100_000), new CancellationToken());

        // Nothing was answered, so the first file is tried alone and the rest
        // behind it at half their bytes, one file each at 100 KB apiece.
        assertSame([4, 1, 1, 1, 1, 4], $this->recordedBatches());
        assertSame([], $result->workerDiagnostics);
        assertSame(8, $result->parsed);
        // A frame search splits a batch only to find one file, so it is not a
        // budget the language's batches settled on.
        assertSame(400_000, $result->batchBudgets['knossos.php']['source_bytes_used']);
    }

    public function testAWorkerKilledFromOutsideIsRetriedInSmallerBatchesAndRecovers(): void
    {
        // A host memory guard such as earlyoom SIGTERMs the largest process
        // when memory runs low, and a TypeScript worker mid-request is often
        // that process. Nothing about the batch is wrong, so the language must
        // not be lost to it: a fresh worker and a smaller request get the
        // scan through, as they do for the worker's own heap exhaustion.
        $this->allocateRecordPath();
        $descriptor = $this->descriptorFor('typescript', batchSourceBytes: 400_000);
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_sigterm_once', threshold: 2),
            $descriptor,
        );

        $files = array_fill_keys(array_keys($this->eightBigPhpFiles()), 'typescript');
        $result = $runner->run($this->planForFiles($files, 100_000), new CancellationToken());

        assertSame([4, 2, 2, 4], $this->recordedBatches());
        assertSame([], $result->workerDiagnostics);
        assertSame(8, $result->parsed);
        assertSame(200_000, $result->batchBudgets['knossos.typescript']['source_bytes_used']);
    }

    public function testRepeatedOutsideKillsKeepSplittingUntilTheBatchesFit(): void
    {
        // Each kill splits only the batch it hit, down to whatever size the
        // host can afford, and every file still ends up scanned.
        $this->allocateRecordPath();
        $descriptor = $this->descriptorFor('typescript', batchSourceBytes: 400_000);
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_sigterm', threshold: 1),
            $descriptor,
        );

        $files = array_fill_keys(array_keys($this->eightBigPhpFiles()), 'typescript');
        $result = $runner->run($this->planForFiles($files, 100_000), new CancellationToken());

        // 4 is killed, halves to 2s; the first 2 is killed and halves to 1s,
        // which the worker answers; the second 2 is killed again and its 1s
        // succeed. Then the second 4 does the same.
        assertSame([4, 2, 1, 1, 2, 1, 1, 4, 2, 1, 1, 2, 1, 1], $this->recordedBatches());
        assertSame([], $result->workerDiagnostics);
        assertSame(8, $result->parsed);
    }

    public function testAWorkerKilledFromOutsideWhenNothingIsLeftToSplitNamesTheCause(): void
    {
        // When the retries run out the language degrades, and the diagnostic
        // has to send the reader to the right place: the signal, that Knossos
        // did not send it, what usually does, and that smaller batches were
        // already tried.
        $this->allocateRecordPath();
        $descriptor = $this->descriptorFor('typescript', batchSourceBytes: 200_000);
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_sigterm', threshold: 0),
            $descriptor,
        );

        $files = ['src/a.ts' => 'typescript', 'src/b.ts' => 'typescript'];
        $result = $runner->run($this->planForFiles($files, 100_000), new CancellationToken());

        assertSame([2, 1], $this->recordedBatches());
        assertSame(1, count($result->workerDiagnostics));
        assertSame('WORKER_EXITED', $result->workerDiagnostics[0]['code']);
        $message = $result->workerDiagnostics[0]['message'];
        assertContains('killed by signal 15 (SIGTERM)', $message);
        assertContains('Knossos did not send it', $message);
        assertContains('earlyoom', $message);
        assertContains('after 1 retry in smaller batches with a fresh worker', $message);
        assertSame(false, str_contains($message, 'look outside'), $message);
    }

    public function testAFileTooLargeForOneFrameIsLeftOutWithoutLosingTheLanguage(): void
    {
        // A generated bundle can describe more facts than one 2 MB frame
        // holds. Halving narrows the failure down to that one file, and from
        // there no split helps; failing the language for it threw away every
        // other file's facts and left a graph with 0 nodes.
        $this->allocateRecordPath();
        $descriptor = $this->descriptorFor('typescript', batchSourceBytes: 400_000);
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_frame_too_large_for_huge', tightCap: true),
            $descriptor,
        );

        $files = [
            'src/a.ts' => 'typescript',
            'src/Huge.js' => 'typescript',
            'src/b.ts' => 'typescript',
            'src/c.ts' => 'typescript',
        ];
        $result = $runner->run($this->planForFiles($files, 100_000), new CancellationToken());

        // The worker answered a, b and c before Huge's frame failed, so Huge
        // is tried alone and left out, and the answered three go back as one.
        assertSame([4, 1, 3], $this->recordedBatches());
        assertSame([], $result->workerDiagnostics);
        // Parsed counts the files a worker answered for, which Huge was not.
        assertSame(3, $result->parsed);
        $skipped = array_values(array_filter(
            $result->contributions,
            static fn($contribution): bool => $contribution->ownerKey === 'knossos.fake:file:src/Huge.js',
        ));
        assertSame(1, count($skipped));
        assertSame([], $skipped[0]->nodes);
        assertSame('WORKER_FRAME_TOO_LARGE', $skipped[0]->diagnostics[0]->code);
        assertSame(
            "Left out of the graph: the scanner's answer for src/Huge.js alone was too large. Worker frame exceeds "
            . 'the 100000-byte line limit (worker_execution.max_line_bytes), so Knossos stopped the worker. A single '
            . 'file cannot be split any further, so its facts are omitted and the rest of the language is kept.',
            $skipped[0]->diagnostics[0]->message,
        );
        assertSame(1, $result->leftOut);
        // Cached like the others, but under a key that also names the limits
        // it failed, so a raised limit scans it afresh.
        $cached = [];
        foreach ($result->cacheEntries as $entry) {
            $cached[$entry->filePath] = $entry->configurationHash;
        }
        assertSame(['src/a.ts', 'src/b.ts', 'src/c.ts', 'src/Huge.js'], array_keys($cached));
        assertSame('cfg-ts', $cached['src/a.ts']);
        assertSame(
            ContributionCacheService::leftOutConfigurationHash('cfg-ts', (new WorkerExecutionPolicy())->limits()),
            $cached['src/Huge.js'],
        );
    }

    public function testAnUnchangedLeftOutFileIsReusedRatherThanRescannedAsAdded(): void
    {
        // Uncached, a left-out file counted as added on every rescan: that
        // defeated the no-change fast path and paid the halving again each
        // time, only to leave the same file out.
        $this->allocateRecordPath();
        $descriptor = $this->descriptorFor('typescript', batchSourceBytes: 400_000);
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_frame_too_large_for_huge', tightCap: true),
            $descriptor,
        );
        $files = ['src/a.ts' => 'typescript', 'src/Huge.js' => 'typescript'];
        $first = $runner->run($this->planForFiles($files, 100_000), new CancellationToken());
        $requestsBefore = count($this->recordedBatches());

        $cache = [];
        foreach ($first->cacheEntries as $entry) {
            $cache[$entry->scannerId . "\0" . $entry->filePath] = [
                'content_hash' => $entry->contentHash,
                'scanner_version' => $entry->scannerVersion,
                'configuration_hash' => $entry->configurationHash,
                'payload_json' => json_encode($entry->contribution, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ];
        }
        $plan = $this->planForFiles($files, 100_000);
        $second = $runner->run(
            new ScanPlan($plan->preparation, $plan->projectId, 'incremental', $cache, 0),
            new CancellationToken(),
        );

        assertSame($requestsBefore, count($this->recordedBatches()), 'Nothing was sent: both files were reused.');
        assertSame(0, $second->added);
        assertSame(0, $second->changed);
        // Counted as left out and nothing else, as on the scan that left it
        // out, where it was neither parsed nor unchanged.
        assertSame(1, $second->unchanged);
        assertSame(1, $second->leftOut);
        assertSame(['src/Huge.js'], $second->leftOutPaths);
        assertSame([], $second->workerDiagnostics);
        $huge = array_values(array_filter(
            $second->contributions,
            static fn($contribution): bool => $contribution->ownerKey === 'knossos.fake:file:src/Huge.js',
        ));
        assertContains('Left out of the graph', $huge[0]->diagnostics[0]->message);
    }

    public function testASmallFileWithAnOversizedFrameIsFoundAmongManyNeighbours(): void
    {
        // A 120 KB minified bundle can still emit a frame over 2 MB. Halving a
        // 32-file batch by bytes four times left it with neighbours, the bound
        // was hit, and the whole language degraded. The files the worker had
        // already answered are not the culprit, so only the rest is split.
        $this->allocateRecordPath();
        $files = [];
        for ($index = 0; $index < 32; ++$index) {
            $files[sprintf('src/f%02d.ts', $index)] = 'typescript';
        }
        $files['src/f20Huge.js'] = 'typescript';
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_frame_too_large_for_huge', tightCap: true),
            $this->descriptorFor('typescript', batchSourceBytes: 4_000_000),
        );

        $result = $runner->run($this->planForFiles($files, 100_000), new CancellationToken());

        // The oversized file alone, then everything the worker had answered.
        assertSame([33, 1, 32], $this->recordedBatches());
        assertSame([], $result->workerDiagnostics);
        assertSame(32, $result->parsed);
        assertSame(1, $result->leftOut);
        // Narrowing to the unanswered file is not a reduction of the budget
        // the language settled on, so it is not reported as one.
        assertSame(4_000_000, $result->batchBudgets['knossos.typescript']['source_bytes_used']);
    }

    public function testAnInOrderWorkerHasItsOversizedFileConfirmedInOneRequest(): void
    {
        // The worker answers in order and fails at the first file, so the
        // first unanswered file is the one: tried alone, it is confirmed at
        // once, and the rest goes on in two halves.
        $this->allocateRecordPath();
        $files = ['src/a00Huge.js' => 'typescript'];
        for ($index = 1; $index < 32; ++$index) {
            $files[sprintf('src/f%02d.ts', $index)] = 'typescript';
        }
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_frame_too_large_in_order', tightCap: true),
            $this->descriptorFor('typescript', batchSourceBytes: 4_000_000),
        );

        $result = $runner->run($this->planForFiles($files, 1_000), new CancellationToken());

        assertSame([32, 1, 15, 15, 1], $this->recordedBatches());
        assertSame([], $result->workerDiagnostics);
        assertSame(31, $result->parsed);
        assertSame(['src/a00Huge.js'], $result->leftOutPaths);
        assertSame(4_000_000, $result->batchBudgets['knossos.typescript']['source_bytes_used']);
    }

    public function testABlindSearchForOneOversizedFrameFitsTheRetryAllowance(): void
    {
        // This worker fails before answering anything whenever the batch holds
        // the oversized file, so only splitting finds it: a binary search,
        // charged split by split, well inside the allowance for 32 files
        // (4 + 2 * 5 = 14).
        $this->allocateRecordPath();
        $files = [];
        for ($index = 0; $index < 32; ++$index) {
            $files[$index === 20 ? 'src/f20Huge.js' : sprintf('src/f%02d.ts', $index)] = 'typescript';
        }
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_frame_too_large_blind', tightCap: true),
            $this->descriptorFor('typescript', batchSourceBytes: 4_000_000),
        );

        $result = $runner->run($this->planForFiles($files, 1_000), new CancellationToken());

        assertSame([], $result->workerDiagnostics);
        assertSame(['src/f20Huge.js'], $result->leftOutPaths);
        assertSame(31, $result->parsed);
        assertSame(true, count($this->recordedBatches()) <= 2 * 32 - 1, (string) count($this->recordedBatches()));
        assertSame(4_000_000, $result->batchBudgets['knossos.typescript']['source_bytes_used']);
    }

    public function testAFewOversizedFilesAmongHundredsSurviveAnInOrderWorker(): void
    {
        // A TypeScript worker answers in request order, so after a failure the
        // first unanswered file is the one whose frame was too large. Sending
        // it alone confirms it in one request. Halving instead put it at the
        // front of a half that then failed before answering anything, a blind
        // search per file, and six oversized files among 600 spent the
        // language's whole allowance and degraded it.
        $this->allocateRecordPath();
        $files = [];
        $huge = [];
        for ($index = 0; $index < 600; ++$index) {
            $path = $index % 100 === 37 ? sprintf('src/f%03dHuge.js', $index) : sprintf('src/f%03d.ts', $index);
            $files[$path] = 'typescript';
            if (str_contains($path, 'Huge')) {
                $huge[] = $path;
            }
        }
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_frame_too_large_in_order', tightCap: true),
            $this->descriptorFor('typescript', batchSourceBytes: 4_000_000),
        );

        $result = $runner->run($this->planForFiles($files, 1_000), new CancellationToken());

        assertSame([], $result->workerDiagnostics);
        assertSame(6, count($huge));
        assertSame($huge, array_values(array_intersect($huge, $result->leftOutPaths)));
        assertSame(6, $result->leftOut);
        assertSame(594, $result->parsed);
        // 2n - b, with b the batches 600 files make at the packaged file cap
        // (their 600 KB is well under the byte budget).
        $batches = (int) ceil(600 / WorkerExecutionPolicy::SCAN_BATCH_FILES);
        assertSame(true, count($this->recordedBatches()) <= 2 * 600 - $batches, (string) count($this->recordedBatches()));
    }

    public function testManyBatchesThatEachOverflowOnceAllFinish(): void
    {
        // An ordinary retry is bounded per batch, never by a pool shared
        // across the language: 40 batches that each overflow once are 40
        // retries, which a language-wide allowance growing with log n cut
        // short, degrading a scan that used to finish.
        $this->allocateRecordPath();
        $files = [];
        for ($index = 0; $index < 160; ++$index) {
            $files[sprintf('src/Big%03d.php', $index)] = 'php';
        }
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_overflow', threshold: 2, tightCap: true),
            $this->descriptorFor('php', batchSourceBytes: 400_000),
        );

        $result = $runner->run($this->planForFiles($files, 100_000), new CancellationToken());

        assertSame([], $result->workerDiagnostics);
        assertSame(160, $result->parsed);
        assertSame(120, count($this->recordedBatches()));
    }

    public function testAWorkerThatAnswersOneFileBeforeEachFailureIsNotSearchedForFree(): void
    {
        // A broken worker answers one file of every batch and then fails,
        // whatever the files are. A split that confirms one file is not
        // progress enough to be free. Such splits grow linearly with the
        // files, and the allowance only with their logarithm, so on 128 files
        // the allowance (4 + 2 * 7 = 18) ends it, inside the tree bound, and
        // says what is likely wrong.
        $this->allocateRecordPath();
        $files = [];
        for ($index = 0; $index < 128; ++$index) {
            $files[sprintf('src/s%03d.ts', $index)] = 'typescript';
        }
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_frame_too_large_after_one', tightCap: true),
            $this->descriptorFor('typescript', batchSourceBytes: 4_000_000),
        );

        $result = $runner->run($this->planForFiles($files, 1_000), new CancellationToken());

        assertSame(true, count($this->recordedBatches()) <= 2 * 128 - 1, (string) count($this->recordedBatches()));
        assertSame('WORKER_FRAME_TOO_LARGE', $result->workerDiagnostics[0]['code']);
        $message = $result->workerDiagnostics[0]['message'];
        assertContains('after 18 frame-search retries, the most a language of 128 files to scan is allowed', $message);
        assertContains('suggests the limit is set too low for this project', $message);
    }

    public function testTheRequestCapStopsALanguageAndNamesIt(): void
    {
        // No retry path may grow past linear in the files scanned. The cap is
        // set low here so a path that stays within every other bound meets it.
        $this->allocateRecordPath();
        $pool = $this->createStub(LanguageWorkerPool::class);
        $factory = fn(): ProcessScannerClient => $this->workerClient('per_file_overflow', threshold: 2, tightCap: true);
        $pool->method('client')->willReturnCallback($factory);
        $pool->method('restart')->willReturnCallback($factory);
        $runner = new LanguageScanRunner([$this->descriptorFor('php', batchSourceBytes: 400_000)], $pool, new ContributionCacheService(), maxRequestsPerLanguage: 4);

        $result = $runner->run($this->planForFiles($this->eightBigPhpFiles(), 100_000), new CancellationToken());

        assertSame([4, 2, 2, 4], $this->recordedBatches());
        assertSame('WORKER_REQUEST_CAP', $result->workerDiagnostics[0]['code']);
        assertContains('after 4 requests, the cap for 8 files in 2 batches', $result->workerDiagnostics[0]['message']);
        assertContains('Worker output exceeds the 200000-byte request limit', $result->workerDiagnostics[0]['message']);
    }

    public function testTheDefaultRequestCapIsTwoPerFilePlusFourPerBatch(): void
    {
        assertSame(2 * 160 + 4 * 40, ScanBatchQueue::requestCap(160, 40));
    }

    public function testAFrameAfterEveryFileWasAnsweredLeavesNoFileOut(): void
    {
        // The worker answered the one file it was sent and then wrote an
        // oversized frame. That frame is not the file's answer, so leaving
        // the file out would cache an innocent file as too large.
        $this->allocateRecordPath();
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_frame_too_large_after_all', tightCap: true),
            $this->descriptorFor('typescript', batchSourceBytes: 400_000),
        );

        $result = $runner->run($this->planForFiles(['src/a.ts' => 'typescript'], 100_000), new CancellationToken());

        assertSame([1], $this->recordedBatches());
        assertSame(0, $result->leftOut);
        assertSame('WORKER_FRAME_TOO_LARGE', $result->workerDiagnostics[0]['code']);
        assertContains(
            'The oversized frame belongs to no file (the worker had already answered every file in the batch), so no file is left out over it.',
            $result->workerDiagnostics[0]['message'],
        );
    }

    public function testAWorkerThatFailsEveryFrameBeforeAnsweringIsNotSearchedFileByFile(): void
    {
        // A line limit set far too low fails every request before any file is
        // answered. Splitting that without charge searched all 64 files one by
        // one; on a 2,000-file batch it meant about 4,000 requests and worker
        // restarts. Splits that answered nothing are charged, and the
        // language's allowance ends the search.
        $this->allocateRecordPath();
        $files = [];
        for ($index = 0; $index < 64; ++$index) {
            $files[sprintf('src/f%02d.ts', $index)] = 'typescript';
        }
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_frame_too_large_always', tightCap: true),
            $this->descriptorFor('typescript', batchSourceBytes: 4_000_000),
        );

        $result = $runner->run($this->planForFiles($files, 1_000), new CancellationToken());

        assertSame(1, count($result->workerDiagnostics));
        assertSame('WORKER_FRAME_TOO_LARGE', $result->workerDiagnostics[0]['code']);
        // The allowance for 64 files is 4 + 2 * 6 = 16 charged retries, and a
        // left-out request is not charged: fewer requests than files, against
        // the 127 of a full search.
        assertSame(true, count($this->recordedBatches()) < 64, (string) count($this->recordedBatches()));
        assertContains('after 16 frame-search retries', $result->workerDiagnostics[0]['message']);
    }

    public function testAnOversizedFrameThatBelongsToNoFileDegradesInsteadOfLeavingAFileOut(): void
    {
        // Every file was answered and the final response was too large. No
        // file's answer caused that, so splitting would only end by leaving
        // an innocent file out and caching it there.
        $this->allocateRecordPath();
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_frame_too_large_result', tightCap: true),
            $this->descriptorFor('typescript', batchSourceBytes: 400_000),
        );
        $files = ['src/a.ts' => 'typescript', 'src/b.ts' => 'typescript', 'src/c.ts' => 'typescript', 'src/d.ts' => 'typescript'];

        $result = $runner->run($this->planForFiles($files, 100_000), new CancellationToken());

        assertSame([4], $this->recordedBatches());
        assertSame(0, $result->leftOut);
        assertSame('WORKER_FRAME_TOO_LARGE', $result->workerDiagnostics[0]['code']);
        assertContains(
            "The oversized frame belongs to no file (it was the worker's response to the request), so no file is left out over it.",
            $result->workerDiagnostics[0]['message'],
        );
    }

    public function testARetryAfterAKillIsSmallerEvenWhenTheBatchWasUnderItsBudget(): void
    {
        // Halving the budget of a batch that only used a tenth of it re-sent
        // the same batch, while the diagnostic claimed smaller ones were tried.
        $this->allocateRecordPath();
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_sigterm_once', threshold: 2),
            $this->descriptorFor('typescript', batchSourceBytes: 4_000_000),
        );
        $files = ['src/a.ts' => 'typescript', 'src/b.ts' => 'typescript', 'src/c.ts' => 'typescript', 'src/d.ts' => 'typescript'];

        $result = $runner->run($this->planForFiles($files, 100_000), new CancellationToken());

        assertSame([4, 2, 2], $this->recordedBatches());
        assertSame(4, $result->parsed);
        assertSame(200_000, $result->batchBudgets['knossos.typescript']['source_bytes_used']);
    }

    public function testAWorkerThatCrashesWithASignalIsNotRetriedOrBlamedOnTheHost(): void
    {
        // A parser crash ends in SIGSEGV or SIGABRT. Splitting the batch does
        // not stop a crash, and the host's memory guard did not send it.
        $this->allocateRecordPath();
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_segv', threshold: 0),
            $this->descriptorFor('typescript', batchSourceBytes: 400_000),
        );
        $files = array_fill_keys(array_keys($this->eightBigPhpFiles()), 'typescript');

        $result = $runner->run($this->planForFiles($files, 100_000), new CancellationToken());

        assertSame([4], $this->recordedBatches());
        assertSame('WORKER_EXITED', $result->workerDiagnostics[0]['code']);
        $message = $result->workerDiagnostics[0]['message'];
        assertContains('crashed with signal 11 (SIGSEGV)', $message);
        assertSame(false, str_contains($message, 'earlyoom'), $message);
        assertSame(false, str_contains($message, 'did not send'), $message);
    }

    public function testARequestTooLargeForOneFileDegradesRatherThanLeavingTheFileOut(): void
    {
        // The request carries everything the batch shares, so one file's
        // request being too large says nothing about that file.
        $this->allocateRecordPath();
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file', tightCap: true),
            $this->descriptorFor('typescript', batchSourceBytes: 400_000),
        );
        $long = 'src/' . str_repeat('a', 250_000) . '.ts';

        $result = $runner->run($this->planForFiles([$long => 'typescript'], 100_000), new CancellationToken());

        assertSame([], $this->recordedBatches());
        assertSame(0, $result->leftOut);
        assertSame('WORKER_REQUEST_TOO_LARGE', $result->workerDiagnostics[0]['code']);
        $message = $result->workerDiagnostics[0]['message'];
        assertContains('200000-byte request frame limit (the larger of worker_execution.max_line_bytes and worker_execution.max_output_bytes)', $message);
        // One file is as small as a batch gets.
        assertContains('The batch held a single file, so a smaller batch cannot help.', $message);
        assertSame(false, str_contains($message, 'may still fit'), $message);
    }

    public function testAReducedBudgetDoesNotPinTheRestOfTheLanguage(): void
    {
        // The reduction must not outlive the batch that caused it. This worker
        // overflows only its first oversized request, so if the budget stayed
        // pinned every later request would carry 2 files; the trailing 4s prove
        // the descriptor's full budget came back.
        $this->allocateRecordPath();
        $files = [];
        for ($index = 0; $index < 12; ++$index) {
            $files[sprintf('src/Big%02d.php', $index)] = 'php';
        }
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_overflow_once', threshold: 2, tightCap: true),
            $this->descriptorFor('php', batchSourceBytes: 400_000),
        );

        $result = $runner->run($this->planForFiles($files, 100_000), new CancellationToken());

        assertSame([4, 2, 2, 4, 4], $this->recordedBatches());
        assertSame([], $result->workerDiagnostics);
        assertSame(12, $result->parsed);
    }

    public function testAFileThatOverflowsAloneIsNotRetriedAtAll(): void
    {
        // batches() always emits at least one file per request, so a file that
        // overflows by itself cannot be split any further. Retrying it would
        // burn every remaining attempt and a worker restart each time, so it
        // is left out at once, and the language keeps the rest of its facts.
        $this->allocateRecordPath();
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_overflow', threshold: 0, tightCap: true),
            $this->descriptorFor('php', batchSourceBytes: 400_000),
        );

        $result = $runner->run($this->planForFiles(['src/Huge.php' => 'php'], 900_000), new CancellationToken());

        assertSame([1], $this->recordedBatches());
        assertSame([], $result->workerDiagnostics);
        assertSame(1, count($result->contributions));
        assertSame('WORKER_OUTPUT_LIMIT', $result->contributions[0]->diagnostics[0]->code);
        // Never halved, because it was never retried.
        assertSame(400_000, $result->batchBudgets['knossos.php']['source_bytes_used']);
    }

    public function testHalvingIsBoundedAndThenDegradesTheLanguage(): void
    {
        // A worker that refuses every request must not be retried forever. After
        // the bounded halvings the failure falls through to the per-language
        // degrade path exactly as any other worker fault does.
        //
        // 64 files of 1 KB against a 64 KB budget stay splittable through all
        // four halvings (64 -> 32 -> 16 -> 8 -> 4 files), so it is the bound
        // that stops this and not the single-file guard.
        $this->allocateRecordPath();
        $files = [];
        for ($index = 0; $index < 64; ++$index) {
            $files[sprintf('src/Small%02d.php', $index)] = 'php';
        }
        $descriptor = $this->descriptorFor('php', batchSourceBytes: 64_000);
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_overflow', threshold: 0, tightCap: true),
            $descriptor,
        );

        $result = $runner->run($this->planForFiles($files, 1_000), new CancellationToken());

        // The initial attempt plus MAX_SCAN_BATCH_HALVINGS retries, then stop.
        assertSame([64, 32, 16, 8, 4], $this->recordedBatches());
        assertSame(1 + WorkerExecutionPolicy::MAX_SCAN_BATCH_HALVINGS, count($this->recordedBatches()));
        assertSame(1, count($result->workerDiagnostics));
        assertSame('WORKER_OUTPUT_LIMIT', $result->workerDiagnostics[0]['code']);
        assertSame('knossos.php', $result->workerDiagnostics[0]['owner']);
        // Reported even though the language failed: how far the budget fell is
        // exactly what an operator needs to see here.
        assertSame(4_000, $result->batchBudgets['knossos.php']['source_bytes_used']);
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
        assertSame(400_000, $result->batchBudgets['knossos.php']['source_bytes_used']);
    }

    public function testBatchBudgetsAreReportedForALanguageThatNeededNoRetry(): void
    {
        $result = $this->runnerWithClients(['php' => $this->recordingClient()])
            ->run($this->planForFiles($this->nineHundredPhpFiles()), new CancellationToken());

        assertSame(
            ['files' => 400, 'source_bytes' => 4_000_000, 'source_bytes_used' => 4_000_000],
            $result->batchBudgets['knossos.php'],
        );
    }

    public function testBatchBudgetsAreReportedForALanguageWithNothingToScan(): void
    {
        // A stable shape is easier to consume than one whose keys depend on what
        // the project happened to contain, and the configured bounds are still
        // the answer to "what would this language have run under?".
        $runner = $this->runnerWithClients(
            ['php' => $this->recordingClient()],
            [$this->descriptorFor('php'), $this->descriptorFor('typescript', batchFiles: 2_000)],
        );

        $result = $runner->run($this->planForFiles(['src/Foo.php' => 'php']), new CancellationToken());

        assertSame(2_000, $result->batchBudgets['knossos.typescript']['files']);
        assertSame(
            $result->batchBudgets['knossos.typescript']['source_bytes'],
            $result->batchBudgets['knossos.typescript']['source_bytes_used'],
        );
    }

    /**
     * Each language's worker is told the limits, and the frameworks and config
     * files that are its own and nobody else's.
     *
     * The units are listed with each language's own one after a unit it must
     * not see, so a config list that kept its filtered keys would reach the
     * worker as an object rather than a list.
     */
    public function testEachWorkerIsSentItsLimitsFrameworksAndOwnConfigFiles(): void
    {
        $this->allocateRecordPath();
        $keys = ['php', 'typescript', 'python', 'rust'];
        $clients = array_combine($keys, array_map(fn(): ProcessScannerClient => $this->workerClient('per_file_request'), $keys));
        $base = $this->makePreparationWithFiles(
            [
                $this->fileFixture('src/A.php', 'php'),
                $this->fileFixture('web/a.ts', 'typescript'),
                $this->fileFixture('py/a.py', 'python'),
                $this->fileFixture('rs/a.rs', 'rust'),
            ],
            ['php' => 'cfg-php', 'typescript' => 'cfg-ts', 'python' => 'cfg-py', 'rust' => 'cfg-rs'],
        );
        $preparation = new ScanPreparation(
            configuration: $base->configuration,
            discovery: new DiscoveryResult(
                rootRealpath: '/tmp/foo',
                files: $base->discovery->files,
                units: [
                    new ProjectUnit('composer', 'composer.json', 'h1'),
                    new ProjectUnit('typescript', 'web/tsconfig.json', 'h2'),
                    new ProjectUnit('cargo', 'rs/Cargo.toml', 'h3'),
                    new ProjectUnit('node', 'package.json', 'h4', ['typescript_range' => '^5.4.0']),
                    new ProjectUnit('node', 'web/package.json', 'h5', ['typescript_range' => '~6.0', 'vue' => true]),
                    new ProjectUnit('node', 'docs/package.json', 'h6', ['typescript_range' => 'latest']),
                    new ProjectUnit('node', 'tools/package.json', 'h7', []),
                ],
                diagnostics: [],
                inputHash: '',
                configurationHash: '',
            ),
            maxFiles: 7,
            maxFileBytes: 9_000,
            explicitBoundaries: [],
            requestedMode: 'fast',
            snapshotRetention: 0,
            executionPolicy: new WorkerExecutionPolicy(),
            laravel: true,
            symfony: false,
            configurationHashes: $base->configurationHashes,
            configurationMilliseconds: 0.0,
            discoveryMilliseconds: 0.0,
            planningMilliseconds: 0.0,
            pythonFrameworks: ['django'],
            rustFrameworks: ['axum'],
        );

        $this->runnerWithClients($clients)->run(
            new ScanPlan(preparation: $preparation, projectId: 'plan-requests', effectiveMode: 'fast', cacheByScannerPath: [], deletedFiles: 0),
            new CancellationToken(),
        );

        $limits = ['root' => '/tmp/foo', 'limits' => ['max_files' => 7, 'max_file_bytes' => 9_000]];
        // What discovery leaves out, for the workers that resolve imports
        // through the tree.
        $exclusions = ['exclusions' => (new IgnoreMatcher($base->configuration->ignores))->workerRules()];
        assertSame(
            [
                [...$limits, 'frameworks' => ['laravel']],
                // Each manifest's declared TypeScript major, keyed by its
                // directory; a range naming no version tells the worker nothing.
                // And the directories whose manifest depends on Vue.
                // And every file of the language, so a program for files no
                // config includes holds the same files whichever are requested.
                [...$limits, ...$exclusions, 'config_files' => ['web/tsconfig.json'], 'typescript_versions' => ['' => 5, 'web' => 6], 'vue_projects' => ['web'], 'package_directories' => ['', 'docs', 'tools', 'web'], 'source_files' => ['web/a.ts']],
                [...$limits, ...$exclusions, 'frameworks' => ['django']],
                [...$limits, 'frameworks' => ['axum'], 'config_files' => ['rs/Cargo.toml']],
            ],
            array_map(
                static fn(string $line): mixed => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                array_values(array_filter(explode("\n", (string) file_get_contents($this->recordPath . '.request')))),
            ),
        );
    }

    /**
     * Every TypeScript request names all of the language's files, whichever
     * batch it is and whichever files it scans.
     *
     * A file no tsconfig includes is read in a program of its neighbours,
     * which must hold the same files whichever of them a request names: an
     * ambient `declare module` in a `.d.ts` satisfies an import only when
     * that file is in the importer's program, and a batch of one file, or an
     * incremental scan of the importer alone, left the declaration out.
     */
    public function testEveryTypescriptRequestNamesTheLanguagesFiles(): void
    {
        $this->allocateRecordPath();
        $runner = $this->runnerWithClients(
            ['typescript' => $this->workerClient('per_file_request')],
            [$this->descriptorFor('typescript', batchFiles: 1)],
        );

        $runner->run(
            $this->planForFiles([
                'hooks/register.tsx' => 'typescript',
                'hooks/engine.d.ts' => 'typescript',
                'src/types.d.mts' => 'typescript',
                'src/a.ts' => 'typescript',
            ]),
            new CancellationToken(),
        );

        $requests = array_map(
            static fn(string $line): mixed => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) file_get_contents($this->recordPath . '.request')))),
        );
        assertSame(4, count($requests));
        foreach ($requests as $request) {
            assertSame(['hooks/engine.d.ts', 'hooks/register.tsx', 'src/a.ts', 'src/types.d.mts'], $request['source_files']);
        }
    }

    /**
     * A batch closes exactly when the next file would take it past the byte
     * budget, and a file with no size adds nothing to the total.
     *
     * The sizes put every comparison on its boundary: the first batch reaches
     * 300,001 only through its sizeless file counting zero, the second reaches
     * exactly 300,000 and holds, and the third ends on a sizeless file at
     * exactly the budget.
     */
    public function testABatchClosesExactlyWhereTheByteBudgetWouldBeExceeded(): void
    {
        $sizes = [150_000, 0, 150_001, 149_999, 1, 299_999, 0];
        $files = [];
        foreach ($sizes as $index => $size) {
            $files[] = $this->fileFixture(sprintf('src/F%d.php', $index), 'php', $size);
        }
        $runner = $this->runnerWithClients(
            ['php' => $this->recordingClient()],
            [$this->descriptorFor('php', batchSourceBytes: 300_000)],
        );

        $runner->run(
            new ScanPlan(
                preparation: $this->makePreparationWithFiles($files, ['php' => 'cfg-php', 'typescript' => 'cfg-ts', 'python' => 'cfg-py']),
                projectId: 'plan-boundaries',
                effectiveMode: 'fast',
                cacheByScannerPath: [],
                deletedFiles: 0,
            ),
            new CancellationToken(),
        );

        assertSame([2, 2, 3], $this->recordedBatches());
    }

    /**
     * Halving never takes the reported budget below one byte, the smallest
     * budget that still sends a file. From 3 it goes to 1 and stays there.
     */
    public function testAHalvedBudgetStopsAtOneByte(): void
    {
        $this->allocateRecordPath();
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_overflow', threshold: 0, tightCap: true),
            $this->descriptorFor('php', batchSourceBytes: 3),
        );

        $result = $runner->run(
            $this->planForFiles(['src/A.php' => 'php', 'src/B.php' => 'php', 'src/C.php' => 'php', 'src/D.php' => 'php']),
            new CancellationToken(),
        );

        // Halving by file count reaches single files, each of which is then
        // left out on its own; the byte budget bottoms out at 1 on the way.
        assertSame([4, 2, 1, 1, 2, 1, 1], $this->recordedBatches());
        assertSame(1, $result->batchBudgets['knossos.php']['source_bytes_used']);
        // Every file left out is not four oversized files: it is a limit set
        // too low or a broken worker, and the language says so loudly.
        assertSame(0, $result->leftOut);
        assertSame('WORKER_EVERY_FILE_LEFT_OUT', $result->workerDiagnostics[0]['code']);
        assertSame(
            'php scanner failed: Every one of the 4 files was left out because its own answer outgrew a size limit. '
            . 'That points to a limit set too low or a broken worker rather than 4 oversized files, so the language is '
            . 'reported as failed.',
            $result->workerDiagnostics[0]['message'],
        );
    }

    /**
     * The guard judges the files sent in this scan. Two files left out once
     * and reused from the cache say nothing about a limit set too low now:
     * a rescan that sends nothing must not fail the language again.
     */
    public function testReusedLeftOutFilesDoNotTripTheEveryFileLeftOutGuard(): void
    {
        $this->allocateRecordPath();
        $runner = $this->runnerWithWorkerFactory(
            fn(): ProcessScannerClient => $this->workerClient('per_file_frame_too_large_for_huge', tightCap: true),
            $this->descriptorFor('typescript', batchSourceBytes: 400_000),
        );
        $first = $runner->run(
            $this->planForFiles(['src/a.ts' => 'typescript', 'src/Huge.js' => 'typescript', 'src/Huge2.js' => 'typescript'], 100_000),
            new CancellationToken(),
        );
        $cache = [];
        foreach ($first->cacheEntries as $entry) {
            if (!str_contains($entry->filePath, 'Huge')) {
                continue;
            }
            $cache[$entry->scannerId . "\0" . $entry->filePath] = [
                'content_hash' => $entry->contentHash,
                'scanner_version' => $entry->scannerVersion,
                'configuration_hash' => $entry->configurationHash,
                'payload_json' => json_encode($entry->contribution, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ];
        }
        assertSame(2, count($cache));
        $requestsBefore = count($this->recordedBatches());

        $plan = $this->planForFiles(['src/Huge.js' => 'typescript', 'src/Huge2.js' => 'typescript'], 100_000);
        $second = $runner->run(
            new ScanPlan($plan->preparation, $plan->projectId, 'incremental', $cache, 0),
            new CancellationToken(),
        );

        assertSame($requestsBefore, count($this->recordedBatches()), 'Nothing was sent: both files were reused.');
        assertSame([], $second->workerDiagnostics);
        assertSame(2, $second->leftOut);
    }

    /** Every language's new and changed files are counted, not only the last language's. */
    public function testAddedAndChangedFilesAreSummedAcrossLanguages(): void
    {
        $this->allocateRecordPath();
        $stale = static fn(string $path): array => ["knossos.fake\0" . $path => [
            'content_hash' => 'stale', 'scanner_version' => '0.1.0', 'configuration_hash' => 'cfg', 'payload_json' => '{}',
        ]];
        $runner = $this->runnerWithClients(['php' => $this->workerClient('per_file'), 'typescript' => $this->workerClient('per_file')]);

        $result = $runner->run(
            new ScanPlan(
                preparation: $this->makePreparationWithFiles(
                    [
                        $this->fileFixture('src/A.php', 'php'),
                        $this->fileFixture('src/B.php', 'php'),
                        $this->fileFixture('web/a.ts', 'typescript'),
                        $this->fileFixture('web/b.ts', 'typescript'),
                    ],
                    ['php' => 'cfg', 'typescript' => 'cfg', 'python' => 'cfg'],
                ),
                projectId: 'plan-counts',
                effectiveMode: 'fast',
                cacheByScannerPath: [...$stale('src/B.php'), ...$stale('web/b.ts')],
                deletedFiles: 0,
            ),
            new CancellationToken(),
        );

        assertSame(2, $result->added, 'One uncached file per language.');
        assertSame(2, $result->changed, 'One stale cache entry per language.');
        // A stage timing is a duration: never negative, and nowhere near a minute here.
        $milliseconds = $result->stageMilliseconds['php-analysis'];
        assertSame(true, $milliseconds >= 0.0 && $milliseconds < 60_000.0, sprintf('php-analysis took %s ms.', $milliseconds));
    }

    /** A failed language's worker is shut down, so a hung process cannot outlive the scan that gave up on it. */
    public function testAFailedLanguageShutsThePoolDown(): void
    {
        $pool = $this->createMock(LanguageWorkerPool::class);
        $pool->method('client')->willThrowException(new WorkerException('WORKER_TIMEOUT', 'Scanner worker request timed out.'));
        $pool->expects($this->once())->method('shutdown');

        $result = (new LanguageScanRunner([$this->phpDescriptor()], $pool, new ContributionCacheService()))
            ->run($this->planWithOneFile(), new CancellationToken());

        assertSame('WORKER_TIMEOUT', $result->workerDiagnostics[0]['code']);
    }
}
