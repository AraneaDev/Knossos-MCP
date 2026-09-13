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
 * The hashes of files a worker read for another file's sake, checked end to end
 * through a real worker process.
 *
 * `src/Other.ts` is discovered but claimed by no descriptor in this test, so it
 * is never requested: the worker only reads it, the way Python's module index
 * or the TypeScript checker reads files to resolve the ones it was asked for.
 * Its own per-file hash therefore never reaches the core, and the #79 re-read
 * sees it restored, so only `input_hashes` can catch a change around that read.
 */
#[Group('scan')]
final class InputHashVerificationTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/src/Checkout.ts', "import { other } from './Other';\nexport class Checkout {}\n");
        file_put_contents($this->root . '/src/Other.ts', "export const other = 1;\n");
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testAnotherFileChangedAndRestoredAroundTheWorkersReadFailsTheScan(): void
    {
        $other = $this->other();
        $original = (string) file_get_contents($other->absolutePath);

        $error = captureThrows(fn(): LanguageScanResult => $this->runScan('inputs_swap'), ScanSnapshotChangedException::class);

        assertContains('src/Other.ts', $error->getMessage());
        assertContains('was read from different content than the scan hashed', $error->getMessage());
        // Both files are back to what discovery hashed, and Checkout.ts was
        // parsed honestly, so neither #79's re-read nor the per-file hash sees it.
        assertSame($original, (string) file_get_contents($other->absolutePath));
        (new ScanSnapshotValidator())->validate([$this->checkout(), $other]);
    }

    public function testHonestInputHashesScanAndStayOutOfTheScannerMetadata(): void
    {
        $result = $this->runScan('inputs_honest');

        assertSame([], $result->workerDiagnostics);
        assertSame(['src/Checkout.ts'], array_map(static fn($contribution): string => $contribution->nodes[0]->evidence->relativePath, $result->contributions));
        assertSame(['src/Checkout.ts'], array_map(static fn($entry): string => $entry->filePath, $result->cacheEntries));
        assertSame(['count' => 1], $result->scannerMetadata['knossos.fake']);
    }

    public function testADeclaringWorkerThatSendsNoInputHashesDegradesThatLanguage(): void
    {
        $result = $this->runScan('inputs_missing');

        assertSame([], $result->contributions);
        assertSame('WORKER_RESPONSE_INVALID', $result->workerDiagnostics[0]['code']);
    }

    public function testAnotherFileTheWorkerCouldNotReadFailsTheScan(): void
    {
        $error = captureThrows(fn(): LanguageScanResult => $this->runScan('inputs_unreadable'), ScanSnapshotChangedException::class);

        assertContains('src/Other.ts', $error->getMessage());
        assertContains('could not be read while the scan resolved other files', $error->getMessage());
    }

    public function testAReadOutsideTheDiscoveredTreeIsIgnored(): void
    {
        $result = $this->runScan('inputs_outside');

        assertSame([], $result->workerDiagnostics);
        assertSame(1, count($result->contributions));
    }

    public function testAWorkerWithoutTheCapabilityScansExactlyAsBefore(): void
    {
        $result = $this->runScan('per_file');

        assertSame([], $result->workerDiagnostics);
        assertSame(1, count($result->cacheEntries));
        assertSame(1, $result->scannerMetadata['knossos.fake']['files_scanned']);
    }

    private function checkout(): DiscoveredFile
    {
        return $this->discovered('src/Checkout.ts', 'typescript');
    }

    private function other(): DiscoveredFile
    {
        // A language the test's descriptor does not claim: discovered, never requested.
        return $this->discovered('src/Other.ts', 'javascript');
    }

    private function discovered(string $relativePath, string $language): DiscoveredFile
    {
        $absolute = $this->root . '/' . $relativePath;
        $contents = (string) file_get_contents($absolute);

        return new DiscoveredFile($relativePath, $absolute, $language, strlen($contents), (int) filemtime($absolute), hash('sha256', $contents));
    }

    private function runScan(string $mode): LanguageScanResult
    {
        $client = new ProcessScannerClient([PHP_BINARY, self::repositoryRoot() . '/tests/Fixtures/workers/fake-worker.php', $mode]);
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willReturn($client);
        $descriptor = new LanguageDescriptor(key: 'typescript', stage: 'typescript-analysis', languages: ['typescript'], command: ['php', '-r', 'echo 1']);
        $runner = new LanguageScanRunner([$descriptor], $pool, new ContributionCacheService());

        try {
            return $runner->run($this->plan(), new CancellationToken());
        } finally {
            $client->shutdown();
        }
    }

    private function plan(): ScanPlan
    {
        return new ScanPlan(
            preparation: new ScanPreparation(
                configuration: new ProjectConfiguration(),
                discovery: new DiscoveryResult(rootRealpath: $this->root, files: [$this->checkout(), $this->other()], units: [], diagnostics: [], inputHash: '', configurationHash: ''),
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
            projectId: 'input-hashes',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
    }
}
