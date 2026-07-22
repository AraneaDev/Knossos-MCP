<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use Knossos\Runtime\RuntimeFactory;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('runtime-factory')]
final class RuntimeFactoryTest extends TestCase
{
    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private string $tempDir;

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            // Remove any WAL sidecars that may exist.
            foreach (glob($this->tempDir . '/*-wal') ?: [] as $f) {
                @unlink($f);
            }
            foreach (glob($this->tempDir . '/*-shm') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
    }

    // ----- helpers -----

    private function freshTempDir(): string
    {
        $this->tempDir = sys_get_temp_dir() . '/knossos-runtime-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        return $this->tempDir;
    }

    // ----- installationRoot() -----

    public function testInstallationRootExposesConstructorArgumentVerbatim(): void
    {
        $factory = new RuntimeFactory('/opt/knossos');

        assertSame('/opt/knossos', $factory->installationRoot());
    }

    public function testInstallationRootAcceptsArbitraryPaths(): void
    {
        $factory = new RuntimeFactory('/var/folders/abc');

        assertSame('/var/folders/abc', $factory->installationRoot());
    }

    // ----- defaultDatabasePath() -----

    public function testDefaultDatabasePathUsesKnossosDataDirEnvVarWhenProvided(): void
    {
        $previous = getenv('KNOSSOS_DATA_DIR');
        putenv('KNOSSOS_DATA_DIR=/custom/data');

        try {
            $factory = new RuntimeFactory('/opt/knossos');

            assertSame('/custom/data/knossos.sqlite', $factory->defaultDatabasePath());
        } finally {
            if ($previous === false) {
                putenv('KNOSSOS_DATA_DIR');
            } else {
                putenv('KNOSSOS_DATA_DIR=' . $previous);
            }
        }
    }

    public function testDefaultDatabasePathFallsBackToCwdWhenEnvVarEmpty(): void
    {
        $previous = getenv('KNOSSOS_DATA_DIR');
        putenv('KNOSSOS_DATA_DIR=');

        try {
            $factory = new RuntimeFactory('/opt/knossos');

            assertSame(rtrim(getcwd(), '/') . '/.knossos/knossos.sqlite', $factory->defaultDatabasePath());
        } finally {
            if ($previous === false) {
                putenv('KNOSSOS_DATA_DIR');
            } else {
                putenv('KNOSSOS_DATA_DIR=' . $previous);
            }
        }
    }

    public function testDefaultDatabasePathStripsTrailingSlashesFromEnvVar(): void
    {
        $previous = getenv('KNOSSOS_DATA_DIR');
        putenv('KNOSSOS_DATA_DIR=/custom/data///');

        try {
            $factory = new RuntimeFactory('/opt/knossos');

            assertSame('/custom/data/knossos.sqlite', $factory->defaultDatabasePath());
        } finally {
            if ($previous === false) {
                putenv('KNOSSOS_DATA_DIR');
            } else {
                putenv('KNOSSOS_DATA_DIR=' . $previous);
            }
        }
    }

    // ----- database() -----

    public function testDatabaseOpenWithExplicitPathAppliesMigrationsAndReturnsPdo(): void
    {
        $root = $this->freshTempDir();
        $factory = new RuntimeFactory(self::projectRoot());

        $pdo = $factory->database($root . '/runtime.sqlite');

        $this->assertNotNull($pdo);
        assertSame(true, $pdo instanceof PDO);
        // schema_migrations table was created by the runner
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='schema_migrations'")->fetchColumn());
        // projects table is one of the migrated targets
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='projects'")->fetchColumn());
    }

    public function testDatabaseOpenCreatesParentDirectoryWhenMissing(): void
    {
        $root = $this->freshTempDir();
        $deepPath = $root . '/nested/path/runtime.sqlite';

        $pdo = (new RuntimeFactory(self::projectRoot()))->database($deepPath);

        $this->assertNotNull($pdo);
        assertSame(true, is_file($deepPath));
        assertSame(true, is_dir(dirname($deepPath)));
    }

    public function testDatabaseOpenIsIdempotentWhenCalledTwice(): void
    {
        $root = $this->freshTempDir();
        $factory = new RuntimeFactory(self::projectRoot());

        $factory->database($root . '/runtime.sqlite');

        // Second call should be a no-op for migrations (already applied).
        $pdo = $factory->database($root . '/runtime.sqlite');
        $this->assertNotNull($pdo);
    }

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(RuntimeFactory::class);

        $this->assertTrue($reflection->isFinal());
    }
}