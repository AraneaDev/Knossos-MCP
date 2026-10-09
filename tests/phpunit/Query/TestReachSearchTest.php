<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\AbstractArchitectureQueryService;
use Knossos\Query\ReportableComponent;
use Knossos\Query\TestReachSearch;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The reverse search behind test_impact: it walks every dependant, production
 * and test alike, and returns only the test-classified ones, with bounds of its
 * own that are always reported when they cut the walk.
 */
final class TestReachSearchTest extends KnossosTestCase
{
    #[Group('query')]
    public function testTheVisitBoundStopsTheSearchAndIsReported(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->fiveCallersAndATest($repository, $ids);

        $found = (new TestReachSearch($pdo, maxVisited: 3))->search($ids['project'], [$ids['checkout']], 4, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, PHP_INT_MAX);

        self::assertSame(['visit_limit'], $found['truncation_reasons']);
        self::assertSame(3, $found['visited']);
        self::assertSame([], $found['tests']);
    }

    #[Group('query')]
    public function testTheEdgeBoundStopsTheSearchAndIsReported(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->fiveCallersAndATest($repository, $ids);

        $found = (new TestReachSearch($pdo, maxEdges: 2))->search($ids['project'], [$ids['checkout']], 4, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, PHP_INT_MAX);

        self::assertSame(['edge_limit'], $found['truncation_reasons']);
        self::assertSame(2, $found['edges_examined']);
    }

    #[Group('query')]
    public function testAnUnboundedSearchFindsTheTestBehindTheFifthCaller(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $test = $this->fiveCallersAndATest($repository, $ids);

        $found = (new TestReachSearch($pdo))->search($ids['project'], [$ids['checkout']], 4, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, PHP_INT_MAX);

        self::assertSame([$test => 2], $found['tests']);
        self::assertSame([], $found['truncation_reasons']);
        self::assertSame(7, $found['visited'], 'Checkout, five callers and the test.');
        self::assertSame(6, $found['edges_examined'], 'Five caller edges and the test edge; Checkout\'s own call to Invoice points the other way.');
    }

    /**
     * A bound cuts the walk only when something lies beyond it: reaching the
     * count exactly, with nothing left to visit or read, is a finished search.
     */
    #[Group('query')]
    public function testASearchThatEndsExactlyAtItsBoundsIsNotTruncated(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $test = $this->fiveCallersAndATest($repository, $ids);

        $found = (new TestReachSearch($pdo, maxVisited: 7, maxEdges: 6))->search($ids['project'], [$ids['checkout']], 4, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, PHP_INT_MAX);

        self::assertSame([], $found['truncation_reasons']);
        self::assertSame(7, $found['visited']);
        self::assertSame(6, $found['edges_examined']);
        self::assertSame([$test => 2], $found['tests']);
    }

    #[Group('query')]
    public function testTheDeadlineStopsTheSearchAndIsReported(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->fiveCallersAndATest($repository, $ids);
        $ticks = 0;
        $clock = static function () use (&$ticks): int {
            return ++$ticks;
        };

        $found = (new TestReachSearch($pdo, $clock))->search($ids['project'], [$ids['checkout']], 4, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, 0);

        self::assertSame(['time_limit'], $found['truncation_reasons']);
        self::assertSame(0, $found['edges_examined']);
    }

    #[Group('query')]
    public function testDepthEndsTheSearchWithoutTruncation(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $a = $this->component($repository, $ids, 'App\\A');
        $b = $this->component($repository, $ids, 'App\\B');
        $test = $this->component($repository, $ids, 'Tests\\FarTest', test: true);
        $this->edge($repository, $ids, 'calls', $a, $ids['checkout']);
        $this->edge($repository, $ids, 'calls', $b, $a);
        $this->edge($repository, $ids, 'calls', $test, $b);
        $repository->completeScan($ids['project'], $ids['scan']);
        $search = new TestReachSearch($pdo);

        $shallow = $search->search($ids['project'], [$ids['checkout']], 2, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, PHP_INT_MAX);
        $deep = $search->search($ids['project'], [$ids['checkout']], 3, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, PHP_INT_MAX);

        self::assertSame([], $shallow['tests']);
        self::assertSame([], $shallow['truncation_reasons']);
        self::assertSame([$test => 3], $deep['tests']);
    }

    #[Group('query')]
    public function testAChangedTestIsDistanceZeroAndTheSearchContinuesThroughTestHelpers(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $helper = $this->component($repository, $ids, 'Tests\\Support\\Helper', test: true);
        $test = $this->component($repository, $ids, 'Tests\\ATest', test: true);
        $this->edge($repository, $ids, 'calls', $helper, $ids['checkout']);
        $this->edge($repository, $ids, 'calls', $test, $helper);
        $repository->completeScan($ids['project'], $ids['scan']);
        $search = new TestReachSearch($pdo);

        $fromCheckout = $search->search($ids['project'], [$ids['checkout']], 4, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, PHP_INT_MAX);
        $fromHelper = $search->search($ids['project'], [$helper], 4, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, PHP_INT_MAX);

        self::assertSame([$helper => 1, $test => 2], $fromCheckout['tests']);
        self::assertSame([$helper => 0, $test => 1], $fromHelper['tests']);
    }

    #[Group('query')]
    public function testMinimumConfidenceAndEdgeKindsFilterTheWalk(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $guessed = $this->component($repository, $ids, 'Tests\\GuessedTest', test: true);
        $contained = $this->component($repository, $ids, 'Tests\\ContainerTest', test: true);
        $imported = $this->component($repository, $ids, 'Tests\\ImportTest', test: true);
        $this->edge($repository, $ids, 'calls', $guessed, $ids['checkout'], 'possible');
        $this->edge($repository, $ids, 'contains', $contained, $ids['checkout']);
        $this->edge($repository, $ids, 'imports', $imported, $ids['checkout']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $search = new TestReachSearch($pdo);

        $all = $search->search($ids['project'], [$ids['checkout']], 4, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, PHP_INT_MAX);
        $probable = $search->search($ids['project'], [$ids['checkout']], 4, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 2, PHP_INT_MAX);
        $callsOnly = $search->search($ids['project'], [$ids['checkout']], 4, ['calls'], 1, PHP_INT_MAX);

        self::assertEqualsCanonicalizing([$guessed, $imported], array_keys($all['tests']), '`contains` is never an impact edge.');
        self::assertSame([$imported], array_keys($probable['tests']), 'A possible edge is skipped at rank 2.');
        self::assertSame([$guessed], array_keys($callsOnly['tests']));
    }

    /**
     * Checkout with five callers and a test behind the fifth.
     *
     * @param array<string, string> $ids
     */
    private function fiveCallersAndATest(GraphRepository $repository, array $ids): string
    {
        $caller = '';
        foreach (range(1, 5) as $index) {
            $caller = $this->component($repository, $ids, sprintf('App\\Caller%d', $index));
            $this->edge($repository, $ids, 'calls', $caller, $ids['checkout']);
        }
        $test = $this->component($repository, $ids, 'Tests\\BehindTest', test: true);
        $this->edge($repository, $ids, 'calls', $test, $caller);
        $repository->completeScan($ids['project'], $ids['scan']);
        return $test;
    }

    /**
     * A class node, classified as test code when asked.
     *
     * @param array<string, string> $ids
     */
    private function component(GraphRepository $repository, array $ids, string $name, bool $test = false): string
    {
        $project = $ids['project'];
        $id = StableId::symbol($project, 'php', 'class', $name);
        $repository->saveNode($id, $project, 'php', 'class', $name, $name, null, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Reach.php', $ids['scan']);
        if ($test) {
            $repository->saveClassification(StableId::classification($project, $id, ReportableComponent::TEST_ROLE, 'test.roles'), $project, $id, ReportableComponent::TEST_ROLE, 'derived', 'probable', 'test.roles', $ids['file'], 1, 1, [], $ids['scan']);
        }
        return $id;
    }

    /** @param array<string, string> $ids */
    private function edge(GraphRepository $repository, array $ids, string $kind, string $source, string $target, string $confidence = 'certain'): void
    {
        $project = $ids['project'];
        $repository->saveEdge(StableId::edge($project, $kind, $source, $target, 'reach'), $project, $kind, $source, $target, $ids['file'], 1, 1, 'ast', $confidence, [], 'php:file:src/Reach.php', $ids['scan']);
    }
}
