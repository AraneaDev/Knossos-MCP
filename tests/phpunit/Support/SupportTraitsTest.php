<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class SupportTraitsTest extends KnossosTestCase
{
    public function testFreshTestDatabaseIsMigratedAndEmpty(): void
    {
        $pdo = $this->freshTestDatabase();
        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
    }

    public function testRunFixtureCommandOutputCapturesExitCodeAndStreams(): void
    {
        [$exit, $stdout] = $this->runFixtureCommandOutput([PHP_BINARY, '-r', 'echo "hi";']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('hi', $stdout);
    }
}
