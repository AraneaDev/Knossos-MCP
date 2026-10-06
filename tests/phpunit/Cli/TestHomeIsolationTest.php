<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The CLI reads `~/.knossos/knossos.sqlite` when it exists. On a developer
 * machine it does, and holds the real graph: the suite must never see it.
 */
#[Group('cli')]
final class TestHomeIsolationTest extends KnossosTestCase
{
    public function testTheSuiteRunsUnderAHomeOfItsOwn(): void
    {
        $home = (string) getenv('HOME');
        self::assertStringStartsWith(rtrim(sys_get_temp_dir(), '/') . '/knossos-test-home-', $home);
        self::assertDirectoryExists($home);
        self::assertFileDoesNotExist($home . '/.knossos/knossos.sqlite');
    }
}
