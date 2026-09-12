<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `suggest_location` reports truncation at exactly its member and edge limits.
 *
 * A ranking computed over a truncated slice of the graph is weaker evidence,
 * and the envelope's `truncated` flag with its reasons is how a caller learns
 * that. The limit comparisons were never exercised at the boundary, so either
 * could become `>=` and report a complete scan as truncated (or the reverse)
 * with the suite green.
 *
 * The fixture holds exactly two boundary memberships and two edges, so a limit
 * of 2 sits on each boundary and a limit of 1 falls just inside it.
 */
final class SuggestionTruncationTest extends KnossosTestCase
{
    #[Group('query')]
    public function testTheMemberLimitTruncatesOnlyWhenMembersExceedIt(): void
    {
        [$queries, $project] = $this->queries();

        $atLimit = $queries->suggestLocation($project, 'checkout refunds', 5, 2)->data['bounds'];
        assertSame(false, in_array('member_limit', $atLimit['truncation_reasons'], true), 'Two members fit a limit of two.');
        assertSame(2, $atLimit['members_examined']);

        $over = $queries->suggestLocation($project, 'checkout refunds', 5, 1);
        assertSame(true, in_array('member_limit', $over->data['bounds']['truncation_reasons'], true));
        assertSame(true, $over->truncated);
        assertSame(1, $over->data['bounds']['members_examined'], 'Only the members within the limit are examined.');
    }

    #[Group('query')]
    public function testTheEdgeLimitTruncatesOnlyWhenEdgesExceedIt(): void
    {
        [$queries, $project] = $this->queries();

        $atLimit = $queries->suggestLocation($project, 'checkout refunds', 5, 20_000, 2)->data['bounds'];
        assertSame(false, in_array('edge_limit', $atLimit['truncation_reasons'], true), 'Two edges fit a limit of two.');
        assertSame(2, $atLimit['edges_examined']);

        $over = $queries->suggestLocation($project, 'checkout refunds', 5, 20_000, 1);
        assertSame(true, in_array('edge_limit', $over->data['bounds']['truncation_reasons'], true));
        assertSame(true, $over->truncated, 'An edge cut must set the envelope flag, not only record a reason.');
        assertSame(1, $over->data['bounds']['edges_examined'], 'Only the edges within the limit are examined.');
    }

    #[Group('query')]
    public function testNothingIsTruncatedWhenEveryLimitHolds(): void
    {
        [$queries, $project] = $this->queries();

        $result = $queries->suggestLocation($project, 'checkout refunds', 5, 2, 2);

        assertSame([], $result->data['bounds']['truncation_reasons']);
        assertSame(false, $result->truncated);
    }

    /**
     * Two boundaries, one member each, and two edges between them.
     *
     * @return array{0: ArchitectureQueryService, 1: string}
     */
    private function queries(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->saveEdge(
            StableId::edge($ids['project'], 'calls', $ids['invoice'], $ids['checkout'], 'truncation-reverse'),
            $ids['project'], 'calls', $ids['invoice'], $ids['checkout'], $ids['file'], 30, 30, 'ast', 'certain', [], 'truncation:file:src/Invoice.php', $ids['scan'],
        );
        $repository->completeScan($ids['project'], $ids['scan']);

        return [new ArchitectureQueryService($pdo), $ids['project']];
    }
}
