<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use InvalidArgumentException;
use Knossos\Store\SqliteGraphReader;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Reads over the stored graph, asserted on the fixture rather than through a scan. */
final class SqliteGraphReaderTest extends KnossosTestCase
{
    #[Group('store')]
    public function testACanonicalMatchRanksAheadOfADisplayMatch(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $other = StableId::symbol($ids['project'], 'php', 'class', 'Vendor\\Checkout');
        $repository->saveNode($other, $ids['project'], 'php', 'class', 'Vendor\\Checkout', 'App\\Checkout', null, $ids['file'], 30, 31, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);

        $rows = (new SqliteGraphReader($pdo))->findNodesByName($ids['project'], 'App\\Checkout', 20);

        assertSame(['App\\Checkout', 'Vendor\\Checkout'], array_column($rows, 'canonical_name'));
    }

    #[Group('store')]
    public function testFindNodesByNameHonoursItsLimit(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $other = StableId::symbol($ids['project'], 'php', 'class', 'Vendor\\Checkout');
        $repository->saveNode($other, $ids['project'], 'php', 'class', 'Vendor\\Checkout', 'App\\Checkout', null, $ids['file'], 30, 31, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);

        assertSame(1, count((new SqliteGraphReader($pdo))->findNodesByName($ids['project'], 'App\\Checkout', 1)));
    }

    #[Group('store')]
    public function testOutgoingAndIncomingReadOppositeEndsOfTheSameEdge(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $reader = new SqliteGraphReader($pdo);

        assertSame($ids['invoice'], $reader->outgoing($ids['project'], $ids['checkout'], null, 100)[0]['target_id']);
        assertSame($ids['checkout'], $reader->incoming($ids['project'], $ids['invoice'], null, 100)[0]['source_id']);
        assertSame([], $reader->incoming($ids['project'], $ids['checkout'], null, 100));
    }

    #[Group('store')]
    public function testAKindFilterExcludesOtherEdgeKinds(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $reader = new SqliteGraphReader($pdo);

        assertSame(1, count($reader->outgoing($ids['project'], $ids['checkout'], 'calls', 100)));
        assertSame([], $reader->outgoing($ids['project'], $ids['checkout'], 'extends', 100));
    }

    #[Group('store')]
    public function testALimitOutsideOneToAThousandIsRejected(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $reader = new SqliteGraphReader($pdo);

        assertThrows(static fn() => $reader->outgoing($ids['project'], $ids['checkout'], null, 0), InvalidArgumentException::class);
        assertThrows(static fn() => $reader->outgoing($ids['project'], $ids['checkout'], null, 1001), InvalidArgumentException::class);
        assertSame(1, count($reader->outgoing($ids['project'], $ids['checkout'], null, 1000)));
        assertSame(1, count($reader->outgoing($ids['project'], $ids['checkout'], null, 1)));
    }
}
