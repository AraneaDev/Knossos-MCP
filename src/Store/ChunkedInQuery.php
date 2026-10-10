<?php

declare(strict_types=1);

namespace Knossos\Store;

use Generator;
use InvalidArgumentException;
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
     * goes; any other `%` in it is left as written. Each statement binds
     * `$before`, then the chunk, then `$after`, matching the order the
     * placeholders appear in.
     *
     * @param list<mixed> $values
     * @param list<mixed> $before
     * @param list<mixed> $after
     * @param int<1, max> $size
     * @return Generator<int, mixed>
     *
     * @throws InvalidArgumentException when `$sql` does not carry exactly one `%s`
     */
    public static function rows(PDO $pdo, string $sql, array $values, array $before = [], array $after = [], int $mode = PDO::FETCH_ASSOC, int $size = self::SIZE): Generator
    {
        // Replaced as text rather than through sprintf, so a `%` in a quoted
        // constant cannot be read as a conversion and corrupt the statement.
        if (substr_count($sql, '%s') !== 1) {
            throw new InvalidArgumentException('A chunked IN query must carry exactly one %s placeholder list.');
        }
        foreach (array_chunk($values, $size) as $chunk) {
            $statement = $pdo->prepare(str_replace('%s', implode(',', array_fill(0, count($chunk), '?')), $sql));
            $statement->execute([...$before, ...$chunk, ...$after]);
            foreach ($statement->fetchAll($mode) as $row) {
                yield $row;
            }
        }
    }
}
