<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteStatementCache;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A scan replays the same few inserts thousands of times, so preparing each
 * statement once is what keeps it fast. These pin the reuse directly, without
 * a scan and without reflection.
 */
final class SqliteStatementCacheTest extends KnossosTestCase
{
    #[Group('store')]
    public function testTheSameSqlReturnsTheSamePreparedStatement(): void
    {
        $cache = new SqliteStatementCache(SqliteConnection::open(':memory:'));

        assertSame(true, $cache->prepare('SELECT 1') === $cache->prepare('SELECT 1'));
    }

    #[Group('store')]
    public function testDifferentSqlIsPreparedSeparately(): void
    {
        $cache = new SqliteStatementCache(SqliteConnection::open(':memory:'));

        assertSame(false, $cache->prepare('SELECT 1') === $cache->prepare('SELECT 2'));
    }

    #[Group('store')]
    public function testItExposesTheConnectionItPreparesAgainst(): void
    {
        $pdo = SqliteConnection::open(':memory:');

        assertSame(true, (new SqliteStatementCache($pdo))->pdo() === $pdo);
    }
}
