<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\GraphSearchService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;

/**
 * The pane's finder: components and files whose name holds the typed
 * letters in order, the closest first, bounded.
 */
final class GraphSearchServiceTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    #[Group('query')]
    public function testComponentsAndFilesMatchByLettersInOrderTheClosestFirst(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $found = (new GraphSearchService($pdo))->search($root, ' grtr ');
            assertSame(['ok', 'grtr', false], [$found['status'], $found['query'], $found['truncated']]);
            $first = $found['results'][0];
            // The class whose own name holds the letters comes before the file, and before a member whose class does.
            assertSame(['component', 'Greeter', 'App\\Greeter', 'class', 'src/Core/Greeter.php', 'Core'], [$first['type'], $first['name'], $first['canonical_name'], $first['kind'], $first['path'], $first['boundary']]);
            $types = array_column($found['results'], 'type');
            assertSame(true, in_array('file', $types, true));
            assertSame('src/Core/Greeter.php', $found['results'][array_search('file', $types, true)]['path']);
            // Any case; a whole name beats its start, its start beats a run inside it.
            assertSame('Greeter', (new GraphSearchService($pdo))->search($root, 'GREETER')['results'][0]['name']);
            assertGreaterThan(GraphSearchService::score('gre', 'Progress', 'x'), GraphSearchService::score('gre', 'Greeter', 'x'));
            assertGreaterThan(GraphSearchService::score('gre', 'x', 'a/greet'), GraphSearchService::score('gre', 'Progress', 'x'));
            assertSame(0, GraphSearchService::score('zq', 'Greeter', 'src/Greeter.php'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testWildcardsAreLettersAnEmptyQueryFindsNothingAndTheListIsBounded(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $service = new GraphSearchService($pdo);
            // `%` and `_` are matched as themselves, not as any text.
            assertSame([], $service->search($root, '%')['results']);
            assertSame([], $service->search($root, '_')['results']);
            assertSame([], $service->search($root, "  \t ")['results']);
            assertSame(GraphSearchService::QUERY_MAX, mb_strlen($service->search($root, str_repeat('a', 200))['query']));
            for ($i = 0; $i < 30; ++$i) {
                file_put_contents($root . "/src/Core/Thing{$i}.php", "<?php\nnamespace App;\nfinal class Thing{$i} {}\n");
            }
            (new \Knossos\Scan\ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $things = $service->search($root, 'thing')['results'];
            assertSame(GraphSearchService::LIMIT, count($things));
            assertNotContains('external_class', array_column($things, 'kind'));
            assertSame('unscanned', $service->search(sys_get_temp_dir(), 'x')['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testNonAsciiLettersMatchInAnyCase(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            file_put_contents($root . '/src/Core/Écran.php', "<?php\nnamespace App;\nfinal class Écran {}\n");
            (new \Knossos\Scan\ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $service = new GraphSearchService($pdo);
            // SQLite folds only ASCII: the name is found typed either way, and a letter that only looks alike is not one.
            foreach (['écran', 'ÉCRAN', 'Écran', 'éc'] as $typed) {
                $first = $service->search($root, $typed)['results'][0] ?? null;
                assertSame('App\\Écran', $first['canonical_name'] ?? null, $typed);
            }
            assertNotContains('App\\Écran', array_column($service->search($root, 'ëcran')['results'], 'canonical_name'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testASharpSFindsTheCapitalSharpSAndTheReverse(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            // No case mapping pairs ß with ẞ (U+1E9E): ß upper-cases to SS, and ẞ lower-cases to ß only one way.
            file_put_contents($root . '/src/Core/Sharp.php', "<?php\nnamespace App;\nfinal class FUẞBALL {}\nfinal class Maße {}\n");
            (new \Knossos\Scan\ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $service = new GraphSearchService($pdo);
            foreach (['fußball' => 'App\\FUẞBALL', 'FUẞBALL' => 'App\\FUẞBALL', 'ß' => 'App\\FUẞBALL', 'maße' => 'App\\Maße', 'MAẞE' => 'App\\Maße'] as $typed => $expected) {
                assertContains($expected, array_column($service->search($root, $typed)['results'], 'canonical_name'), $typed);
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testANonAsciiQueryFindsANameBeyondTheCandidateBound(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            // 676 classes `GrXYe`, each shorter than or as short as the names looked for and sorting before them:
            // a pattern that let any character through for a non-ASCII letter kept only these.
            $classes = '';
            foreach (range('a', 'z') as $x) {
                foreach (range('a', 'z') as $y) {
                    $classes .= "final class Gr{$x}{$y}e {}\n";
                }
            }
            file_put_contents($root . '/src/Core/Many.php', "<?php\nnamespace App;\n" . $classes);
            file_put_contents($root . '/src/Core/Settings.php', "<?php\nnamespace App;\nfinal class 設定Service {}\nfinal class Größe {}\n");
            (new \Knossos\Scan\ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $service = new GraphSearchService($pdo);
            assertSame(true, $service->search($root, 'gre')['truncated']);
            foreach (['設定' => 'App\\設定Service', '定s' => 'App\\設定Service', 'größe' => 'App\\Größe', 'GRÖßE' => 'App\\Größe', 'gröe' => 'App\\Größe'] as $typed => $expected) {
                $found = $service->search($root, $typed);
                assertSame($expected, $found['results'][0]['canonical_name'] ?? null, $typed);
                assertSame(false, $found['truncated'], $typed);
            }
        } finally {
            $this->removeTempTree($root);
        }
    }
}
