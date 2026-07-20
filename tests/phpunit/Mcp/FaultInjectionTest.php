<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Scanner\Worker\WorkerLimits;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use stdClass;

final class FaultInjectionTest extends KnossosTestCase
{
    #[Group('fault-injection')]
    public function testStorageLocksDiskLimitsAndCorruptCachesRecoverWithoutPublishingPartialState(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-fault-storage-');
        $cacheDatabase = tempnam(sys_get_temp_dir(), 'knossos-fault-cache-');
        if ($path === false || $cacheDatabase === false) {
            throw new RuntimeException('Unable to allocate fault-injection databases.');
        }
        try {
            $writer = SqliteConnection::open($path);
            $writer->exec('CREATE TABLE fault_payloads (id INTEGER PRIMARY KEY, payload BLOB NOT NULL)');
            $writer->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $pages = (int) $writer->query('PRAGMA page_count')->fetchColumn();
            $writer->exec('PRAGMA max_page_count = ' . $pages);
            assertThrows(fn() => $writer->exec('INSERT INTO fault_payloads(payload) VALUES (zeroblob(1048576))'), PDOException::class);
            assertSame(0, (int) $writer->query('SELECT COUNT(*) FROM fault_payloads')->fetchColumn());
            $writer->exec('PRAGMA max_page_count = 10000');
            $writer->exec("INSERT INTO fault_payloads(payload) VALUES (x'01')");

            $contender = SqliteConnection::open($path);
            $contender->exec('PRAGMA busy_timeout = 25');
            $writer->exec('BEGIN IMMEDIATE');
            assertThrows(fn() => $contender->exec("INSERT INTO fault_payloads(payload) VALUES (x'02')"), PDOException::class);
            $writer->exec('ROLLBACK');
            $contender->exec("INSERT INTO fault_payloads(payload) VALUES (x'03')");
            assertSame(2, (int) $contender->query('SELECT COUNT(*) FROM fault_payloads')->fetchColumn());

            $root = self::repositoryRoot() . '/tests/Fixtures/configured';
            $cachePdo = SqliteConnection::open($cacheDatabase);
            (new MigrationRunner($cachePdo, self::repositoryRoot() . '/migrations'))->migrate();
            $service = new ProjectScanService($cachePdo, self::repositoryRoot(), [$root]);
            $first = $service->scan($root);
            $nodeCount = (int) $cachePdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn();
            $cachePdo->exec("UPDATE contribution_cache SET payload_json = '{corrupt' ");
            $recovered = $service->scan($root, mode: 'incremental');
            assertSame(true, $recovered->data['parsed_files'] > 0);
            assertSame($nodeCount, (int) $cachePdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
            assertNotSame($first->snapshotId, $recovered->snapshotId);
        } finally {
            unset($service, $cachePdo, $contender, $writer);
            foreach ([$path, $path . '-shm', $path . '-wal', $cacheDatabase, $cacheDatabase . '-shm', $cacheDatabase . '-wal'] as $candidate) {
                @unlink($candidate);
            }
        }
    }

    #[Group('fault-injection')]
    public function testCancelledWorkerSupervisionTerminatesSpawnedProcessTrees(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_kill')) {
            return;
        }
        $pidFile = tempnam(sys_get_temp_dir(), 'knossos-child-pid-');
        if ($pidFile === false) {
            throw new RuntimeException('Unable to allocate child PID fixture.');
        }
        try {
            $client = new ProcessScannerClient(
                [PHP_BINARY, self::repositoryRoot() . '/tests/Fixtures/workers/fake-worker.php', 'child_scan', $pidFile],
                new WorkerLimits(requestTimeoutMs: 3_000),
            );
            $client->initialize();
            $polls = 0;
            $error = captureThrows(
                fn() => iterator_to_array($client->scan([new stdClass()], function () use (&$polls): bool {
                    return ++$polls >= 4;
                })),
                WorkerException::class,
            );
            assertSame('WORKER_CANCELLED', $error->diagnosticCode);
            $childPid = (int) trim((string) file_get_contents($pidFile));
            assertSame(true, $childPid > 0);
            for ($attempt = 0; $attempt < 50 && is_dir('/proc/' . $childPid); ++$attempt) {
                usleep(10_000);
            }
            assertSame(false, is_dir('/proc/' . $childPid));
        } finally {
            unset($client);
            @unlink($pidFile);
        }
    }
}
