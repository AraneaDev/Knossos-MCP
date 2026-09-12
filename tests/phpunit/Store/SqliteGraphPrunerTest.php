<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\SqliteGraphPruner;
use Knossos\Store\SqliteStatementCache;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * An incremental rescan deletes only what it no longer produced and restamps
 * the rest. Getting the difference wrong either leaves stale rows behind or
 * deletes live ones, and both look like a successful scan.
 */
final class SqliteGraphPrunerTest extends KnossosTestCase
{
    #[Group('store')]
    public function testExistingIdsAreReadPerTableAndMembershipsByPair(): void
    {
        [$pdo, , $ids] = $this->storeFixture();

        $existing = (new SqliteGraphPruner(new SqliteStatementCache($pdo)))->existingGraphIds($ids['project']);

        assertSame(true, isset($existing['nodes'][$ids['checkout']]));
        assertSame(true, isset($existing['nodes'][$ids['invoice']]));
        assertSame(true, isset($existing['files'][$ids['file']]));
        assertSame([], $existing['boundary_memberships']);
    }

    #[Group('store')]
    public function testPruneDeletesOnlyWhatTheScanNoLongerProduced(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $pruner = new SqliteGraphPruner(new SqliteStatementCache($pdo));
        $existing = $pruner->existingGraphIds($ids['project']);
        $desired = $existing;
        unset($desired['edges']);

        $pruner->pruneGraph($ids['project'], $existing, $desired);

        assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());
        assertSame('2', (string) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
    }

    #[Group('store')]
    public function testPruningNothingChangedDeletesNothing(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $pruner = new SqliteGraphPruner(new SqliteStatementCache($pdo));
        $existing = $pruner->existingGraphIds($ids['project']);

        $pruner->pruneGraph($ids['project'], $existing, $existing);

        assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());
    }

    #[Group('store')]
    public function testStampingMovesEveryRowOntoTheConfirmingScan(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->createScan('scan_new', $ids['project'], 'full', 'h');

        (new SqliteGraphPruner(new SqliteStatementCache($pdo)))->stampGraphScan($ids['project'], 'scan_new');

        foreach (['files', 'nodes', 'edges'] as $table) {
            assertSame('0', (string) $pdo->query(sprintf("SELECT COUNT(*) FROM %s WHERE last_scan_id <> 'scan_new'", $table))->fetchColumn());
        }
    }
}
