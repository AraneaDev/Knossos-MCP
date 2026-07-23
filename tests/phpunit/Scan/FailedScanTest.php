<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanCancelledException;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class FailedScanTest extends KnossosTestCase
{
    #[Group('scan')]
    public function testRecordFailedScanPersistsTerminalRowForExistingProject(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        $failedId = StableId::scan($ids['project'], 'nonce-failed');
        $repository->recordFailedScan($failedId, $ids['project'], 'full', 'failed');
        $cancelledId = StableId::scan($ids['project'], 'nonce-cancelled');
        $repository->recordFailedScan($cancelledId, $ids['project'], 'incremental', 'cancelled');

        $rows = $pdo->query("SELECT id, status, finished_at FROM scans WHERE status IN ('failed','cancelled') ORDER BY status")->fetchAll();
        assertSame(2, count($rows));
        assertSame('cancelled', $rows[0]['status']);
        assertSame('failed', $rows[1]['status']);
        // A terminal scan is finished, so cleanup can date it.
        assertSame(true, $rows[0]['finished_at'] !== null);
    }

    #[Group('scan')]
    public function testRecordFailedScanIsANoOpWhenProjectDoesNotExist(): void
    {
        $pdo = $this->freshTestDatabase();
        $repository = new SqliteGraphRepository($pdo);
        // No FK violation, no row: there is nothing to clean up for a project
        // that was never persisted (e.g. a first-ever scan that failed early).
        $repository->recordFailedScan(StableId::scan('project_missing', 'n'), 'project_missing', 'full', 'failed');
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn());
    }

    #[Group('scan')]
    public function testRecordFailedScanRejectsInvalidStatusAndMode(): void
    {
        [, $repository, $ids] = $this->storeFixture();
        assertThrows(fn() => $repository->recordFailedScan('s', $ids['project'], 'full', 'complete'), InvalidArgumentException::class);
        assertThrows(fn() => $repository->recordFailedScan('s', $ids['project'], 'auto', 'failed'), InvalidArgumentException::class);
    }

    #[Group('scan')]
    public function testCleanupRemovesPersistedFailedScan(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $failedId = StableId::scan($ids['project'], 'stale-failed');
        $repository->recordFailedScan($failedId, $ids['project'], 'full', 'failed');
        // Age it past the cutoff so cleanup considers it stale.
        $pdo->prepare('UPDATE scans SET started_at = :old WHERE id = :id')
            ->execute(['old' => '2000-01-01T00:00:00+00:00', 'id' => $failedId]);

        $maintenance = new DatabaseMaintenanceService($pdo, ':memory:');
        $result = $maintenance->cleanupStaleScans($ids['project'], 24, true);
        assertSame(true, in_array($failedId, $result->data['removable_scan_ids'], true));
        assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM scans WHERE status = 'failed'")->fetchColumn());
    }

    #[Group('scan')]
    public function testCancelledRescanPersistsCancelledScan(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            // The project now exists from the initial scan. Force a content change so
            // the rescan does real work, then cancel it mid-flight.
            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, file_get_contents($file) . "\n// touch\n");

            $calls = 0;
            $token = new CancellationToken(function () use (&$calls): bool {
                // Two cancellation checks run before the guarded try block; cancel
                // only once execution is inside it (during the language scan or the
                // post-analysis guard, before the atomic reconcile) so the terminal
                // attempt is recorded.
                return ++$calls >= 3;
            });

            $threw = false;
            try {
                (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, cancellation: $token);
            } catch (ScanCancelledException) {
                $threw = true;
            }
            assertSame(true, $threw);

            $cancelled = (int) $pdo->query("SELECT COUNT(*) FROM scans WHERE status = 'cancelled'")->fetchColumn();
            assertSame(1, $cancelled);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
