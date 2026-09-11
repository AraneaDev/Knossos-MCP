<?php

declare(strict_types=1);

namespace Knossos\Store;

use PDO;
use PDOStatement;

/**
 * One connection and the statements prepared against it.
 *
 * A scan replays the same handful of inserts thousands of times, so each is
 * prepared once. One instance is shared by every store class built for a
 * repository, which keeps reuse exactly as it was when they were one class.
 */
final class SqliteStatementCache
{
    /** @var array<string, PDOStatement> */
    private array $statements = [];

    public function __construct(private readonly PDO $pdo) {}

    /** A cached prepared statement, since a scan replays the same inserts repeatedly. */
    public function prepare(string $sql): PDOStatement
    {
        return $this->statements[$sql] ??= $this->pdo->prepare($sql);
    }

    /** The connection, for the statements that are prepared once and deliberately not cached. */
    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
