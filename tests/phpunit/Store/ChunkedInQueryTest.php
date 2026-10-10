<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use InvalidArgumentException;
use Knossos\Store\ChunkedInQuery;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

final class ChunkedInQueryTest extends KnossosTestCase
{
    #[Group('store')]
    public function testRowsSpanChunksInChunkOrderWithTheBoundValuesAroundEachChunk(): void
    {
        $pdo = self::numbers(7);

        $rows = iterator_to_array(ChunkedInQuery::rows(
            $pdo,
            'SELECT n FROM t WHERE n >= ? AND n IN (%s) AND n <= ? ORDER BY n DESC',
            [1, 2, 3, 4, 5, 6, 7],
            [2],
            [6],
            PDO::FETCH_COLUMN,
            3,
        ), false);

        // Chunks [1,2,3], [4,5,6], [7]: each ordered on its own, in chunk order.
        self::assertSame([3, 2, 6, 5, 4], array_map('intval', $rows));
    }

    /** sprintf would read `%d` in a quoted constant as a conversion and corrupt the statement. */
    #[Group('store')]
    public function testAPercentSignOutsideThePlaceholderListIsKept(): void
    {
        $pdo = self::numbers(2);

        $rows = iterator_to_array(ChunkedInQuery::rows($pdo, "SELECT '100%d' AS label FROM t WHERE n IN (%s)", [1]), false);

        self::assertSame([['label' => '100%d']], $rows);
    }

    #[Group('store')]
    public function testAStatementWithoutExactlyOnePlaceholderListIsRefused(): void
    {
        $pdo = self::numbers(1);

        foreach (['SELECT n FROM t', 'SELECT n FROM t WHERE n IN (%s) OR n IN (%s)'] as $sql) {
            try {
                iterator_to_array(ChunkedInQuery::rows($pdo, $sql, [1]));
                self::fail('A statement must carry exactly one %s.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('A chunked IN query must carry exactly one %s placeholder list.', $error->getMessage());
            }
        }
    }

    /** An in-memory table `t` holding the numbers 1 to `$count`. */
    private static function numbers(int $count): PDO
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('CREATE TABLE t (n INTEGER)');
        for ($n = 1; $n <= $count; ++$n) {
            $pdo->exec('INSERT INTO t VALUES (' . $n . ')');
        }

        return $pdo;
    }
}
