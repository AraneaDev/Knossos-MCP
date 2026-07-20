<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ProjectWriterLock;
use Knossos\Scan\ScanBusyException;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Scanner\Worker\WorkerLimits;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class ConcurrencyTest extends KnossosTestCase
{
    #[Group('concurrency')]
    public function testWriterLeasesCancellationAndStaleRecoveryPreserveQueryAvailability(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-concurrency-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate concurrency database.');
        }
        try {
            $writerPdo = SqliteConnection::open($path);
            (new MigrationRunner($writerPdo, self::repositoryRoot() . '/migrations'))->migrate();
            $repository = new SqliteGraphRepository($writerPdo);
            $project = StableId::project('concurrency-fixture');
            $scan = StableId::scan($project, 'active');
            $file = StableId::file($project, 'src/A.php');
            $node = StableId::symbol($project, 'php', 'class', 'Fixture\\A');
            $repository->saveProject($project, 'Concurrency Fixture', '/workspace/concurrency');
            $repository->createScan($scan, $project, 'full', hash('sha256', 'scanner'));
            $repository->saveFile($file, $project, 'src/A.php', hash('sha256', 'A'), 1, 1, 'php', '0.2.0', $scan);
            $repository->saveNode($node, $project, 'class', 'Fixture\\A', 'A', null, $file, 1, 1, 'ast', 'certain', [], 'php:file:src/A.php', $scan);
            $repository->completeScan($project, $scan);

            $readerPdo = SqliteConnection::open($path);
            $lease = (new ProjectWriterLock($writerPdo))->acquire($project);
            assertSame($scan, (new ArchitectureQueryService($readerPdo))->architectureSummary($project)->snapshotId);
            assertThrows(fn() => (new ProjectWriterLock($readerPdo))->acquire($project), ScanBusyException::class);
            $lease->release();
            $second = (new ProjectWriterLock($readerPdo))->acquire($project);
            $second->release();

            $writerPdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, 0)')
                ->execute(['project' => $project, 'token' => 'orphan']);
            $recovered = (new ProjectWriterLock($writerPdo, 10, fn(): int => 100))->acquire($project);
            $recovered->release();
            assertSame(0, (int) $writerPdo->query('SELECT COUNT(*) FROM scan_locks')->fetchColumn());

            $client = $this->fakeWorkerClient('slow_scan', new WorkerLimits(requestTimeoutMs: 2_000));
            $client->initialize();
            $polls = 0;
            $error = captureThrows(
                fn() => iterator_to_array($client->scan([], function () use (&$polls): bool {
                    return ++$polls >= 2;
                })),
                WorkerException::class,
            );
            assertSame('WORKER_CANCELLED', $error->diagnosticCode);

            $token = new CancellationToken();
            $token->cancel();
            assertThrows(
                fn() => (new ProjectScanService($writerPdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']))->scan(self::repositoryRoot() . '/tests/Fixtures/mixed', cancellation: $token),
                \Knossos\Scan\ScanCancelledException::class,
            );
            assertSame($scan, (string) $writerPdo->query("SELECT active_scan_id FROM projects WHERE id = '$project'")->fetchColumn());
            assertSame(0, (int) $writerPdo->query('SELECT COUNT(*) FROM scan_locks')->fetchColumn());
        } finally {
            unset($readerPdo, $writerPdo);
            foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }
}
