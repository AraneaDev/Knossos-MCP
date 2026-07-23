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

    private function makePlan(ScanPreparation $preparation = null): ScanPlan
    {
        return new ScanPlan(
            preparation: $preparation ?? $this->makePreparation(),
            projectId: 'plan-default',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
    }

    private function makePreparationWithFiles(array $files): ScanPreparation
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
            configurationHashes: $base->configurationHashes,
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

    public function testGenericWorkerExceptionIsNotTranslatedWhenNotCancelled(): void
    {
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willThrowException(
            new WorkerException('WORKER_EXITED', 'Scanner worker exited unexpectedly.'),
        );
        $runner = new LanguageScanRunner([$this->phpDescriptor()], $pool, new ContributionCacheService());

        $error = captureThrows(
            fn(): LanguageScanResult => $runner->run($this->planWithOneFile(), new CancellationToken()),
            WorkerException::class,
        );

        assertSame('WORKER_EXITED', $error->diagnosticCode);
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
}
