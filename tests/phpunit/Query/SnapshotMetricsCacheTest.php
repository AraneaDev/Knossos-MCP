<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\SnapshotMetricsCache;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A retained snapshot's trend figures are computed once and kept, and a kept
 * row never answers for anything but the archive and the code it came from.
 */
final class SnapshotMetricsCacheTest extends KnossosTestCase
{
    /**
     * Two snapshots: the fixture's, retained once a second scan completes, and
     * that second, active one.
     *
     * @return array{0: PDO, 1: string, 2: string, 3: string}
     */
    private function twoSnapshots(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $next = StableId::scan($ids['project'], 'trend-next');
        $repository->createScan($next, $ids['project'], 'incremental', hash('sha256', 'scanner-next'));
        $repository->completeScan($ids['project'], $next);

        return [$pdo, $ids['project'], $ids['scan'], $next];
    }

    /** The trend series with the fields every snapshot reports, in order. */
    private static function series(PDO $pdo, string $project): array
    {
        return array_map(
            static fn(array $s): array => [$s['scan_id'], $s['active'], $s['counts'], $s['metrics']],
            (new ArchitectureQueryService($pdo))->architectureTrends($project, 5)->data['series'],
        );
    }

    #[Group('query')]
    public function testARetainedSnapshotsFiguresAreComputedOnceAndAnswerAfterwards(): void
    {
        [$pdo, $project, $retained, $active] = $this->twoSnapshots();
        $first = self::series($pdo, $project);
        assertSame([$retained, $active], array_column($first, 0));
        // Only the retained archive is kept: the active snapshot is the live tables.
        assertSame([$retained], $pdo->query('SELECT scan_id FROM snapshot_metrics')->fetchAll(PDO::FETCH_COLUMN));

        // The archive is never decoded again: with its payload unreadable, the kept figures still answer.
        $pdo->exec("UPDATE scan_snapshots SET payload_json = 'unreadable' WHERE scan_id = '" . $retained . "'");
        assertSame($first, self::series($pdo, $project));
    }

    #[Group('query')]
    public function testAKeptRowAnswersOnlyForItsArchiveAndItsCode(): void
    {
        [$pdo, $project, $retained] = $this->twoSnapshots();
        self::series($pdo, $project);
        $archive = $pdo->query("SELECT captured_at, byte_size FROM scan_snapshots WHERE scan_id = '" . $retained . "'")->fetch();
        $captured = (string) $archive['captured_at'];
        $size = (int) $archive['byte_size'];
        $cache = new SnapshotMetricsCache($pdo);

        assertSame(true, $cache->get($retained, $captured, $size) !== null);
        assertSame(null, $cache->get($retained, $captured . 'x', $size));
        assertSame(null, $cache->get($retained, $captured, $size + 1));
        assertSame(null, (new SnapshotMetricsCache($pdo, 'other code'))->get($retained, $captured, $size));
        assertSame(null, $cache->get('scan_unknown', $captured, $size));

        // A row that does not decode is a miss, never an answer.
        $pdo->exec("UPDATE snapshot_metrics SET payload_json = '{\"counts\":1}'");
        assertSame(null, $cache->get($retained, $captured, $size));
    }

    #[Group('query')]
    public function testChangedCodeRecomputesRatherThanServingOldFigures(): void
    {
        [$pdo, $project, $retained] = $this->twoSnapshots();
        $first = self::series($pdo, $project);
        $pdo->exec("UPDATE snapshot_metrics SET fingerprint = 'older code', payload_json = '{\"counts\":{\"components\":999},\"metrics\":{\"cycles\":999}}'");
        assertSame($first, self::series($pdo, $project));
        assertSame(SnapshotMetricsCache::fingerprint(), $pdo->query("SELECT fingerprint FROM snapshot_metrics WHERE scan_id = '" . $retained . "'")->fetchColumn());
        assertSame(1, preg_match('/^[0-9a-f]{64}$/', SnapshotMetricsCache::fingerprint()));
    }

    #[Group('query')]
    public function testDeletingTheArchiveDeletesItsFigures(): void
    {
        [$pdo, $project, $retained] = $this->twoSnapshots();
        self::series($pdo, $project);
        $pdo->exec("DELETE FROM scan_snapshots WHERE scan_id = '" . $retained . "'");
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM snapshot_metrics')->fetchColumn());
    }

    /**
     * A store while a scan holds the write lock neither waits out the busy
     * timeout nor fails the read: it is dropped, and the timeout is restored.
     */
    #[Group('query')]
    public function testAStoreNeverWaitsForALockedDatabase(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-metrics-' . bin2hex(random_bytes(4));
        mkdir($root);
        try {
            $path = $root . '/knossos.sqlite';
            $pdo = SqliteConnection::open($path);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $writer = SqliteConnection::open($path);
            $writer->exec('BEGIN IMMEDIATE');
            $started = hrtime(true);
            (new SnapshotMetricsCache($pdo))->put('scan_x', '2026-01-01T00:00:00Z', 1, ['counts' => [], 'metrics' => []]);
            assertSame(true, hrtime(true) - $started < 2_000_000_000);
            assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
            $writer->exec('ROLLBACK');
            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM snapshot_metrics')->fetchColumn());
            unset($writer, $pdo);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
