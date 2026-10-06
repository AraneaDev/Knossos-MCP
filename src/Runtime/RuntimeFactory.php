<?php

declare(strict_types=1);

namespace Knossos\Runtime;

use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use PDO;
use RuntimeException;

/**
 * Locates and opens the runtime's shared resources.
 *
 * One place that knows where the graph database lives and where the packaged
 * workers are, so the CLI, the stdio server, and the HTTP router cannot disagree
 * about either.
 */
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
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create data directory: %s', $directory));
        }
        $pdo = SqliteConnection::open($path);
        (new MigrationRunner($pdo, $this->installationRoot . '/migrations'))->migrate();

        return $pdo;
    }

    /**
     * `KNOSSOS_DATA_DIR/knossos.sqlite`, else the installation's graph in
     * `~/.knossos` when there is one, else `<cwd>/.knossos/knossos.sqlite`.
     *
     * The working-directory fallback means a CLI run from another project addresses a
     * different graph; set KNOSSOS_DATA_DIR to make one installation share one.
     */
    public function defaultDatabasePath(): string
    {
        $directory = getenv('KNOSSOS_DATA_DIR');
        if (!is_string($directory) || $directory === '') {
            $home = self::homeDatabasePath();
            if ($home !== null) {
                return $home;
            }
            $cwd = getcwd();
            if ($cwd === false) {
                throw new RuntimeException('Unable to determine the current working directory; set KNOSSOS_DATA_DIR or pass --db=PATH.');
            }
            $directory = $cwd . '/.knossos';
        }

        return rtrim($directory, '/') . '/knossos.sqlite';
    }

    /**
     * The installation's graph, `~/.knossos/knossos.sqlite`, when it exists:
     * `tools/install` puts it there and pins the server and hooks to it, so a
     * shell without KNOSSOS_DATA_DIR reads the same graph. Null when there is
     * no such file, so a fresh checkout keeps the working-directory fallback.
     */
    public static function homeDatabasePath(): ?string
    {
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            return null;
        }
        $path = rtrim($home, '/') . '/.knossos/knossos.sqlite';

        return is_file($path) ? $path : null;
    }

    /** Where Knossos is installed, used to locate migrations and the scanner workers. */
    public function installationRoot(): string
    {
        return $this->installationRoot;
    }
}
