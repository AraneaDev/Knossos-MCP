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

    private static function archive(PDO $pdo): SqliteSnapshotArchive
    {
        $lifecycle = new SqliteScanLifecycle(new SqliteStatementCache($pdo), new SqliteTransactions($pdo));

        return new SqliteSnapshotArchive($pdo, $lifecycle, self::TABLES);
    }
}
