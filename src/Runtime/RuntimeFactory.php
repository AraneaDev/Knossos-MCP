<?php

declare(strict_types=1);

namespace Knossos\Runtime;

use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use PDO;
use RuntimeException;

final class RuntimeFactory
{
    /** @param string $installationRoot the Knossos install, holding migrations and the workers */
    public function __construct(private readonly string $installationRoot) {}

    /**
     * Open the graph database, creating its directory and applying migrations.
     *
     * @param string|null $path defaults to {@see defaultDatabasePath()}
     * @throws \RuntimeException when the data directory cannot be created
     */
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

    /**
     * `KNOSSOS_DATA_DIR/knossos.sqlite`, else `<cwd>/.knossos/knossos.sqlite`.
     *
     * The working-directory fallback means a CLI run from another project addresses a
     * different graph; set KNOSSOS_DATA_DIR to make one installation share one.
     */
    public function defaultDatabasePath(): string
    {
        $directory = getenv('KNOSSOS_DATA_DIR');
        if (!is_string($directory) || $directory === '') {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new RuntimeException('Unable to determine the current working directory; set KNOSSOS_DATA_DIR or pass --db=PATH.');
            }
            $directory = $cwd . '/.knossos';
        }

        return rtrim($directory, '/') . '/knossos.sqlite';
    }

    /** Where Knossos is installed, used to locate migrations and the scanner workers. */
    public function installationRoot(): string
    {
        return $this->installationRoot;
    }
}
