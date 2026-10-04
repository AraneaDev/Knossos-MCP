<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\GraphSearchService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

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
}
