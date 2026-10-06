<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Without KNOSSOS_DATA_DIR the CLI reads the installation's graph in ~/.knossos when there is one. */
#[Group('cli')]
final class DefaultDatabasePathTest extends KnossosTestCase
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
        @unlink(getenv('HOME') . '/.knossos/knossos.sqlite');
        @rmdir(getenv('HOME') . '/.knossos');
        parent::tearDown();
    }

    public function testTheHomeDatabaseIsUsedWhenItExists(): void
    {
        mkdir(getenv('HOME') . '/.knossos', 0700);
        touch(getenv('HOME') . '/.knossos/knossos.sqlite');
        self::assertSame(getenv('HOME') . '/.knossos/knossos.sqlite', (new RuntimeFactory(self::repositoryRoot()))->defaultDatabasePath());
    }

    public function testWithoutAHomeDatabaseTheWorkingDirectoryStillServes(): void
    {
        self::assertSame(getcwd() . '/.knossos/knossos.sqlite', (new RuntimeFactory(self::repositoryRoot()))->defaultDatabasePath());
    }

    public function testTheDataDirectoryVariableWinsOverTheHomeDatabase(): void
    {
        mkdir(getenv('HOME') . '/.knossos', 0700);
        touch(getenv('HOME') . '/.knossos/knossos.sqlite');
        putenv('KNOSSOS_DATA_DIR=/tmp/knossos-pinned');
        self::assertSame('/tmp/knossos-pinned/knossos.sqlite', (new RuntimeFactory(self::repositoryRoot()))->defaultDatabasePath());
    }
}
