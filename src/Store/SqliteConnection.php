<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;
use PDO;

final class SqliteConnection
{
    private function __construct() {}

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

        $pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        }

        return $pdo;
    }
}
