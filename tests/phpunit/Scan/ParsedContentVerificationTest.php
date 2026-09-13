<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ContributionCacheService;
use Knossos\Scan\LanguageDescriptor;
use Knossos\Scan\LanguageScanResult;
use Knossos\Scan\LanguageScanRunner;
use Knossos\Scan\LanguageWorkerPool;
use Knossos\Scan\ScanPlan;
use Knossos\Scan\ScanPreparation;
use Knossos\Scan\ScanSnapshotChangedException;
use Knossos\Scan\ScanSnapshotValidator;
use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The worker's own hash of what it parsed, checked end to end through a real
 * worker process.
 *
 * The central test makes the fake worker rewrite the file, read it, and put it
 * back, all inside its own scan request. Discovery's hash and the file on disk
 * agree before and after, which is exactly the case #79's re-read passes, so
 * only the reported hash can catch it.
 */
#[Group('scan')]
final class ParsedContentVerificationTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/src/Checkout.ts', "export class Checkout {}\n");
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testAFileChangedAndRestoredAroundTheWorkersReadFailsTheScan(): void
    {
        $file = $this->discovered();
        $original = (string) file_get_contents($file->absolutePath);

        $error = captureThrows(fn(): LanguageScanResult => $this->runScan('hash_swap', $file), ScanSnapshotChangedException::class);

        assertContains('src/Checkout.ts', $error->getMessage());
        assertContains('was parsed from different content', $error->getMessage());
        // The file is back to what discovery hashed, so the post-worker re-read
        // alone would have passed. This is what makes the test worth having.
        assertSame($original, (string) file_get_contents($file->absolutePath));
        (new ScanSnapshotValidator())->validate([$file]);
    }

    public function testAnHonestHashScansAndIsCached(): void
    {
        $file = $this->discovered();

        $result = $this->runScan('hash_honest', $file);

        assertSame([], $result->workerDiagnostics);
        assertSame($file->contentHash, $result->contributions[0]->contentHash);
        assertSame(1, count($result->cacheEntries));
    }

    public function testFactsWithoutAHashFromADeclaringWorkerDegradeOnlyThatLanguage(): void
    {
        $result = $this->runScan('hash_missing', $this->discovered());

        assertSame([], $result->contributions);
        assertSame('WORKER_CONTRIBUTION_INVALID', $result->workerDiagnostics[0]['code']);
    }

    public function testAnUnreadableFileUnderTheCapabilityIsReportedButNotCached(): void
    {
        $result = $this->runScan('hash_unreadable', $this->discovered());

        assertSame([], $result->workerDiagnostics);
        assertSame('FAKE_UNSCANNABLE_FILE', $result->contributions[0]->diagnostics[0]->code);
        assertSame([], $result->cacheEntries);
    }

    public function testAWorkerWithoutTheCapabilityScansExactlyAsBefore(): void
    {
        $result = $this->runScan('per_file', $this->discovered());

        assertSame([], $result->workerDiagnostics);
        assertSame(null, $result->contributions[0]->contentHash);
        assertSame(1, count($result->cacheEntries));
    }

    private function discovered(): DiscoveredFile
    {
        $absolute = $this->root . '/src/Checkout.ts';
        $contents = (string) file_get_contents($absolute);

        return new DiscoveredFile('src/Checkout.ts', $absolute, 'typescript', strlen($contents), (int) filemtime($absolute), hash('sha256', $contents));
    }

    private function runScan(string $mode, DiscoveredFile $file): LanguageScanResult
    {
        $client = new ProcessScannerClient([PHP_BINARY, self::repositoryRoot() . '/tests/Fixtures/workers/fake-worker.php', $mode]);
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willReturn($client);
        $descriptor = new LanguageDescriptor(key: 'typescript', stage: 'typescript-analysis', languages: ['typescript'], command: ['php', '-r', 'echo 1']);
        $runner = new LanguageScanRunner([$descriptor], $pool, new ContributionCacheService());

        try {
            return $runner->run($this->plan($file), new CancellationToken());
        } finally {
            $client->shutdown();
        }
    }

    private function plan(DiscoveredFile $file): ScanPlan
    {
        return new ScanPlan(
            preparation: new ScanPreparation(
                configuration: new ProjectConfiguration(),
                discovery: new DiscoveryResult(rootRealpath: $this->root, files: [$file], units: [], diagnostics: [], inputHash: '', configurationHash: ''),
                maxFiles: 100,
                maxFileBytes: 1_000_000,
                explicitBoundaries: [],
                requestedMode: 'fast',
                snapshotRetention: 0,
                executionPolicy: new WorkerExecutionPolicy(),
                laravel: false,
                symfony: false,
                configurationHashes: ['php' => 'cfg-php', 'typescript' => 'cfg-ts', 'python' => 'cfg-py'],
                configurationMilliseconds: 0.0,
                discoveryMilliseconds: 0.0,
                planningMilliseconds: 0.0,
            ),
            projectId: 'parsed-content',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
    }
}
