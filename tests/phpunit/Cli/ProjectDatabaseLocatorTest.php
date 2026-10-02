<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\ProjectDatabaseLocator;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The one place a brief command decides which graph it reads. */
#[Group('cli')]
final class ProjectDatabaseLocatorTest extends KnossosTestCase
{
    private string|false $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = getenv('KNOSSOS_DATA_DIR');
        putenv('KNOSSOS_DATA_DIR');
    }

    protected function tearDown(): void
    {
        putenv($this->dataDir === false ? 'KNOSSOS_DATA_DIR' : 'KNOSSOS_DATA_DIR=' . $this->dataDir);
    }

    private function context(?string $db = null): CliCommandContext
    {
        return new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $db);
    }

    #[Group('cli')]
    public function testASubdirectoryResolvesToTheProjectDatabaseAbove(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-locator-' . bin2hex(random_bytes(4));
        mkdir($root . '/.knossos', 0700, true);
        mkdir($root . '/src/deep', 0700, true);
        touch($root . '/.knossos/knossos.sqlite');
        try {
            $found = (new ProjectDatabaseLocator())->locate($root . '/src/deep', [], $this->context());
            assertSame(realpath($root) . '/.knossos/knossos.sqlite', $found);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testAnUnscannedDirectoryGetsItsOwnNonexistentPath(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-locator-' . bin2hex(random_bytes(4));
        mkdir($root, 0700, true);
        try {
            $found = (new ProjectDatabaseLocator())->locate($root, [], $this->context());
            assertSame(realpath($root) . '/.knossos/knossos.sqlite', $found);
            assertSame(false, is_file($found));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testAnExplicitDatabaseWins(): void
    {
        $found = (new ProjectDatabaseLocator())->locate('/', ['db' => ['/tmp/x.sqlite']], $this->context('/tmp/x.sqlite'));
        assertSame('/tmp/x.sqlite', $found);
    }

    #[Group('cli')]
    public function testTheDataDirectoryVariableWinsOverThePath(): void
    {
        putenv('KNOSSOS_DATA_DIR=/tmp/knossos-data-test');
        $found = (new ProjectDatabaseLocator())->locate('/', [], $this->context());
        assertSame($this->context()->databasePath(), $found);
    }
}
