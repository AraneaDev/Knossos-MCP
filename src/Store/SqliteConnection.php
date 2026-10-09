<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;
use PDO;

/**
 * Opens SQLite with the settings the rest of the store assumes.
 *
 * WAL so a scan's writes do not block concurrent reads and bounded so its file
 * does not keep a peak size for good, enforced foreign keys so a partial graph
 * cannot survive, and exceptions rather than silent false returns. Centralised because a connection opened without these behaves subtly
 * differently, and the difference only shows up under concurrency.
 */
final class SqliteConnection
{
    private function __construct() {}
    /** Open SQLite with WAL, enforced foreign keys, and exceptions on error. */

    public static function open(string $path): PDO
    {
        if ($path === '') {
            throw new InvalidArgumentException('SQLite path must not be empty.');
        }

        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (!is_dir($directory)) {
                throw new InvalidArgumentException(sprintf('SQLite directory does not exist: %s', $directory));
            }
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        $pdo = self::connected(PDO::class, 'sqlite:' . $path, $options);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            // A write-ahead log keeps whatever size its busiest transaction
            // needed, for good: a full scan of a large project left 124 MB of
            // allocated log holding two live pages, and only maintenance run by
            // hand shrank it. This truncates the file back after a checkpoint.
            $pdo->exec('PRAGMA journal_size_limit = ' . (64 * 1024 * 1024));
        }

        return $pdo;
    }

    /**
     * The connection as the driver's own class where the runtime has one.
     *
     * From PHP 8.4, `PDO::connect()` returns the driver subclass
     * (`Pdo\Sqlite`), which can read a stored value a piece at a time
     * (`openBlob`); the snapshot reader uses that where it exists. Detected
     * through a callable built from `$driver` so the same code runs, and
     * analyses, on 8.3 (no `connect`) and on 8.4 and later.
     *
     * @param array<int, mixed> $options
     */
    private static function connected(string $driver, string $dsn, array $options): PDO
    {
        $connect = [$driver, 'connect'];
        $pdo = is_callable($connect) ? $connect($dsn, null, null, $options) : new PDO($dsn, options: $options);
        if (!$pdo instanceof PDO) {
            throw new InvalidArgumentException(sprintf('SQLite could not be opened: %s', $dsn));
        }

        return $pdo;
    }
}
