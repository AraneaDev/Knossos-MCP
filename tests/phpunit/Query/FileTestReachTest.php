<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ChangeImpactQueryService;
use Knossos\Query\FileTestReach;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertSame;

/**
 * How many tests reach each changed file, one file at a time: a count only
 * where it is known, within a cap on files and a deadline across them.
 */
final class FileTestReachTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    #[Group('query')]
    public function testEachFileIsCountedOnItsOwnAndAFileWithoutComponentsIsLeftOut(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            file_put_contents($root . '/src/Core/Alone.php', "<?php\nnamespace App;\nfinal class Alone {}\n");
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $reach = (new FileTestReach($pdo))->reach($projectId, ['src/Core/Greeter.php', 'src/Core/Alone.php', 'composer.json', 'tests/GreeterTest.php']);
            // The greeter's test reaches it; nothing reaches the lone class; a test reaches itself.
            assertSame(['src/Core/Greeter.php' => 1, 'src/Core/Alone.php' => 0, 'tests/GreeterTest.php' => 1], $reach);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testFilesPastTheCapOrTheDeadlineAreNotCounted(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $files = array_merge(['src/Core/Greeter.php'], array_fill(0, FileTestReach::MAX_FILES, 'src/Edge/Caller.php'), ['tests/GreeterTest.php']);
            assertSame(false, array_key_exists('tests/GreeterTest.php', (new FileTestReach($pdo))->reach($projectId, $files)));
            // A clock already past the deadline after the first file: only that one is counted.
            $ticks = 0;
            $clock = static function () use (&$ticks): float {
                return $ticks++ < 2 ? 0.0 : (float) FileTestReach::DEADLINE_MS;
            };
            assertSame(['src/Core/Greeter.php' => 1], (new FileTestReach($pdo, $clock))->reach($projectId, ['src/Core/Greeter.php', 'src/Edge/Caller.php']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheTestsOfManyFilesKeepToOneDeadlineAndSayWhenTheyWereCut(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $queries = ArchitectureQueryService::forDatabase($pdo);
            // Two searches: fifty copies of the caller, then the greeter.
            $files = [...array_fill(0, ChangeImpactQueryService::MAX_FILES, 'src/Edge/Caller.php'), 'src/Core/Greeter.php'];
            $all = FileTestReach::testsOf($queries, $projectId, $files, 10);
            assertSame(false, $all['truncated']);
            assertContains('tests/GreeterTest.php', array_column($all['tests'], 'path'));
            // A clock past the deadline once the first search is done: the second is never asked, and the list says it was cut.
            $ticks = 0;
            $clock = static function () use (&$ticks): float {
                return $ticks++ < 2 ? 0.0 : (float) FileTestReach::TESTS_DEADLINE_MS;
            };
            $cut = FileTestReach::testsOf($queries, $projectId, $files, 10, $clock);
            assertSame(true, $cut['truncated']);
            assertSame(3, $ticks);
            // More tests than asked for is a cut list too.
            assertSame(true, FileTestReach::testsOf($queries, $projectId, ['src/Core/Greeter.php', 'tests/GreeterTest.php', 'src/Edge/Caller.php'], 0)['truncated']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
