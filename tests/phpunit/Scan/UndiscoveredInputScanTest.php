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
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanPlan;
use Knossos\Scan\ScanPreparation;
use Knossos\Scan\ScanSnapshotChangedException;
use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use Knossos\Scanner\Worker\WorkerLimits;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Worker reads of files discovery never hashed, collected across a scan and
 * re-read just before it commits, end to end through a real worker process.
 *
 * `node_modules/dep/index.d.ts` is ignored by discovery, so no hash of it is
 * recorded and neither the per-request check nor the post-worker re-read of
 * discovered files covers it.
 */
#[Group('scan')]
final class UndiscoveredInputScanTest extends KnossosTestCase
{
    private const DEPENDENCY = 'node_modules/dep/index.d.ts';

    private string $root;

    private string $installation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $this->installation = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        mkdir($this->root . '/node_modules/dep', 0o777, true);
        file_put_contents($this->root . '/src/Checkout.ts', "import { dep } from 'dep';\nexport class Checkout {}\n");
        file_put_contents($this->root . '/src/Other.ts', "export const other = 1;\n");
        file_put_contents($this->root . '/' . self::DEPENDENCY, "export declare const dep: 1;\n");
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        if (is_dir($this->installation)) {
            $this->removeTempTree($this->installation);
        }
        parent::tearDown();
    }

    public function testAnUnchangedUndiscoveredReadIsCollectedAndIsNotAnError(): void
    {
        $result = $this->runScan('inputs_undiscovered_stable');

        assertSame([], $result->workerDiagnostics);
        assertSame([self::DEPENDENCY => hash('sha256', "export declare const dep: 1;\n")], $result->undiscoveredInputs);
    }

    public function testTheSameUndiscoveredPathWithTwoValuesInTwoRequestsFailsTheScan(): void
    {
        $error = captureThrows(fn(): LanguageScanResult => $this->runScan('inputs_undiscovered_per_request', batchFiles: 1), ScanSnapshotChangedException::class);

        assertSame(ScanSnapshotChangedException::inputReadInconsistently(self::DEPENDENCY)->getMessage(), $error->getMessage());
    }

    public function testTheSameUndiscoveredPathWithTwoValuesInTwoLanguagesFailsTheScan(): void
    {
        // One request per language, each naming its own first file, so the
        // conflict only shows once both languages are merged into the scan.
        $error = captureThrows(fn(): LanguageScanResult => $this->runScan('inputs_undiscovered_per_request', otherLanguage: true), ScanSnapshotChangedException::class);

        assertSame(ScanSnapshotChangedException::inputReadInconsistently(self::DEPENDENCY)->getMessage(), $error->getMessage());
    }

    public function testADegradedLanguageContributesNoUndiscoveredReads(): void
    {
        // The worker reports the read and then its language degrades on the
        // missing capability field of its next request, so none of its facts
        // are kept and nothing it read needs to match at commit.
        $result = $this->runScan('inputs_undiscovered_then_missing', batchFiles: 1);

        assertSame('WORKER_RESPONSE_INVALID', $result->workerDiagnostics[0]['code']);
        assertSame([], $result->contributions);
        assertSame([], $result->undiscoveredInputs);
    }

    public function testAnUndiscoveredFileChangedAfterTheWorkerReadItFailsTheScanBeforeAnythingIsWritten(): void
    {
        $pdo = $this->freshTestDatabase();

        $error = captureThrows(fn() => $this->service('inputs_undiscovered_changed', $pdo)->scan($this->root), ScanSnapshotChangedException::class);

        assertSame(ScanSnapshotChangedException::inputChangedAfterRead(self::DEPENDENCY)->getMessage(), $error->getMessage());
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
    }

    public function testATransientFileTheWorkerReadFailsTheScan(): void
    {
        $pdo = $this->freshTestDatabase();

        $error = captureThrows(fn() => $this->service('inputs_undiscovered_transient', $pdo)->scan($this->root), ScanSnapshotChangedException::class);

        assertSame(ScanSnapshotChangedException::inputChangedAfterRead('generated/transient.d.ts')->getMessage(), $error->getMessage());
        assertSame(false, file_exists($this->root . '/generated/transient.d.ts'));
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
    }

    public function testAFileAbsentWhenTheWorkerLookedAndPresentAtCommitFailsTheScan(): void
    {
        $pdo = $this->freshTestDatabase();

        $error = captureThrows(fn() => $this->service('inputs_undiscovered_appeared', $pdo)->scan($this->root), ScanSnapshotChangedException::class);

        assertSame(ScanSnapshotChangedException::inputChangedAfterRead('generated/late.d.ts')->getMessage(), $error->getMessage());
    }

    public function testAnUnchangedUndiscoveredReadScansAndCommits(): void
    {
        $pdo = $this->freshTestDatabase();

        $result = $this->service('inputs_undiscovered_stable', $pdo)->scan($this->root);

        assertSame('full', $result->data['mode']);
        assertSame([], $result->data['degraded_languages']);
        assertSame(true, (int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn() > 0);
    }

    /**
     * The packaged PHP worker replaced by the fake worker in one mode, so the
     * whole service runs: discovery, workers, validation and reconciliation.
     * The tree holds PHP only, so no other language starts a worker.
     */
    private function service(string $mode, \PDO $pdo): ProjectScanService
    {
        unlink($this->root . '/src/Checkout.ts');
        unlink($this->root . '/src/Other.ts');
        file_put_contents($this->root . '/src/Checkout.php', "<?php\nfinal class Checkout {}\n");
        mkdir($this->installation . '/workers/php/bin', 0o777, true);
        file_put_contents($this->installation . '/workers/php/bin/worker', sprintf(
            "<?php\n\$argv = [__FILE__, %s];\nrequire %s;\n",
            var_export($mode, true),
            var_export(self::repositoryRoot() . '/tests/Fixtures/workers/fake-worker.php', true),
        ));

        return new ProjectScanService($pdo, $this->installation, [$this->root]);
    }

    private function runScan(string $mode, int $batchFiles = 100, bool $otherLanguage = false): LanguageScanResult
    {
        $clients = [];
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willReturnCallback(function () use (&$clients, $mode): ProcessScannerClient {
            return $clients[] = new ProcessScannerClient([PHP_BINARY, self::repositoryRoot() . '/tests/Fixtures/workers/fake-worker.php', $mode], new WorkerLimits());
        });
        $descriptors = [new LanguageDescriptor(key: 'typescript', stage: 'typescript-analysis', languages: ['typescript'], command: ['php', '-r', 'echo 1'], scanBatchFiles: $batchFiles)];
        if ($otherLanguage) {
            $descriptors[] = new LanguageDescriptor(key: 'python', stage: 'python-analysis', languages: ['javascript'], command: ['php', '-r', 'echo 1'], scanBatchFiles: $batchFiles);
        }
        $runner = new LanguageScanRunner($descriptors, $pool, new ContributionCacheService());

        try {
            return $runner->run($this->plan($otherLanguage), new CancellationToken());
        } finally {
            foreach ($clients as $client) {
                $client->shutdown();
            }
        }
    }

    private function plan(bool $otherLanguage): ScanPlan
    {
        $files = [$this->discovered('src/Checkout.ts', 'typescript'), $this->discovered('src/Other.ts', $otherLanguage ? 'javascript' : 'typescript')];

        return new ScanPlan(
            preparation: new ScanPreparation(
                configuration: new ProjectConfiguration(),
                discovery: new DiscoveryResult(rootRealpath: $this->root, files: $files, units: [], diagnostics: [], inputHash: '', configurationHash: ''),
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
            projectId: 'undiscovered-inputs',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
    }

    private function discovered(string $relativePath, string $language): DiscoveredFile
    {
        $absolute = $this->root . '/' . $relativePath;
        $contents = (string) file_get_contents($absolute);

        return new DiscoveredFile($relativePath, $absolute, $language, strlen($contents), (int) filemtime($absolute), hash('sha256', $contents));
    }
}
