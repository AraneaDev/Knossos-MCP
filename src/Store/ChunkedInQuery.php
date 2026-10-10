<?php

declare(strict_types=1);

namespace Knossos\Store;

use Generator;
use PDO;

/**
 * Runs one `IN (...)` query over a list of values too long to bind at once.
 *
 * SQLite caps the variables one statement may bind, so every lookup keyed by
 * an arbitrary set of ids splits the set into chunks and runs the statement
 * once per chunk. The split, the placeholder list, the prepare and the execute
 * were written out by hand at each such lookup; this is that sequence once.
 *
 * Rows come back in chunk order, and within a chunk in the order the statement
 * returns them, which is what the hand-written loops produced. A generator, so
 * a caller that stops at the first row leaves the remaining chunks unqueried.
 */
final readonly class ChunkedInQuery
{
    /** Values bound per statement: far below SQLite's bound-variable limit. */
    public const SIZE = 500;

    /**
     * Yield every row `$sql` returns across the chunks of `$values`.
     *
     * `$sql` carries exactly one `%s`, where the chunk's placeholder list
     * goes, so it must not contain any other `%`. Each statement binds
     * `$before`, then the chunk, then `$after`, matching the order the
     * placeholders appear in.
     *
     * @param list<mixed> $values
     * @param list<mixed> $before
     * @param list<mixed> $after
     * @param int<1, max> $size
     * @return Generator<int, array<int|string, mixed>>
     */
    public static function rows(PDO $pdo, string $sql, array $values, array $before = [], array $after = [], int $mode = PDO::FETCH_ASSOC, int $size = self::SIZE): Generator
    {
        foreach (array_chunk($values, $size) as $chunk) {
            $statement = $pdo->prepare(sprintf($sql, implode(',', array_fill(0, count($chunk), '?'))));
            $statement->execute([...$before, ...$chunk, ...$after]);
            foreach ($statement->fetchAll($mode) as $row) {
                yield $row;
            }
        }
    }
}
