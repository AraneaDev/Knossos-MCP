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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('scan-runner')]
final class LanguageScanRunnerTest extends TestCase
{
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
     */
    private function fileFixture(string $relativePath, string $language): \stdClass
    {
        $file = new \stdClass();
        $file->language = $language;
        $file->relativePath = $relativePath;
        $file->contentHash = 'hash-' . $relativePath;

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

    public function testOneFailingWorkerDoesNotDiscardAnotherLanguagesFacts(): void
    {
        // PHP succeeds, TypeScript throws. The PHP graph must survive and the
        // TypeScript failure must arrive as a diagnostic, not an exception.
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
        $runner = new LanguageScanRunner(
            [$this->phpDescriptor(), $this->typescriptDescriptor()],
            $pool,
            new ContributionCacheService(),
        );

        $result = $runner->run(
            $this->planWithCachedPhpFile([
                $this->fileFixture('src/Foo.php', 'php'),
                $this->fileFixture('src/a.ts', 'typescript'),
            ]),
            new CancellationToken(),
        );

        assertSame(1, count($result->contributions));
        assertSame(1, count($result->manifests));
        assertSame(1, $result->unchanged);
        assertSame(1, count($result->workerDiagnostics));
        assertSame('WORKER_TIMEOUT', $result->workerDiagnostics[0]['code']);
        assertSame('knossos.typescript', $result->workerDiagnostics[0]['owner']);
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
}
