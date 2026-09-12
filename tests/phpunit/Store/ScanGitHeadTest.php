<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\SqliteGraphRepository;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The HEAD is written at scan creation, not at completion: a scan that fails
 * still needs the commit it started from, or the next probe compares against
 * nothing.
 */
final class ScanGitHeadTest extends KnossosTestCase
{
    #[Group('store')]
    public function testItPersistsTheGitHeadWhenGiven(): void
    {
        $pdo = $this->freshTestDatabase();
        $repository = new SqliteGraphRepository($pdo);
        $repository->saveProject('p1', 'Fixture', sys_get_temp_dir());
        $repository->createScan('s1', 'p1', 'full', hash('sha256', 'set'), '3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c');

        self::assertSame('3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c', $pdo->query("SELECT git_head FROM scans WHERE id = 's1'")->fetchColumn());
    }

    #[Group('store')]
    public function testItPersistsNullForAGitlessProject(): void
    {
        $pdo = $this->freshTestDatabase();
        $repository = new SqliteGraphRepository($pdo);
        $repository->saveProject('p1', 'Fixture', sys_get_temp_dir());
        $repository->createScan('s1', 'p1', 'full', hash('sha256', 'set'));

        self::assertNull($pdo->query("SELECT git_head FROM scans WHERE id = 's1'")->fetchColumn());
    }
}
