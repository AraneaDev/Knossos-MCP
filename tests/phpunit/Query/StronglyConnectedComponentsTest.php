<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The component walk on a graph whose cycles are known exactly.
 *
 * AbstractArchitectureQueryService scored 75% under mutation testing and the
 * component walk holds most of what survived. The existing tests assert that
 * cycles are found and how many, which every step of the traversal can change
 * without disturbing: the edge index a child starts from, the order members come
 * back in, and which nodes end up grouped together.
 *
 * The fixture is two disjoint cycles of different sizes plus a node on neither,
 * so a traversal that skips an edge, or that sweeps two components into one,
 * reports something this test can see.
 */
final class StronglyConnectedComponentsTest extends KnossosTestCase
{
    /** Two disjoint cycles are reported as two, each with exactly its own members. */
    #[Group('query')]
    public function testDisjointCyclesAreReportedSeparatelyWithTheirOwnMembers(): void
    {
        [$pdo, $project] = $this->graph();

        $sets = array_map(static function (array $members): array {
            sort($members, SORT_STRING);

            return $members;
        }, self::cyclesOf($pdo, $project));

        assertSame(
            [
                ['App\\Aaa', 'App\\Bbb', 'App\\Ccc'],
                ['App\\Ddd', 'App\\Eee'],
            ],
            $sets,
            'Three members and two, each in its own component and nowhere else.',
        );
    }

    /**
     * Members come back ordered by node id, not in the order the walk reached
     * them.
     *
     * The ordering is on the ids, and the names are attached afterwards, so the
     * result looks unsorted by name and is nonetheless fixed. That is the
     * property worth having: a report that does not churn between runs. Sorting
     * by name here instead would assert something the service never promised.
     */
    #[Group('query')]
    public function testMembersComeBackOrderedByNodeId(): void
    {
        [$pdo, $project] = $this->graph();

        $cycles = self::cyclesOf($pdo, $project);

        assertSame($cycles, self::cyclesOf($pdo, $project), 'The same graph reports the same cycles.');
        foreach ($cycles as $members) {
            assertSame(self::byNodeId($project, $members), $members, 'Members follow their id order, not the traversal order.');
        }
    }

    /**
     * The given names, ordered the way their node ids order.
     *
     * @param list<string> $names @return list<string>
     */
    private static function byNodeId(string $projectId, array $names): array
    {
        $ordered = $names;
        usort($ordered, static fn(string $a, string $b): int => StableId::symbol($projectId, 'php', 'class', $a)
            <=> StableId::symbol($projectId, 'php', 'class', $b));

        return $ordered;
    }

    /** A node on no cycle is on no cycle, however many edges it has. */
    #[Group('query')]
    public function testANodeOnNoCycleIsNotReported(): void
    {
        [$pdo, $project] = $this->graph();

        $reported = [];
        foreach (self::cyclesOf($pdo, $project) as $members) {
            $reported = [...$reported, ...$members];
        }

        assertSame(false, in_array('App\\Lonely', $reported, true), 'A one-way dependency into a cycle is not part of it.');
    }

    /**
     * Every member of every cycle found, each cycle's members sorted, the
     * cycles themselves ordered largest first so the assertion does not depend
     * on which component the walk happened to close first.
     *
     * @return list<list<string>>
     */
    private static function cyclesOf(PDO $pdo, string $projectId): array
    {
        $data = (new ArchitectureQueryService($pdo))->dependencyCycles($projectId)->data;
        $cycles = array_map(
            static fn(array $cycle): array => array_map(
                static fn(mixed $member): string => is_array($member) ? (string) ($member['canonical_name'] ?? $member['id']) : (string) $member,
                $cycle['members'],
            ),
            $data['cycles'],
        );
        usort($cycles, static fn(array $a, array $b): int => [count($b), $a[0] ?? ''] <=> [count($a), $b[0] ?? '']);

        return $cycles;
    }

    /**
     * Two disjoint cycles, three members and two, plus a node that only points
     * into one of them.
     *
     * @return array{0: PDO, 1: string}
     */
    private function graph(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $nodes = [];
        foreach (['Aaa', 'Bbb', 'Ccc', 'Ddd', 'Eee', 'Lonely'] as $name) {
            $id = StableId::symbol($ids['project'], 'php', 'class', 'App\\' . $name);
            $repository->saveNode($id, $ids['project'], 'php', 'class', 'App\\' . $name, $name, null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            $nodes[$name] = $id;
        }
        foreach ([
            ['Aaa', 'Bbb'], ['Bbb', 'Ccc'], ['Ccc', 'Aaa'],
            ['Ddd', 'Eee'], ['Eee', 'Ddd'],
            ['Lonely', 'Aaa'],
        ] as [$from, $to]) {
            $repository->saveEdge(
                StableId::edge($ids['project'], 'calls', $nodes[$from], $nodes[$to], $from . $to),
                $ids['project'],
                'calls',
                $nodes[$from],
                $nodes[$to],
                $ids['file'],
                5,
                5,
                'ast',
                'certain',
                [],
                'php:file:src/Checkout.php',
                $ids['scan'],
            );
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        return [$pdo, $ids['project']];
    }
}
