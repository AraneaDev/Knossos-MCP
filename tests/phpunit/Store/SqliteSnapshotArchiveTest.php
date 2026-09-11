<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use InvalidArgumentException;
use Knossos\Store\SqliteScanLifecycle;
use Knossos\Store\SqliteSnapshotArchive;
use Knossos\Store\SqliteStatementCache;
use Knossos\Store\SqliteTransactions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/** Archiving captures the active graph once per scan, and only when there is a graph to capture. */
final class SqliteSnapshotArchiveTest extends KnossosTestCase
{
    private const TABLES = ['files', 'nodes', 'edges', 'classifications', 'boundaries', 'boundary_memberships', 'diagnostics'];

    #[Group('store')]
    public function testRetentionOutsideZeroToTwentyIsRejected(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $archive = self::archive($pdo);

        assertThrows(static fn() => $archive->archiveActiveSnapshot($ids['project'], 'c', -1), InvalidArgumentException::class);
        assertThrows(static fn() => $archive->archiveActiveSnapshot($ids['project'], 'c', 21), InvalidArgumentException::class);
    }

    #[Group('store')]
    public function testZeroRetentionArchivesNothing(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        self::archive($pdo)->archiveActiveSnapshot($ids['project'], 'c', 0);

        assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM scan_snapshots')->fetchColumn());
    }

    #[Group('store')]
    public function testAProjectWithNoActiveScanArchivesNothing(): void
    {
        [$pdo, , $ids] = $this->storeFixture();

        self::archive($pdo)->archiveActiveSnapshot($ids['project'], 'c', 5);

        assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM scan_snapshots')->fetchColumn());
    }

    #[Group('store')]
    public function testTheActiveScanIsArchivedExactlyOnce(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $archive = self::archive($pdo);

        $archive->archiveActiveSnapshot($ids['project'], 'c', 5);
        $archive->archiveActiveSnapshot($ids['project'], 'c', 5);

        assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM scan_snapshots')->fetchColumn());
        assertSame('1', (string) $pdo->query('SELECT complete FROM scan_snapshots')->fetchColumn());

        // A count of 1 alone is what INSERT OR IGNORE would give even for a
        // row nothing actually populated; check the row itself has real content.
        $row = $pdo->query('SELECT fact_count, payload_json FROM scan_snapshots')->fetch(PDO::FETCH_ASSOC);
        $this->assertGreaterThan(0, (int) $row['fact_count'], 'storeFixture() seeds two nodes and an edge; fact_count must reflect them.');
        $decoded = json_decode(\Knossos\Store\SnapshotPayload::decode((string) $row['payload_json']), true, 512, JSON_THROW_ON_ERROR);
        $nodeIds = array_map(static fn(array $node): string => (string) $node['id'], $decoded['facts']['nodes']);
        sort($nodeIds, SORT_STRING);
        $expected = [$ids['checkout'], $ids['invoice']];
        sort($expected, SORT_STRING);
        assertSame($expected, $nodeIds);
    }

    #[Group('store')]
    public function testRetentionUpToTwentyIsAccepted(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        self::archive($pdo)->archiveActiveSnapshot($ids['project'], 'c', 20);

        assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM scan_snapshots')->fetchColumn());
    }

    /**
     * A table holding more of the project's rows than the ceiling stores the
     * reason in place of the facts; a table holding exactly that many does not.
     */
    #[Group('store')]
    public function testTheRowCeilingIsInclusive(): void
    {
        // storeFixture() holds two nodes, the most rows of any captured table.
        assertSame(1, (int) $this->snapshotWith(maxRowsPerTable: 2)['complete']);

        $stopped = $this->snapshotWith(maxRowsPerTable: 1);
        self::assertStoppedFor('fact_limit', $stopped);
    }

    /** The same for the payload's size: exactly the ceiling fits, one byte less does not. */
    #[Group('store')]
    public function testTheByteCeilingIsInclusive(): void
    {
        $whole = $this->snapshotWith();
        $bytes = (int) $whole['byte_size'];
        assertSame(1, (int) $whole['complete']);

        assertSame(1, (int) $this->snapshotWith(maxPayloadBytes: $bytes)['complete']);
        self::assertStoppedFor('byte_limit', $this->snapshotWith(maxPayloadBytes: $bytes - 1));
    }

    /**
     * The one snapshot row a fresh fixture produces under the given ceilings.
     *
     * @return array<string, mixed>
     */
    private function snapshotWith(int $maxRowsPerTable = 200_000, int $maxPayloadBytes = 50_000_000): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $lifecycle = new SqliteScanLifecycle(new SqliteStatementCache($pdo), new SqliteTransactions($pdo));
        (new SqliteSnapshotArchive($pdo, $lifecycle, self::TABLES, $maxRowsPerTable, $maxPayloadBytes))
            ->archiveActiveSnapshot($ids['project'], 'c', 5);

        return $pdo->query('SELECT complete, fact_count, byte_size, payload_json FROM scan_snapshots')->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * A stopped capture keeps no facts and records why, sized as what it stored.
     *
     * @param array<string, mixed> $row
     */
    private static function assertStoppedFor(string $reason, array $row): void
    {
        $stored = sprintf('{"schema":1,"reason":"%s"}', $reason);
        assertSame(0, (int) $row['complete']);
        assertSame(0, (int) $row['fact_count']);
        assertSame(strlen($stored), (int) $row['byte_size']);
        assertSame($stored, \Knossos\Store\SnapshotPayload::decode((string) $row['payload_json']));
    }

    private static function archive(PDO $pdo): SqliteSnapshotArchive
    {
        $lifecycle = new SqliteScanLifecycle(new SqliteStatementCache($pdo), new SqliteTransactions($pdo));

        return new SqliteSnapshotArchive($pdo, $lifecycle, self::TABLES);
    }
}
