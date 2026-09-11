<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use InvalidArgumentException;
use Knossos\Store\SqliteScanLifecycle;
use Knossos\Store\SqliteStatementCache;
use Knossos\Store\SqliteTransactions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/** Projects and scans move through their states exactly once, and a race cannot half-promote a graph. */
final class SqliteScanLifecycleTest extends KnossosTestCase
{
    #[Group('store')]
    public function testSavingAProjectTwiceUpdatesItInPlace(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $lifecycle = self::lifecycle($pdo);

        $lifecycle->saveProject($ids['project'], 'Renamed', '/workspace/renamed', ['snapshot_retention' => 2]);

        $project = $lifecycle->findProject($ids['project']);
        assertSame('Renamed', $project['name']);
        assertSame('/workspace/renamed', $project['root_realpath']);
        assertSame('{"snapshot_retention":2}', $project['config_json']);
        assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
    }

    #[Group('store')]
    public function testAnUnknownProjectIsNull(): void
    {
        [$pdo] = $this->storeFixture();

        assertSame(null, self::lifecycle($pdo)->findProject('project_missing'));
    }

    #[Group('store')]
    public function testOnlyFullAndIncrementalScansCanBeOpened(): void
    {
        [$pdo, , $ids] = $this->storeFixture();

        assertThrows(static fn() => self::lifecycle($pdo)->createScan('scan_x', $ids['project'], 'partial', 'h'), InvalidArgumentException::class);
    }

    #[Group('store')]
    public function testCompletingAScanActivatesItExactlyOnce(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $lifecycle = self::lifecycle($pdo);

        $lifecycle->completeScan($ids['project'], $ids['scan']);

        assertSame($ids['scan'], $lifecycle->findProject($ids['project'])['active_scan_id']);
        assertThrows(static fn() => $lifecycle->completeScan($ids['project'], $ids['scan']), InvalidArgumentException::class);
    }

    #[Group('store')]
    public function testAFailedScanForAnUnpersistedProjectIsSkipped(): void
    {
        [$pdo] = $this->storeFixture();
        $before = (string) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();

        self::lifecycle($pdo)->recordFailedScan('scan_gone', 'project_never_saved', 'full', 'failed');

        assertSame($before, (string) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn());
    }

    #[Group('store')]
    public function testOnlyTerminalStatusesCanBeRecordedAsFailed(): void
    {
        [$pdo, , $ids] = $this->storeFixture();

        assertThrows(static fn() => self::lifecycle($pdo)->recordFailedScan('scan_x', $ids['project'], 'full', 'running'), InvalidArgumentException::class);
        assertThrows(static fn() => self::lifecycle($pdo)->recordFailedScan('scan_x', $ids['project'], 'sideways', 'failed'), InvalidArgumentException::class);
    }

    private static function lifecycle(PDO $pdo): SqliteScanLifecycle
    {
        return new SqliteScanLifecycle(new SqliteStatementCache($pdo), new SqliteTransactions($pdo));
    }
}
