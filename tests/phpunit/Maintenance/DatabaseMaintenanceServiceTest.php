<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Maintenance;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Query\ResultEnvelope;
use Knossos\Scan\ProjectWriterLock;
use Knossos\Scan\ScanBusyException;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[Group('database-maintenance-service')]
final class DatabaseMaintenanceServiceTest extends TestCase
{
    private static function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private PDO $pdo;

    private string $dbPath;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/knossos-maint-' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
        $this->dbPath = $this->tempDir . '/database.sqlite';
        $this->pdo = SqliteConnection::open($this->dbPath);
        (new MigrationRunner($this->pdo, self::projectRoot() . '/migrations'))->migrate();
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (!is_dir($this->tempDir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($this->tempDir);
    }

    // ----- helpers -----

    private function makeService(?string $path = null): DatabaseMaintenanceService
    {
        return new DatabaseMaintenanceService($this->pdo, $path ?? $this->dbPath);
    }

    private function seedProject(string $id = 'proj-1', string $name = 'Test'): void
    {
        // The migrations enforce UNIQUE(projects.root_realpath), so each project
        // must own its own subdirectory inside the temp tree.
        $root = $this->tempDir . '/' . $id;
        if (!is_dir($root)) {
            mkdir($root, 0o755, true);
        }
        $this->pdo->prepare('INSERT INTO projects(id, name, root_realpath, config_json, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)')
            ->execute([$id, $name, $root, '{}', gmdate('c'), gmdate('c')]);
    }

    private function seedScan(string $id, string $projectId, string $status, string $startedAt): void
    {
        $this->pdo->prepare('INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at) VALUES(?, ?, ?, ?, ?, ?)')
            ->execute([$id, $projectId, 'full', $status, 'hash', $startedAt]);
    }

    private function seedFileForScan(string $projectId, string $scanId): void
    {
        $this->pdo->prepare('INSERT INTO files(id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['file-' . $scanId, $projectId, 'src/' . $scanId . '.php', 'h', 100, 1, 'php', '0.1.0', $scanId]);
    }

    // ----- removeProject -----

    public function testRemoveProjectDryRunWithoutActiveScan(): void
    {
        $this->seedProject();

        $result = $this->makeService()->removeProject('proj-1', false);

        assertSame('proj-1', $result->projectId);
        assertSame('', $result->snapshotId);
        assertSame('Dry run: project would be removed.', $result->summary);
        assertSame(false, $result->data['executed']);
        assertSame('proj-1', $result->data['project']['id']);
        assertSame('Test', $result->data['project']['name']);
        assertSame(['Set execute=true to permanently remove this project and its stored graph.'], $result->warnings);
        assertSame(['scans' => 0, 'files' => 0, 'nodes' => 0, 'edges' => 0, 'diagnostics' => 0, 'annotations' => 0], $result->data['counts']);
    }

    public function testRemoveProjectDryRunWithActiveScan(): void
    {
        $this->seedProject();
        $this->seedScan('scan-A', 'proj-1', 'complete', '2020-01-01T00:00:00Z');
        $this->pdo->prepare('UPDATE projects SET active_scan_id = ? WHERE id = ?')
            ->execute(['scan-A', 'proj-1']);

        $result = $this->makeService()->removeProject('proj-1', false);

        assertSame('scan-A', $result->snapshotId);
    }

    public function testRemoveProjectExecuteDeletesProjectAndClearsActiveScan(): void
    {
        $this->seedProject();
        $this->seedScan('scan-A', 'proj-1', 'complete', '2020-01-01T00:00:00Z');
        $this->pdo->prepare('UPDATE projects SET active_scan_id = ? WHERE id = ?')
            ->execute(['scan-A', 'proj-1']);

        $result = $this->makeService()->removeProject('proj-1', true);

        assertSame('Project and stored graph removed.', $result->summary);
        assertSame(true, $result->data['executed']);
        assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM projects WHERE id = 'proj-1'")->fetchColumn());
        // scan_locks lease already released
        assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM scan_locks WHERE project_id = 'proj-1'")->fetchColumn());
    }

    public function testRemoveProjectExecuteRollsBackOnFailureAndReleasesLease(): void
    {
        $this->seedProject();
        // Force DELETE FROM projects to fail mid-transaction so the catch block runs.
        $this->pdo->exec("CREATE TRIGGER fail_project_delete BEFORE DELETE ON projects BEGIN SELECT RAISE(ABORT, 'forced'); END");

        captureThrows(
            fn () => $this->makeService()->removeProject('proj-1', true),
            PDOException::class,
        );

        // Project must still exist (rollback succeeded).
        assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM projects WHERE id = 'proj-1'")->fetchColumn());
        // Lease released even on failure path.
        assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM scan_locks WHERE project_id = 'proj-1'")->fetchColumn());
    }

    public function testRemoveProjectThrowsForUnknownProject(): void
    {
        $error = captureThrows(
            fn () => $this->makeService()->removeProject('proj-X', false),
            InvalidArgumentException::class,
        );
        assertSame(true, str_contains($error->getMessage(), 'proj-X'));
    }

    // ----- cleanupStaleScans -----

    public function testCleanupStaleScansRejectsInvalidHours(): void
    {
        $this->seedProject();
        $service = $this->makeService();
        foreach ([0, -1, 8761] as $bad) {
            $error = captureThrows(
                fn () => $service->cleanupStaleScans('proj-1', $bad, false),
                InvalidArgumentException::class,
            );
            assertSame(true, str_contains($error->getMessage(), 'between 1 and 8760'));
        }
    }

    public function testCleanupStaleScansAcceptsBoundaryHours(): void
    {
        $this->seedProject();
        $service = $this->makeService();
        foreach ([1, 8760] as $hours) {
            $result = $service->cleanupStaleScans('proj-1', $hours, false);
            assertSame('proj-1', $result->projectId);
        }
    }

    public function testCleanupStaleScansThrowsForUnknownProject(): void
    {
        $error = captureThrows(
            fn () => $this->makeService()->cleanupStaleScans('proj-X', 24, false),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Unknown project', $error->getMessage());
    }

    public function testCleanupStaleScansDryRunReturnsRemovableAndProtected(): void
    {
        $this->seedProject();
        $this->seedScan('removable-A', 'proj-1', 'failed', '2000-01-01T00:00:00Z');
        $this->seedScan('removable-B', 'proj-1', 'cancelled', '2000-02-01T00:00:00Z');
        $this->seedScan('protected-C', 'proj-1', 'running', '2000-03-01T00:00:00Z');
        // Seed file referencing protected-C; forces scanReferenceCount > 0.
        $this->seedFileForScan('proj-1', 'protected-C');
        $this->seedScan('fresh-D', 'proj-1', 'complete', gmdate('Y-m-d\TH:i:s\Z'));

        $result = $this->makeService()->cleanupStaleScans('proj-1', 24, false);

        assertSame(false, $result->data['executed']);
        assertSame(['removable-A', 'removable-B'], $result->data['removable_scan_ids']);
        assertSame(['protected-C'], $result->data['protected_scan_ids']);
        assertSame(['Referenced scans were protected from cleanup.'], $result->warnings);
    }

    public function testCleanupStaleScansDryRunNoProtectedNoExecutionWarning(): void
    {
        $this->seedProject();
        $this->seedScan('removable-only', 'proj-1', 'failed', '2000-01-01T00:00:00Z');

        $result = $this->makeService()->cleanupStaleScans('proj-1', 24, false);

        assertSame(['Set execute=true to remove the listed stale scans.'], $result->warnings);
    }

    public function testCleanupStaleScansSummaryPluralization(): void
    {
        $this->seedProject();
        $this->seedScan('only', 'proj-1', 'failed', '2000-01-01T00:00:00Z');
        $result = $this->makeService()->cleanupStaleScans('proj-1', 24, false);
        assertSame('Dry run: would remove 1 stale scan.', $result->summary);

        $this->seedScan('two', 'proj-1', 'failed', '2000-02-01T00:00:00Z');
        $result = $this->makeService()->cleanupStaleScans('proj-1', 24, false);
        assertSame('Dry run: would remove 2 stale scans.', $result->summary);
    }

    public function testCleanupStaleScansSkipsUnparseableStartedAt(): void
    {
        $this->seedProject();
        $this->seedScan('bad', 'proj-1', 'failed', 'not-a-date');

        $result = $this->makeService()->cleanupStaleScans('proj-1', 24, false);

        assertSame([], $result->data['removable_scan_ids']);
        assertSame([], $result->data['protected_scan_ids']);
    }

    public function testCleanupStaleScansTruncatesOverOneThousand(): void
    {
        $this->seedProject();
        $insert = $this->pdo->prepare('INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at) VALUES(?, ?, ?, ?, ?, ?)');
        for ($i = 0; $i < 1002; $i++) {
            $insert->execute([sprintf('s-%04d', $i), 'proj-1', 'full', 'failed', 'h', '2000-01-01T00:00:00Z']);
        }

        $result = $this->makeService()->cleanupStaleScans('proj-1', 24, false);

        assertSame(true, $result->truncated);
        assertSame(1000, count($result->data['removable_scan_ids']));
    }

    public function testCleanupStaleScansExcludesActiveScanId(): void
    {
        $this->seedProject();
        $this->seedScan('active', 'proj-1', 'running', '2000-01-01T00:00:00Z');
        $this->pdo->prepare('UPDATE projects SET active_scan_id = ? WHERE id = ?')
            ->execute(['active', 'proj-1']);
        $this->seedScan('stale', 'proj-1', 'failed', '2000-01-01T00:00:00Z');

        $result = $this->makeService()->cleanupStaleScans('proj-1', 24, false);

        assertSame(['stale'], $result->data['removable_scan_ids']);
    }

    public function testCleanupStaleScansMatchesStatusFilter(): void
    {
        $this->seedProject();
        // 'complete' is NOT in the cleanup set per the source.
        $this->seedScan('done', 'proj-1', 'complete', '2000-01-01T00:00:00Z');

        $result = $this->makeService()->cleanupStaleScans('proj-1', 24, false);

        assertSame([], $result->data['removable_scan_ids']);
    }

    public function testCleanupStaleScansExecuteDeletesRemovable(): void
    {
        $this->seedProject();
        $this->seedScan('old', 'proj-1', 'failed', '2000-01-01T00:00:00Z');

        $result = $this->makeService()->cleanupStaleScans('proj-1', 24, true);

        assertSame(true, $result->data['executed']);
        assertSame('Removed 1 stale scan.', $result->summary);
        assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM scans WHERE id = 'old'")->fetchColumn());
        // Lease released
        assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM scan_locks WHERE project_id = 'proj-1'")->fetchColumn());
    }

    public function testCleanupStaleScansExecuteSkipsWhenNoRemovable(): void
    {
        $this->seedProject();
        $this->seedScan('protected-only', 'proj-1', 'failed', '2000-01-01T00:00:00Z');
        $this->seedFileForScan('proj-1', 'protected-only');

        $result = $this->makeService()->cleanupStaleScans('proj-1', 24, true);

        // Cleanup path with execute=true but no removable scans skips the transaction entirely.
        assertSame('Removed 0 stale scans.', $result->summary);
        assertSame([], $result->data['removable_scan_ids']);
        assertSame(['protected-only'], $result->data['protected_scan_ids']);
    }

    public function testCleanupStaleScansExecuteRollsBackOnDeleteFailure(): void
    {
        $this->seedProject();
        $this->seedScan('old', 'proj-1', 'failed', '2000-01-01T00:00:00Z');
        $this->pdo->exec("CREATE TRIGGER fail_scan_delete BEFORE DELETE ON scans BEGIN SELECT RAISE(ABORT, 'forced'); END");

        captureThrows(
            fn () => $this->makeService()->cleanupStaleScans('proj-1', 24, true),
            PDOException::class,
        );

        assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM scans WHERE id = 'old'")->fetchColumn());
        assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM scan_locks WHERE project_id = 'proj-1'")->fetchColumn());
    }

    // ----- maintain -----

    public function testMaintainRejectsInvalidAction(): void
    {
        $service = $this->makeService();
        foreach (['bogus', 'INTEGRITY', '', 'integrity ', 'integrit'] as $bad) {
            $error = captureThrows(
                fn () => $service->maintain($bad, false),
                InvalidArgumentException::class,
            );

            $this->assertStringContainsString('integrity, checkpoint, optimize, or backup', $error->getMessage());
        }
    }

    public function testMaintainIntegrityCheckPassesForCleanDatabase(): void
    {
        $result = $this->makeService()->maintain('integrity', false);

        assertSame('database', $result->projectId);
        assertSame('Database integrity check passed.', $result->summary);
        assertSame(true, $result->data['ok']);
        assertSame(['ok'], $result->data['results']);
        assertSame([], $result->warnings);
        assertSame(true, $result->data['executed']);
    }

    public function testMaintainIntegrityCheckReportsProblemsForCorruptedDatabase(): void
    {
        // Use a PDO subclass that intercepts the integrity_check PRAGMA and returns
        // a non-'ok' row. This is the most reliable way to exercise the source's
        // `=== ['ok']` false-branch: corrupting SQLite's on-disk pages would either
        // be rejected at connection time (HY000 26) or not trip integrity_check.
        //
        // The stub deliberately does NOT implement __call — every method used by
        // DatabaseMaintenanceService is explicitly forwarded so future API drift
        // fails loudly at test time instead of silently passing.
        $stubPdo = new class($this->dbPath) extends PDO {
            private PDO $real;

            public function __construct(string $path)
            {
                $this->real = SqliteConnection::open($path);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                if (str_contains($query, 'integrity_check')) {
                    $fake = new PDO('sqlite::memory:');
                    $fake->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $fake->exec("CREATE TABLE _r(v TEXT); INSERT INTO _r VALUES ('database disk image is malformed')");

                    return $fake->query('SELECT v FROM _r');
                }
                return $this->real->query($query, $fetchMode, ...$fetchModeArgs);
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                return $this->real->prepare($query, $options);
            }

            public function exec(string $statement): int|false
            {
                return $this->real->exec($statement);
            }

            public function beginTransaction(): bool
            {
                return $this->real->beginTransaction();
            }

            public function commit(): bool
            {
                return $this->real->commit();
            }

            public function rollBack(): bool
            {
                return $this->real->rollBack();
            }

            public function inTransaction(): bool
            {
                return $this->real->inTransaction();
            }
        };

        $service = new DatabaseMaintenanceService($stubPdo, $this->dbPath);

        $result = $service->maintain('integrity', false);

        assertSame(false, $result->data['ok']);
        $this->assertNotSame(['ok'], $result->data['results']);
        assertSame(['Restore from a verified backup before attempting manual repair.'], $result->warnings);
    }

    public function testMaintainCheckpointDryRun(): void
    {
        $result = $this->makeService()->maintain('checkpoint', false);

        assertSame('Dry run: database checkpoint would run.', $result->summary);
        assertSame(false, $result->data['executed']);
        assertSame('checkpoint', $result->data['action']);
        assertSame(['Set execute=true to perform this maintenance action.'], $result->warnings);
    }

    public function testMaintainOptimizeDryRun(): void
    {
        $result = $this->makeService()->maintain('optimize', false);
        assertSame('Dry run: database optimize would run.', $result->summary);
        assertSame('optimize', $result->data['action']);
        assertSame(['Set execute=true to perform this maintenance action.'], $result->warnings);
    }

    public function testMaintainBackupDryRunIncludesTargetPath(): void
    {
        $result = $this->makeService()->maintain('backup', false);

        assertSame('Dry run: database backup would run.', $result->summary);
        assertSame(false, $result->data['executed']);
        $this->assertArrayHasKey('target', $result->data);
        assertSame(true, str_ends_with($result->data['target'], '.sqlite'));
    }

    public function testMaintainCheckpointExecuteRunsPragma(): void
    {
        $this->seedProject();

        $result = $this->makeService()->maintain('checkpoint', true);

        assertSame(true, $result->data['executed']);
        $this->assertArrayHasKey('result', $result->data);
    }

    public function testMaintainOptimizeExecuteRunsWithoutError(): void
    {
        $this->seedProject();

        $result = $this->makeService()->maintain('optimize', true);

        assertSame(true, $result->data['executed']);
        assertSame('optimize', $result->data['action']);
    }

    public function testMaintainBackupExecuteProducesValidBackupCleaningScanLocks(): void
    {
        $this->seedProject();
        $result = $this->makeService()->maintain('backup', true, 'snapshot-test.sqlite');

        assertSame(true, $result->data['executed']);
        $target = $result->data['target'];
        assertSame(true, is_file($target));
        $this->assertGreaterThan(0, $result->data['bytes']);

        // Permissions must be 0600.
        assertSame(0600, fileperms($target) & 0777);

        // The backup is a sanitized clean copy: scan_locks table exists
        // (VACUUM INTO copies the entire schema) but must contain zero rows.
        $backupPdo = SqliteConnection::open($target);
        $lockCount = (int) $backupPdo->query('SELECT COUNT(*) FROM scan_locks')->fetchColumn();
        assertSame(0, $lockCount);

        // No leftover .tmp files in the parent directory.
        $dir = dirname($target);
        $tmps = glob($dir . '/.*.tmp') ?: [];
        assertSame([], $tmps);
    }

    public function testMaintainBackupAcceptsDefaultNameFormatFromGmt(): void
    {
        $this->seedProject();
        $result = $this->makeService()->maintain('backup', true);
        assertSame(true, str_contains(basename($result->data['target']), 'knossos-'));
    }

    public function testMaintainBackupRejectsMemoryDatabaseOnDryRun(): void
    {
        $service = $this->makeService(':memory:');

        $error = captureThrows(
            fn () => $service->maintain('backup', false),
            InvalidArgumentException::class,
        );

        assertSame(true, str_contains($error->getMessage(), 'file-backed'));
    }

    public function testMaintainBackupRejectsMemoryDatabaseOnExecute(): void
    {
        $service = $this->makeService(':memory:');

        $error = captureThrows(
            fn () => $service->maintain('backup', true),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('file-backed', $error->getMessage());
    }

    public function testMaintainBackupRejectsUnsafeBackupNames(): void
    {
        $service = $this->makeService();
        foreach (['../escape.sqlite', 'foo/bar.sqlite', '.hidden.sqlite', 'no-ext', ' has space.sqlite'] as $bad) {
            $error = captureThrows(
                fn () => $service->maintain('backup', false, $bad),
                InvalidArgumentException::class,
            );
            assertSame(true, str_contains($error->getMessage(), 'simple .sqlite filename'));
        }
    }

    public function testMaintainBackupAcceptsValidBackupNameCornerCases(): void
    {
        $this->seedProject();
        // Single-character base, snake_case, dots in middle, longer names.
        foreach (['a.sqlite', 'snake_case.sqlite', 'name.with.dots.sqlite'] as $valid) {
            $result = $this->makeService()->maintain('backup', true, $valid);
            assertSame(true, $result->data['executed']);
            assertSame(true, str_ends_with($result->data['target'], '/' . $valid));
        }
    }

    public function testMaintainBackupRejectsExistingTarget(): void
    {
        $this->seedProject();
        $target = dirname($this->dbPath) . '/backups/existing-target.sqlite';
        @mkdir(dirname($target), 0700, true);
        file_put_contents($target, 'pre-existing-content');

        $error = captureThrows(
            fn () => $this->makeService()->maintain('backup', true, 'existing-target.sqlite'),
            InvalidArgumentException::class,
        );

        assertSame(true, str_contains($error->getMessage(), 'already exists'));
    }

    public function testMaintainBackupFailsWhenBackupsDirectoryCannotBeCreated(): void
    {
        $this->seedProject();
        // Pre-create 'backups' AS A REGULAR FILE under the database dir so is_dir=false
        // and mkdir fails on the recursive creation, triggering the RuntimeException branch.
        $badBackupsDir = dirname($this->dbPath) . '/backups';
        @mkdir(dirname($badBackupsDir), 0700, true);
        file_put_contents($badBackupsDir, 'i am not a directory');

        $error = captureThrows(
            fn () => $this->makeService()->maintain('backup', true, 'fail-dir.sqlite'),
            RuntimeException::class,
        );

        assertSame(true, str_contains($error->getMessage(), 'Unable to create the database backup directory'));
    }

    public function testMaintainReleasesAcquiredLeasesWhenSecondAcquireFails(): void
    {
        $this->seedProject('proj-a');
        $this->seedProject('proj-b');

        // Hold the proj-b lock externally so the 2nd acquire will throw ScanBusyException.
        $other = (new ProjectWriterLock($this->pdo))->acquire('proj-b');
        try {
            captureThrows(
                fn () => $this->makeService()->maintain('optimize', true),
                ScanBusyException::class,
            );

            // The proj-a lease taken first must have been released by the rollback loop.
            assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM scan_locks WHERE project_id = 'proj-a'")->fetchColumn());
        } finally {
            $other->release();
        }
    }

    public function testMaintainThrowsWhenMoreThan1000Projects(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO projects(id, name, root_realpath, config_json, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)');
        for ($i = 0; $i < 1001; $i++) {
            $id = sprintf('p-%04d', $i);
            $uniqueRoot = $this->tempDir . '/' . $id;
            if (!is_dir($uniqueRoot)) {
                mkdir($uniqueRoot, 0o755, true);
            }
            $insert->execute([$id, 'P', $uniqueRoot, '{}', gmdate('c'), gmdate('c')]);
        }

        $error = captureThrows(
            fn () => $this->makeService()->maintain('optimize', true),
            RuntimeException::class,
        );

        assertSame(true, str_contains($error->getMessage(), '1000 projects'));
    }

    public function testMaintainReleasesLeasesInReverseOrderOnSuccess(): void
    {
        $this->seedProject('proj-a');
        $this->seedProject('proj-b');

        $this->makeService()->maintain('optimize', true);

        // After successful completion both leases are released (phpunit destructor +
        // finally block). The scan_locks table must be empty.
        assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM scan_locks")->fetchColumn());
    }

    public function testMaintainIntegrityIgnoresExecuteFlag(): void
    {
        // integrity branch runs PRAGMA regardless of execute flag; the data['executed']
        // is always true on this branch because the source unconditionally sets it.
        $r1 = $this->makeService()->maintain('integrity', false);
        $r2 = $this->makeService()->maintain('integrity', true);
        assertSame(true, $r1->data['executed']);
        assertSame(true, $r2->data['executed']);
        assertSame('integrity', $r1->data['action']);
        assertSame('integrity', $r2->data['action']);
    }

    // ----- class shape -----

    public function testClassIsFinalAndReadonly(): void
    {
        $ref = new ReflectionClass(DatabaseMaintenanceService::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->isReadOnly());
    }

    public function testReturnedEnvelopeIsResultEnvelopeInstance(): void
    {
        $this->seedProject();
        $result = $this->makeService()->removeProject('proj-1', false);
        $this->assertInstanceOf(ResultEnvelope::class, $result);
    }
}
