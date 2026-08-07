<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use PDO;
use PDOStatement;

/**
 * A PDO statement that records how many rows were actually materialised.
 *
 * Installed through PDO::ATTR_STATEMENT_CLASS so a test can tell a streamed
 * traversal from one that called fetchAll(): both produce the same envelope
 * when the deadline is already past, and only the row count separates them.
 */
final class RowCountingStatement extends PDOStatement
{
    /** Rows handed to the caller since the last {@see reset}, across every statement. */
    public static int $rows = 0;

    /** PDO constructs these itself; the constructor exists only to satisfy the attribute. */
    protected function __construct() {}

    /** Forget the rows counted so far, so one test can measure several calls. */
    public static function reset(): void
    {
        self::$rows = 0;
    }

    /** Count one streamed row. */
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if ($row !== false) {
            ++self::$rows;
        }

        return $row;
    }

    /**
     * Count every row of a materialised result set.
     *
     * @return list<mixed>
     */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        self::$rows += count($rows);

        return $rows;
    }
}
