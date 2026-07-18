<?php

declare(strict_types=1);

namespace Knossos\Runtime;

use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use PDO;
use RuntimeException;

final class RuntimeFactory
{
    public function __construct(private readonly string $installationRoot) {}

    public function database(?string $path = null): PDO
    {
        $path ??= $this->defaultDatabasePath();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create data directory: %s', $directory));
        }
        $pdo = SqliteConnection::open($path);
        (new MigrationRunner($pdo, $this->installationRoot . '/migrations'))->migrate();

        return $pdo;
    }

    public function defaultDatabasePath(): string
    {
        $directory = getenv('KNOSSOS_DATA_DIR');
        if (!is_string($directory) || $directory === '') {
            $directory = getcwd() . '/.knossos';
        }

        return rtrim($directory, '/') . '/knossos.sqlite';
    }

    public function installationRoot(): string
    {
        return $this->installationRoot;
    }
}
