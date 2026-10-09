<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Git\GitProcessRunnerInterface;
use Knossos\Query\BlastRadiusService;
use Knossos\Query\ChurnService;
use Knossos\Query\PaneQueries;
use Knossos\Query\PathBetweenService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * The architecture pane's wider reads and its one write: churn hotspots, a
 * component's blast radius in rings, the routes between two components, and
 * a note on a component, previewed until confirmed.
 */
final class PaneQueriesTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    /** @param list<string> $args */
    private function git(string $root, array $args): void
    {
        $this->runFixtureCommand(['git', '-C', $root, '-c', 'user.name=Knossos Test', '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false', ...$args]);
    }

    /** The fixture with a test of the caller too, scanned again: the caller is then reached by a test. */
    private function withCallerTest(PDO $pdo, string $root): void
    {
        file_put_contents($root . '/tests/CallerTest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests;\n\nuse App\\Caller;\n\nfinal class CallerTest\n{\n    public function testRuns(): string\n    {\n        return (new Caller())->run();\n    }\n}\n");
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
    }

    #[Group('query')]
    public function testChurnRanksFilesByCommitsTimesDependentsAndSaysWhenThereIsNoGit(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            assertSame('no-git', (new ChurnService($pdo))->churn($root)['status']);
            $this->runFixtureCommand(['git', 'init', '--quiet', $root]);
            $this->git($root, ['add', '.']);
            $this->git($root, ['commit', '--quiet', '-m', 'first']);
            // The greeter changes twice more, the caller once.
            foreach (['src/Core/Greeter.php', 'src/Core/Greeter.php', 'src/Edge/Caller.php'] as $n => $file) {
                file_put_contents($root . '/' . $file, (string) file_get_contents($root . '/' . $file) . "\n// {$n}\n");
                $this->git($root, ['commit', '--quiet', '-am', "change {$n}"]);
            }
            $churn = (new ChurnService($pdo))->churn($root);
            assertSame('ok', $churn['status']);
            assertSame([4, 30, false], [$churn['commits'], $churn['days'], $churn['truncated']]);
            assertSame(40, strlen((string) $churn['head']));
            // Greeter: 3 commits, 2 dependent files; Caller: 2 commits, nothing depends on it; the test: 1 commit, nothing.
            assertSame(
                [['src/Core/Greeter.php', 3, 2, 6, 'Core'], ['src/Edge/Caller.php', 2, 0, 0, 'Edge'], ['tests/GreeterTest.php', 1, 0, 0, 'namespace:App']],
                array_map(static fn(array $f): array => [$f['path'], $f['commits'], $f['dependents'], $f['score'], $f['boundary']], array_values(array_filter($churn['files'], static fn(array $f): bool => $f['path'] !== 'knossos.json' && $f['path'] !== 'composer.json'))),
            );
            assertSame('unscanned', (new ChurnService($pdo))->churn(sys_get_temp_dir())['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testALogTooLargeToReadWholeIsReadShorterAndSaidToBeCut(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            // A repository whose full window outruns git's output bound: only a shorter log can be read.
            $runner = new class implements GitProcessRunnerInterface {
                /** @var list<string> */
                public array $asked = [];

                public function run(array $command, int $timeoutMs, string $operation): string
                {
                    if (in_array('rev-parse', $command, true)) {
                        return str_repeat('a', 40) . "\n";
                    }
                    $count = (string) current(array_filter($command, static fn(string $arg): bool => str_starts_with($arg, '--max-count=')));
                    $this->asked[] = $count;
                    if ($count === '--max-count=' . ChurnService::COMMITS) {
                        throw new RuntimeException('Git churn output exceeded its configured byte limit.');
                    }
                    return "\0KNOSSOS_CHURN\x1f\0\nsrc/Core/Greeter.php\0\0KNOSSOS_CHURN\x1f\0\nsrc/Core/Greeter.php\0";
                }
            };
            $churn = (new ChurnService($pdo, $runner))->churn($root);
            assertSame(['ok', 2, true], [$churn['status'], $churn['commits'], $churn['truncated']]);
            assertSame('src/Core/Greeter.php', $churn['files'][0]['path']);
            assertSame(2, count($runner->asked));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Without -z git C-quoted a path holding a double quote, so its churn was
     * counted under `"src/Core/we\"ird.php"`, a name the graph does not hold,
     * and the file never ranked; trim() also cut a real edge space.
     */
    #[Group('query')]
    public function testChurnCountsAPathGitWouldQuoteUnderItsRealName(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            file_put_contents($root . '/src/Core/we"ird.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Core;\n\nfinal class Weird\n{\n}\n");
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $runner = new class implements GitProcessRunnerInterface {
                /** @var list<string> */
                public array $log = [];

                public function run(array $command, int $timeoutMs, string $operation): string
                {
                    if (in_array('rev-parse', $command, true)) {
                        return str_repeat('a', 40) . "\n";
                    }
                    $this->log = $command;

                    // The second commit names its file twice: a commit counts once per file.
                    return "\0KNOSSOS_CHURN\x1f\0\nsrc/Core/we\"ird.php\0src/Core/Greeter.php\0\0KNOSSOS_CHURN\x1f\0\nsrc/Core/we\"ird.php\0src/Core/we\"ird.php\0";
                }
            };
            $churn = (new ChurnService($pdo, $runner))->churn($root);
            assertSame(['ok', 2], [$churn['status'], $churn['commits']]);
            $commits = array_column($churn['files'], 'commits', 'path');
            assertSame([2, 1], [$commits['src/Core/we"ird.php'] ?? null, $commits['src/Core/Greeter.php'] ?? null]);
            assertSame(true, in_array('-z', $runner->log, true));
            assertSame(true, in_array('--since=' . ChurnService::DAYS . '.days.ago', $runner->log, true));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testALogGitCannotPrintEvenShorterIsSaidToBeUnreadNeverAnEmptyWindow(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $runner = new class implements GitProcessRunnerInterface {
                public function run(array $command, int $timeoutMs, string $operation): string
                {
                    if (in_array('rev-parse', $command, true)) {
                        return str_repeat('a', 40) . "\n";
                    }
                    throw new RuntimeException('Git churn timed out.');
                }
            };
            $churn = (new ChurnService($pdo, $runner))->churn($root);
            assertSame(['unreadable', 0, []], [$churn['status'], $churn['commits'], $churn['files']]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheBlastRadiusCountsEachRingAndTheTestsThatReachIt(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->withCallerTest($pdo, $root);
            $rings = (new BlastRadiusService($pdo))->rings($root, 'App\\Greeter::greet');
            assertSame('ok', $rings['status']);
            assertSame(['greet', 'App\\Greeter::greet', 'Core'], [$rings['component']['name'], $rings['component']['canonical_name'], $rings['component']['boundary']]);
            [$one, $two, $far] = $rings['rings'];
            // One hop: the caller's method (a test reaches it); the tests are no members of a ring.
            assertSame([1, 1, 1], [$one['hop'], $one['count'], $one['tested']]);
            assertSame(['App\\Caller::run'], array_column($one['items'], 'canonical_name'));
            assertSame([true, 'Edge', 'src/Edge/Caller.php'], [$one['items'][0]['tested'], $one['items'][0]['boundary'], $one['items'][0]['path']]);
            assertSame([['path' => 'tests/CallerTest.php', 'hop' => 2]], $one['tests']['items']);
            assertSame([0, 0, 0], [$two['count'], $far['count'], $far['tests']['count']]);
            assertSame(false, $rings['truncated']);
            assertSame('not-found', (new BlastRadiusService($pdo))->rings($root, 'App\\Nope')['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testARouteIsFoundEitherWayAndAnUnknownEndIsNamed(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $routes = (new PathBetweenService($pdo))->routes($root, 'App\\Caller::run', 'App\\Greeter::greet');
            assertSame(['ok', false], [$routes['status'], $routes['reversed']]);
            assertSame(['run', 'greet'], array_column($routes['routes'][0]['nodes'], 'name'));
            assertSame(['Edge', 'Core'], array_column($routes['routes'][0]['nodes'], 'boundary'));
            assertSame(['calls', 'src/Edge/Caller.php', 11], [$routes['routes'][0]['hops'][0]['kind'], $routes['routes'][0]['hops'][0]['path'], $routes['routes'][0]['hops'][0]['line']]);
            // Picked the wrong way round: the ends as asked, and the route the other way, said so.
            $back = (new PathBetweenService($pdo))->routes($root, 'App\\Greeter::greet', 'App\\Caller::run');
            assertSame(['ok', true, 'App\\Greeter::greet', 'App\\Caller::run'], [$back['status'], $back['reversed'], $back['from']['canonical_name'], $back['to']['canonical_name']]);
            assertSame(['run', 'greet'], array_column($back['routes'][0]['nodes'], 'name'));
            $missing = (new PathBetweenService($pdo))->routes($root, 'App\\Caller::run', 'App\\Nope');
            assertSame(['not-found', 'to'], [$missing['status'], $missing['unresolved']]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testANoteIsPreviewedUntilConfirmedAndARefusalSaysWhy(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $queries = new PaneQueries($pdo);
            $ask = fn(array $options): array => $queries->answer('annotate', $root, static fn(string $name): ?string => $options[$name] ?? null, static fn(string $name): bool => isset($options[$name]));
            $preview = $ask(['component' => 'App\\Greeter', 'value' => 'the one place greetings are worded']);
            assertSame(['ok', false, 'upsert', 'note', 'App\\Greeter'], [$preview['status'], $preview['executed'], $preview['action'], $preview['kind'], $preview['component']]);
            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM annotations')->fetchColumn());
            $done = $ask(['component' => 'App\\Greeter', 'value' => 'the one place greetings are worded', 'execute' => '1']);
            assertSame([true, null], [$done['executed'], $done['previous']]);
            assertSame('the one place greetings are worded', (string) $pdo->query('SELECT value FROM annotations')->fetchColumn());
            // A second note replaces the first, and says what it replaces.
            assertSame('the one place greetings are worded', $ask(['component' => 'App\\Greeter', 'value' => 'v2'])['previous']);
            $refused = $ask(['component' => 'App\\Greeter', 'kind' => 'opinion', 'value' => 'x']);
            assertSame('refused', $refused['status']);
            assertStringContainsString('kind must be one of', (string) $refused['reason']);
            assertSame('unscanned', $queries->answer('annotate', sys_get_temp_dir(), static fn(string $name): ?string => $name === 'component' ? 'X' : null, static fn(): bool => false)['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testEachCommandNamesItsOptions(): void
    {
        assertSame(['component'], PaneQueries::options('blast-radius'));
        assertSame(['from', 'to'], PaneQueries::options('path-between'));
        assertSame(['component', 'kind', 'value', 'remove', 'execute'], PaneQueries::options('annotate'));
        assertSame([], PaneQueries::options('churn'));
    }
}
