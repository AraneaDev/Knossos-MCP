<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\ProjectDatabaseLocator;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The one place a brief command decides which graph it reads. */
#[Group('cli')]
final class ProjectDatabaseLocatorTest extends KnossosTestCase
{
    private string|false $dataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = getenv('KNOSSOS_DATA_DIR');
        putenv('KNOSSOS_DATA_DIR');
    }

    protected function tearDown(): void
    {
        putenv($this->dataDir === false ? 'KNOSSOS_DATA_DIR' : 'KNOSSOS_DATA_DIR=' . $this->dataDir);
        parent::tearDown();
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

    /** It is the `--db` option that decides, not a database path the context happens to carry. */
    #[Group('cli')]
    public function testAnExplicitDatabaseWins(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-locator-' . bin2hex(random_bytes(4));
        mkdir($root, 0700, true);
        try {
            $locator = new ProjectDatabaseLocator();
            assertSame('/tmp/x.sqlite', $locator->locate($root, ['db' => ['/tmp/x.sqlite']], $this->context('/tmp/x.sqlite')));
            assertSame(realpath($root) . '/.knossos/knossos.sqlite', $locator->locate($root, [], $this->context('/tmp/x.sqlite')));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testTheDataDirectoryVariableWinsOverThePath(): void
    {
        putenv('KNOSSOS_DATA_DIR=/tmp/knossos-data-test');
        $found = (new ProjectDatabaseLocator())->locate('/', [], $this->context());
        assertSame($this->context()->databasePath(), $found);
    }

    /**
     * The installation's graph serves when it holds the project, or when
     * there is no graph nearer to fall back on. A project scanned into its
     * own `.knossos` is not hidden behind a home graph that never saw it.
     */
    #[Group('cli')]
    public function testTheHomeDatabaseServesOnlyAProjectItHoldsUnlessNothingIsNearer(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-locator-' . bin2hex(random_bytes(4));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/turn-brief', $root);
        $root = (string) realpath($root);
        $home = getenv('HOME') . '/.knossos/knossos.sqlite';
        $local = $root . '/.knossos/knossos.sqlite';
        mkdir(dirname($home), 0700);
        mkdir(dirname($local), 0700);
        $runtime = new RuntimeFactory(self::repositoryRoot());
        try {
            // A home graph without the project, and none nearer: the home graph serves.
            $runtime->database($home);
            assertSame($home, (new ProjectDatabaseLocator())->locate($root . '/src', [], $this->context()));
            // A local graph that holds it: the local one serves.
            (new ProjectScanService($runtime->database($local), self::repositoryRoot(), [$root]))->scan($root);
            assertSame($local, (new ProjectDatabaseLocator())->locate($root . '/src', [], $this->context()));
            // Once the home graph holds it too, the home graph serves.
            (new ProjectScanService($runtime->database($home), self::repositoryRoot(), [$root]))->scan($root);
            assertSame($home, (new ProjectDatabaseLocator())->locate($root . '/src', [], $this->context()));
        } finally {
            foreach (glob(dirname($home) . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir(dirname($home));
            $this->removeTempTree($root);
        }
    }
}
