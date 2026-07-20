<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Scan\ProjectWriterLock;
use Knossos\Scan\ScanBusyException;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class MaintenanceTest extends KnossosTestCase
{
    #[Group('maintenance')]
    public function testMaintenancePreviewsDestructiveWorkAndProducesRestorableBackups(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-maintenance-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate maintenance database.');
        }
        try {
            $pdo = SqliteConnection::open($path);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $repository = new SqliteGraphRepository($pdo);
            $project = StableId::project('maintenance-fixture');
            $active = StableId::scan($project, 'active');
            $stale = StableId::scan($project, 'stale');
            $protected = StableId::scan($project, 'protected');
            $repository->saveProject($project, 'Maintenance Fixture', '/workspace/maintenance');
            $repository->createScan($active, $project, 'full', hash('sha256', 'active'));
            $repository->completeScan($project, $active);
            $repository->createScan($stale, $project, 'incremental', hash('sha256', 'stale'));
            $pdo->exec("UPDATE scans SET status = 'failed', started_at = '2000-01-01T00:00:00+00:00' WHERE id = '" . $stale . "'");
            $repository->createScan($protected, $project, 'incremental', hash('sha256', 'protected'));
            $protectedFile = StableId::file($project, 'src/Protected.php');
            $repository->saveFile($protectedFile, $project, 'src/Protected.php', hash('sha256', 'protected'), 9, 1, 'php', '1', $protected);
            $pdo->exec("UPDATE scans SET status = 'cancelled', started_at = '2000-01-01T00:00:00+00:00' WHERE id = '" . $protected . "'");

            $service = new DatabaseMaintenanceService($pdo, $path);
            $cleanupPreview = $service->cleanupStaleScans($project);
            assertSame(false, $cleanupPreview->data['executed']);
            assertSame([$stale], $cleanupPreview->data['removable_scan_ids']);
            assertSame([$protected], $cleanupPreview->data['protected_scan_ids']);
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM scans WHERE id = '" . $stale . "'")->fetchColumn());
            assertSame(true, $service->cleanupStaleScans($project, execute: true)->data['executed']);
            assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM scans WHERE id = '" . $stale . "'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM scans WHERE id = '" . $protected . "'")->fetchColumn());
            assertThrows(fn() => $service->cleanupStaleScans($project, 0), InvalidArgumentException::class);

            assertSame(true, $service->maintain('integrity')->data['ok']);
            assertSame(false, $service->maintain('checkpoint')->data['executed']);
            assertSame(false, $service->maintain('backup', backupName: 'preview.sqlite')->data['executed']);
            assertSame(true, $service->maintain('checkpoint', true)->data['executed']);
            assertSame(true, $service->maintain('optimize', true)->data['executed']);
            assertThrows(fn() => $service->maintain('backup', backupName: '../escape.sqlite'), InvalidArgumentException::class);
            $backupResult = $service->maintain('backup', true, 'fixture.sqlite');
            assertSame(true, is_file($backupResult->data['target']));
            $backupPdo = SqliteConnection::open($backupResult->data['target']);
            assertSame('ok', $backupPdo->query('PRAGMA integrity_check')->fetchColumn());
            assertSame(1, (int) $backupPdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
            assertSame(0, (int) $backupPdo->query('SELECT COUNT(*) FROM scan_locks')->fetchColumn());
            unset($backupPdo);
            assertThrows(fn() => $service->maintain('backup', true, 'fixture.sqlite'), InvalidArgumentException::class);
            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM scan_locks')->fetchColumn());

            [$exit, $stdout, $stderr] = $this->runFixtureCommandOutput([
                PHP_BINARY, self::repositoryRoot() . '/bin/knossos', 'maintain-database', 'integrity', '--db=' . $path, '--json',
            ]);
            assertSame(0, $exit);
            assertSame('', $stderr);
            assertSame(true, json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['data']['ok']);

            $lease = (new ProjectWriterLock($pdo))->acquire($project);
            assertThrows(fn() => $service->maintain('optimize', true), ScanBusyException::class);
            $lease->release();
            assertSame(false, $service->removeProject($project)->data['executed']);
            assertSame(true, $service->removeProject($project, true)->data['executed']);
            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
            assertThrows(fn() => $service->cleanupStaleScans($project), InvalidArgumentException::class);
            assertThrows(fn() => $service->maintain('invalid'), InvalidArgumentException::class);
            assertThrows(fn() => (new DatabaseMaintenanceService($pdo, ':memory:'))->maintain('backup'), InvalidArgumentException::class);
        } finally {
            unset($service, $repository, $pdo);
            $backup = dirname($path) . '/backups/fixture.sqlite';
            foreach ([$path, $path . '-shm', $path . '-wal', $backup, $backup . '-shm', $backup . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
            $backupDirectory = dirname($path) . '/backups';
            if (is_dir($backupDirectory)) {
                @rmdir($backupDirectory);
            }
        }
    }
}
