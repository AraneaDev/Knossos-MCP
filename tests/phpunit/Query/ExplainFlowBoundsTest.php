<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * explain_flow bounded its search by states taken off the queue, never by
 * states put on it, and checked the clock only between pops. One wide node
 * could therefore queue every one of its targets, each with a copy of its
 * path, before the clock or the visit bound was consulted again; and it read
 * every target node with a query of its own. The queue now has a bound of its
 * own, the clock is read while one node is being expanded, and the target
 * comes from the edge query.
 */
final class ExplainFlowBoundsTest extends KnossosTestCase
{
    #[Group('query')]
    public function testTheQueueIsBoundedByStatesQueuedNotOnlyByStatesVisited(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        [$nodes, $edges] = self::wideGraph($ids);
        self::save($repository, $ids, $nodes, $edges);

        // Depth 2 keeps the drained leaves from costing a query each: a leaf
        // already holds two hops, so it is popped and dropped.
        $result = ArchitectureQueryService::forDatabase($pdo)->explainFlow($ids['project'], 'App\\S', 'App\\T', maxDepth: 2, timeoutMs: 5000);

        self::assertTrue($result->truncated);
        self::assertSame(['queue_limit'], $result->data['bounds']['truncation_reasons']);
        self::assertSame(10_000, $result->data['bounds']['queued_states'], 'States stop being accepted exactly at the bound.');
        self::assertSame(10_000, $result->data['bounds']['visited_states'], 'Every state accepted before the bound is still visited.');
        self::assertStringContainsString('No supported static flow', $result->summary);
        self::assertStringContainsString('The search was truncated (queue_limit).', $result->summary);
    }

    /**
     * A full queue stops new states, not the search: the states already queued
     * cost no more memory, and one of them may be a hop from the target.
     */
    #[Group('query')]
    public function testStatesQueuedBeforeTheBoundAreStillSearchedForTheTarget(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        [$nodes, $edges] = self::wideGraph($ids);
        // Edges leave a node in edge-id order, so the middle behind the
        // highest S edge id is queued last and expanded last: the 100 middles
        // ahead of it have filled the queue by then.
        $fromSource = array_values(array_filter($edges, static fn(array $edge): bool => $edge['source_id'] === $nodes[0]['id']));
        usort($fromSource, static fn(array $left, array $right): int => strcmp($left['id'], $right['id']));
        $last = $fromSource[array_key_last($fromSource)]['target_id'];
        $edges[] = self::edge($ids, $last, $nodes[1]['id']);
        self::save($repository, $ids, $nodes, $edges);

        $result = ArchitectureQueryService::forDatabase($pdo)->explainFlow($ids['project'], 'App\\S', 'App\\T', maxDepth: 2, timeoutMs: 5000);

        self::assertTrue($result->truncated);
        self::assertSame(['queue_limit'], $result->data['bounds']['truncation_reasons']);
        self::assertCount(1, $result->data['paths']);
        self::assertSame([$nodes[0]['id'], $last, $nodes[1]['id']], array_column($result->data['paths'][0]['nodes'], 'id'));
        self::assertSame('Found 1 plausible static flow. The search was truncated (queue_limit).', $result->summary);
    }

    #[Group('query')]
    public function testTheDeadlineIsCheckedWhileExpandingOneNode(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $nodes = [self::node($ids, 'S'), self::node($ids, 'T')];
        $edges = [];
        for ($i = 0; $i < 500; $i++) {
            $target = self::node($ids, sprintf('M%03d', $i));
            $nodes[] = $target;
            $edges[] = self::edge($ids, $nodes[0]['id'], $target['id']);
        }
        self::save($repository, $ids, $nodes, $edges);
        $ticks = 0;
        $clock = static function () use (&$ticks): int {
            return ++$ticks * 1_000_000;
        };

        $result = ArchitectureQueryService::forDatabase($pdo, $clock)->explainFlow($ids['project'], 'App\\S', 'App\\T', maxDepth: 2, timeoutMs: 3);

        self::assertTrue($result->truncated);
        self::assertContains('time_limit', $result->data['bounds']['truncation_reasons']);
        self::assertLessThan(500, $result->data['bounds']['queued_states'], 'Expansion of S stops at the deadline instead of queueing all 500 targets.');
        self::assertStringContainsString('The search was truncated (time_limit).', $result->summary);
    }

    /**
     * Targets read from the edge row must look exactly like the node rows the
     * search used to fetch one by one, or the path a caller receives changes.
     */
    #[Group('query')]
    public function testAFoundPathCarriesTheFullTargetNodeAndAnUntruncatedSummary(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = ArchitectureQueryService::forDatabase($pdo)->explainFlow($ids['project'], $ids['checkout'], $ids['invoice']);

        self::assertFalse($result->truncated);
        self::assertSame('Found 1 plausible static flow.', $result->summary);
        $last = $result->data['paths'][0]['nodes'][1];
        self::assertSame(['id', 'kind', 'canonical_name', 'display_name', 'confidence'], array_keys($last));
        self::assertSame($ids['invoice'], $last['id']);
        self::assertSame('App\\InvoiceService', $last['canonical_name']);
        self::assertSame('InvoiceService', $last['display_name']);
        self::assertSame('class', $last['kind']);
        self::assertSame('certain', $last['confidence']);
        self::assertSame(1, $result->data['bounds']['queued_states'], 'Only the source is queued; a target is recorded, never queued.');
    }

    /**
     * S calls M000..M100 and each of those calls 100 leaves: 10,202 states
     * when fully queued, more than the queue bound allows. T is reached by
     * nothing unless a test adds an edge to it.
     *
     * @param array<string, string> $ids
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private static function wideGraph(array $ids): array
    {
        $nodes = [self::node($ids, 'S'), self::node($ids, 'T')];
        $edges = [];
        for ($i = 0; $i <= 100; $i++) {
            $middle = self::node($ids, sprintf('M%03d', $i));
            $nodes[] = $middle;
            $edges[] = self::edge($ids, $nodes[0]['id'], $middle['id']);
            for ($j = 0; $j < 100; $j++) {
                $leaf = self::node($ids, sprintf('M%03d\\L%03d', $i, $j));
                $nodes[] = $leaf;
                $edges[] = self::edge($ids, $middle['id'], $leaf['id']);
            }
        }
        return [$nodes, $edges];
    }

    /**
     * @param array<string, string> $ids
     * @return array<string, mixed>
     */
    private static function node(array $ids, string $name): array
    {
        return [
            'id' => StableId::symbol($ids['project'], 'php', 'function', 'App\\' . $name),
            'language' => 'php', 'kind' => 'function', 'canonical_name' => 'App\\' . $name, 'display_name' => $name,
            'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 1, 'origin' => 'ast', 'confidence' => 'certain',
            'attributes' => [], 'owner_key' => 'php:file:src/Flow.php',
        ];
    }

    /**
     * @param array<string, string> $ids
     * @return array<string, mixed>
     */
    private static function edge(array $ids, string $source, string $target): array
    {
        return [
            'id' => StableId::edge($ids['project'], 'calls', $source, $target, 'flow'),
            'kind' => 'calls', 'source_id' => $source, 'target_id' => $target,
            'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 1, 'origin' => 'ast',
            'confidence' => 'certain', 'attributes' => [], 'owner_key' => 'php:file:src/Flow.php',
        ];
    }

    /**
     * @param array<string, string> $ids
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $edges
     */
    private static function save(GraphRepository $repository, array $ids, array $nodes, array $edges): void
    {
        $repository->bulkTransaction(static function (GraphRepository $repository) use ($nodes, $edges, $ids): void {
            $repository->saveNodes($nodes, $ids['project'], $ids['scan']);
            $repository->saveEdges($edges, $ids['project'], $ids['scan']);
        });
        $repository->completeScan($ids['project'], $ids['scan']);
    }
}
