<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * find_component, inspect_component and list_usages report truncation and
 * ambiguity at exactly their limits.
 *
 * These are the tools an agent reaches for first, and each tells the caller
 * whether the answer is complete. Every limit comparison was exercised only
 * from well inside its range, so any of them could become `>=` with the suite
 * green: a single match reported as ambiguous, or a complete answer reported as
 * cut short, would each send an agent chasing something that is not there.
 */
final class ComponentBoundsTest extends KnossosTestCase
{
    /** The fixture holds two components under App\, so a limit of two fits and one does not. */
    #[Group('query')]
    public function testFindComponentReportsTruncationOnlyWhenMatchesExceedTheLimit(): void
    {
        [$queries, $project] = $this->queries();

        $atLimit = $queries->findComponent($project, 'App', 2);
        assertSame(2, count($atLimit->data['components']));
        assertSame(false, $atLimit->truncated, 'Two matches fit a limit of two.');

        $over = $queries->findComponent($project, 'App', 1);
        assertSame(1, count($over->data['components']), 'Only the matches within the limit are returned.');
        assertSame(true, $over->truncated);
    }

    #[Group('query')]
    public function testASingleMatchIsNotAmbiguousAndTwoAre(): void
    {
        [$queries, $project] = $this->queries();

        $single = $queries->findComponent($project, 'App\\InvoiceService');
        assertSame(1, count($single->data['components']));
        assertSame(false, $single->data['ambiguous'], 'One match is an answer, not an ambiguity.');

        assertSame(true, $queries->findComponent($project, 'App')->data['ambiguous']);
    }

    #[Group('query')]
    public function testListUsagesAcceptsOneThroughFiveHundred(): void
    {
        [$queries, $project] = $this->queries();

        $queries->listUsages($project, 'App\\InvoiceService', [], 'possible', 1);
        $queries->listUsages($project, 'App\\InvoiceService', [], 'possible', 500);
        assertThrows(static fn() => $queries->listUsages($project, 'App\\InvoiceService', [], 'possible', 0), InvalidArgumentException::class);
        assertThrows(static fn() => $queries->listUsages($project, 'App\\InvoiceService', [], 'possible', 501), InvalidArgumentException::class);
    }

    /** Checkout gets three children and two outgoing edges, so each limit can sit on its boundary. */
    #[Group('query')]
    public function testInspectComponentTruncatesChildrenAndRelationshipsOnlyPastTheirLimits(): void
    {
        [$queries, $project] = $this->queries(withChildrenAndEdges: true);

        $atLimit = $queries->inspectComponent($project, 'App\\Checkout', 2, 3)->data;
        assertSame(3, count($atLimit['component']['children']));
        assertSame(2, count($atLimit['component']['outgoing']));
        assertSame([], $atLimit['limits']['truncation_reasons'], 'Three children and two edges fit limits of three and two.');

        $over = $queries->inspectComponent($project, 'App\\Checkout', 1, 2)->data;
        assertSame(2, count($over['component']['children']), 'Only the children within the limit are returned.');
        assertSame(1, count($over['component']['outgoing']), 'Only the relationships within the limit are returned.');
        assertSame(true, in_array('child_limit', $over['limits']['truncation_reasons'], true));
        assertSame(true, in_array('outgoing_relationship_limit', $over['limits']['truncation_reasons'], true));
    }

    /** @return array{0: ArchitectureQueryService, 1: string} */
    private function queries(bool $withChildrenAndEdges = false): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        if ($withChildrenAndEdges) {
            foreach (['authorise', 'capture', 'refund'] as $index => $name) {
                $child = StableId::symbol($ids['project'], 'php', 'method', 'App\\Checkout::' . $name);
                $repository->saveNode($child, $ids['project'], 'php', 'method', 'App\\Checkout::' . $name, $name, $ids['checkout'], $ids['file'], 4 + $index, 5 + $index, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            }
            $ledger = StableId::symbol($ids['project'], 'php', 'class', 'App\\Ledger');
            $repository->saveNode($ledger, $ids['project'], 'php', 'class', 'App\\Ledger', 'Ledger', null, $ids['file'], 40, 50, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            $repository->saveEdge(
                StableId::edge($ids['project'], 'calls', $ids['checkout'], $ledger, 'src/Checkout.php:14'),
                $ids['project'], 'calls', $ids['checkout'], $ledger, $ids['file'], 14, 14, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan'],
            );
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        return [new ArchitectureQueryService($pdo), $ids['project']];
    }
}
