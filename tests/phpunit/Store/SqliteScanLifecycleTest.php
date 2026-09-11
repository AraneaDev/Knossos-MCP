<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use InvalidArgumentException;
use Knossos\Store\GraphRepository;
use Knossos\Store\SqliteScanLifecycle;
use Knossos\Store\SqliteStatementCache;
use Knossos\Store\SqliteTransactions;
use Knossos\Store\SqliteValues;
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
    public function testRefreshScanCompletionRestampsFinishedAtOnACompleteScanWithoutChangingItsStatus(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $lifecycle = self::lifecycle($pdo);
        $lifecycle->completeScan($ids['project'], $ids['scan']);
        // Backdated directly in the DB rather than by waiting a second: now()
        // has one-second resolution, and this only needs a value the restamp
        // is guaranteed to move away from.
        $pdo->prepare('UPDATE scans SET finished_at = :old WHERE id = :id')
            ->execute(['old' => '2000-01-01T00:00:00Z', 'id' => $ids['scan']]);

        $before = SqliteValues::now();
        $lifecycle->refreshScanCompletion($ids['project'], $ids['scan']);

        $scan = $pdo->query("SELECT status, finished_at FROM scans WHERE id = '{$ids['scan']}'")->fetch(PDO::FETCH_ASSOC);
        assertSame('complete', $scan['status']);
        // Restamped with the time of the refresh, not cleared: a NULL would
        // also differ from the old value, and would read as never completed.
        assertSame(true, is_string($scan['finished_at']) && $scan['finished_at'] >= $before, sprintf('finished_at is %s.', var_export($scan['finished_at'], true)));
    }

    #[Group('store')]
    public function testRefreshScanCompletionLeavesARunningScanUntouched(): void
    {
        // Restricted to a complete scan: a running scan has no completion to
        // restate, and its (null) finished_at means something else entirely.
        [$pdo, , $ids] = $this->storeFixture();

        self::lifecycle($pdo)->refreshScanCompletion($ids['project'], $ids['scan']);

        $finishedAt = $pdo->query("SELECT finished_at FROM scans WHERE id = '{$ids['scan']}'")->fetchColumn();
        assertSame(null, $finishedAt);
    }

    #[Group('store')]
    public function testCompleteScanPrunesSnapshotsBeyondTheProjectsRetentionSetting(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->saveProject($ids['project'], 'Fixture Shop', '/workspace/fixture-shop', ['snapshot_retention' => 2]);
        $lifecycle = self::lifecycle($pdo);

        $insertScan = $pdo->prepare(
            "INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at, finished_at) " .
            "VALUES (:id, :project, 'full', 'complete', 'h', :ts, :ts)",
        );
        $insertSnapshot = $pdo->prepare(
            'INSERT INTO scan_snapshots(scan_id, project_id, scanner_set_hash, config_hash, complete, fact_count, byte_size, payload_json, captured_at) ' .
            "VALUES (:id, :project, 'h', 'c', 1, 0, 2, '{}', :ts)",
        );
        foreach (['old' => '2020-01-01T00:00:00Z', 'mid' => '2021-01-01T00:00:00Z', 'new' => '2022-01-01T00:00:00Z'] as $label => $timestamp) {
            $scanId = 'snap-scan-' . $label;
            $insertScan->execute(['id' => $scanId, 'project' => $ids['project'], 'ts' => $timestamp]);
            $insertSnapshot->execute(['id' => $scanId, 'project' => $ids['project'], 'ts' => $timestamp]);
        }

        // Completing the fixture's own scan runs pruning at retention=2; it
        // owns no snapshot of its own, so this exercises pruning alone.
        $lifecycle->completeScan($ids['project'], $ids['scan']);

        $remaining = $pdo
            ->query("SELECT scan_id FROM scan_snapshots WHERE project_id = '{$ids['project']}' ORDER BY captured_at")
            ->fetchAll(PDO::FETCH_COLUMN);

        assertSame(['snap-scan-mid', 'snap-scan-new'], array_map('strval', $remaining));
        // The pruned snapshot's scan goes with it; the active scan, which owns
        // no snapshot, is not mistaken for one of those.
        $scans = $pdo->query("SELECT id FROM scans WHERE project_id = '{$ids['project']}' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        assertSame(false, in_array('snap-scan-old', $scans, true));
        assertSame(true, in_array($ids['scan'], $scans, true));
    }

    /** With no retention set, completing a scan keeps the default five snapshots. */
    #[Group('store')]
    public function testCompleteScanKeepsTheDefaultRetentionWhenNoneIsSet(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $insertScan = $pdo->prepare(
            "INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at, finished_at) " .
            "VALUES (:id, :project, 'full', 'complete', 'h', :ts, :ts)",
        );
        $insertSnapshot = $pdo->prepare(
            'INSERT INTO scan_snapshots(scan_id, project_id, scanner_set_hash, config_hash, complete, fact_count, byte_size, payload_json, captured_at) ' .
            "VALUES (:id, :project, 'h', 'c', 1, 0, 2, '{}', :ts)",
        );
        foreach (range(1, 7) as $year) {
            $timestamp = sprintf('20%02d-01-01T00:00:00Z', 10 + $year);
            $insertScan->execute(['id' => 'snap-' . $year, 'project' => $ids['project'], 'ts' => $timestamp]);
            $insertSnapshot->execute(['id' => 'snap-' . $year, 'project' => $ids['project'], 'ts' => $timestamp]);
        }

        self::lifecycle($pdo)->completeScan($ids['project'], $ids['scan']);

        $kept = $pdo->query("SELECT scan_id FROM scan_snapshots ORDER BY captured_at")->fetchAll(PDO::FETCH_COLUMN);
        assertSame(GraphRepository::DEFAULT_SNAPSHOT_RETENTION, count($kept));
        assertSame(['snap-3', 'snap-4', 'snap-5', 'snap-6', 'snap-7'], array_map('strval', $kept));
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
