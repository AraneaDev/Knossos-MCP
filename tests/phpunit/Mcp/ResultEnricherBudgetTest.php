<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Query\ResultEnvelope;
use Knossos\Query\StalenessProbe;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use PDO;

/**
 * The budget's edges: what happens at exactly max_chars, which collection pays
 * first when two are the same size, what `truncated` and `max_chars` report,
 * and how compacted evidence refers to a hoisted component.
 *
 * ResultEnricher scored 66% under mutation testing. Its tests all sit well over
 * the budget with one obvious victim, so the inclusive comparison at the top of
 * the trimming loop, the alphabetical tie-break between equal collections, the
 * conditions under which max_chars is reported at all, and the evidence id
 * rewrite could all change with them green.
 *
 * The size measurer is injected for the boundary cases. It has to be: the
 * envelope that gets measured pads `result_bytes` with a nine-digit
 * placeholder, deliberately wider than any real value, so a payload sitting
 * exactly on the budget cannot be built from the outside.
 */
final class ResultEnricherBudgetTest extends KnossosTestCase
{
    /** A result measuring exactly max_chars fits, and says nothing about a budget. */
    #[Group('mcp')]
    public function testAResultMeasuringExactlyTheBudgetIsKeptWhole(): void
    {
        $enricher = $this->enricherMeasuring($this->freshTestDatabase(), 4_000);
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', ['components' => [['a'], ['b'], ['c']]]);

        $result = $enricher->enrich($envelope, 'search_architecture', 'compact', 4_000);

        assertSame(3, count($result->data['components']), 'Exactly filling the budget is fitting inside it.');
        assertSame(false, $result->truncated);
        assertSame([], $result->warnings);
        // Neither dropped anything nor missed the budget, so the budget itself
        // is not part of the answer.
        assertSame(false, array_key_exists('dropped_items', $result->meta));
        assertSame(false, array_key_exists('max_chars', $result->meta));
        assertSame(4_000, $result->meta['result_bytes']);
    }

    /** One byte over the budget is over it, and is reported as far as trimming can go. */
    #[Group('mcp')]
    public function testAResultOneByteOverTheBudgetIsTrimmedAndReported(): void
    {
        $enricher = $this->enricherMeasuring($this->freshTestDatabase(), 4_001);
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', ['components' => [['a'], ['b'], ['c']]]);

        $result = $enricher->enrich($envelope, 'search_architecture', 'compact', 4_000);

        assertSame(0, count($result->data['components']));
        assertSame(3, $result->meta['dropped_items']['components']);
        assertSame(4_000, $result->meta['max_chars']);
        assertSame(true, $result->truncated);
        assertSame(
            ['The max_chars budget could not be fully met by trimming result lists.'],
            $result->warnings,
            'A budget that trimming cannot reach is said out loud rather than met silently.',
        );
    }

    /**
     * Two collections of the same size: the alphabetically first one pays.
     *
     * The sizes are staged so exactly one item is dropped, which is what makes
     * the choice between the two visible at all.
     */
    #[Group('mcp')]
    public function testTheAlphabeticallyFirstOfTwoEqualCollectionsIsTrimmedFirst(): void
    {
        $pdo = $this->freshTestDatabase();
        $sizes = [5_000, 4_000];
        $enricher = new ResultEnricher(
            new StalenessProbe($pdo),
            new NextStepPlanner(),
            static function () use (&$sizes): int {
                return array_shift($sizes) ?? 4_000;
            },
        );
        // zebra is walked first and would win on encounter order alone.
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', [
            'zebra' => [['z1'], ['z2']],
            'alpha' => [['a1'], ['a2']],
        ]);

        $result = $enricher->enrich($envelope, 'search_architecture', 'compact', 4_000);

        assertSame(['alpha' => 1], $result->meta['dropped_items']);
        assertSame(2, count($result->data['zebra']));
        assertSame(1, count($result->data['alpha']));
    }

    /** An envelope that was already truncated stays truncated without any trimming. */
    #[Group('mcp')]
    public function testAnAlreadyTruncatedResultStaysTruncatedWithoutBeingTrimmed(): void
    {
        $pdo = $this->freshTestDatabase();
        $enricher = new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner());
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', ['components' => [1, 2, 3]], [], [], true);

        $result = $enricher->enrich($envelope, 'search_architecture', 'compact', 30_000);

        assertSame(true, $result->truncated, 'The upstream bound is the caller\'s answer, not this trimmer\'s.');
        assertSame(false, array_key_exists('dropped_items', $result->meta));
        assertSame(false, array_key_exists('max_chars', $result->meta));
        assertSame(3, count($result->data['components']));
    }

    /**
     * Compacting hoists a repeated component into the legend, so evidence that
     * pointed at it by id points at its name instead, under a de-`_id`'d key.
     * An id that names nothing hoisted is left exactly as it was.
     */
    #[Group('mcp')]
    public function testEvidenceIdsNamingAHoistedComponentBecomeNames(): void
    {
        $pdo = $this->freshTestDatabase();
        $enricher = new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner());
        $descriptor = [
            'id' => 'symbol_abc', 'kind' => 'method', 'canonical_name' => 'A\\B::c',
            'display_name' => 'c', 'origin' => 'ast', 'confidence' => 'certain',
        ];
        $envelope = new ResultEnvelope(
            'project_x',
            'scan_x',
            'ok',
            ['direct' => [$descriptor], 'grouped' => [['node' => $descriptor, 'distance' => 1]]],
            // `owner` holds the hoisted id under a key that does not end in
            // `_id`, which is the only shape that separates the real rule from
            // one that rewrites on either half of the condition.
            [['component_id' => 'symbol_abc', 'boundary_id' => 'boundary_1', 'owner' => 'symbol_abc', 'path' => 'src/A.php']],
        );

        $result = $enricher->enrich($envelope, 'impact_analysis', 'compact', 30_000);

        assertSame(
            ['component' => 'A\\B::c', 'boundary_id' => 'boundary_1', 'owner' => 'symbol_abc', 'path' => 'src/A.php'],
            $result->evidence[0],
            'Only an `_id` key naming a hoisted component is rewritten: an id that was not hoisted, and a hoisted id under another key, are both left alone.',
        );
    }

    /**
     * One pass drops as many items as the overage costs, not one and not all.
     *
     * Ten items measuring 5,000 against a 4,000 budget: the items average 500
     * bytes, the overage is 1,000, so two of them cover it.
     */
    #[Group('mcp')]
    public function testOnePassDropsAsManyItemsAsTheOverageCosts(): void
    {
        $enricher = $this->enricherMeasuringInTurn($this->freshTestDatabase(), [5_000, 4_000]);
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', ['components' => self::items(10)]);

        $result = $enricher->enrich($envelope, 'search_architecture', 'compact', 4_000);

        assertSame(['components' => 2], $result->meta['dropped_items']);
        assertSame(8, count($result->data['components']));
    }

    /**
     * A large estimate is capped at half the collection, so one pass can never
     * empty a list that the next pass would have kept.
     *
     * Ten items measuring 40,000 against 4,000: the overage is nine items'
     * worth, and five are dropped.
     */
    #[Group('mcp')]
    public function testABatchNeverTakesMoreThanHalfTheCollection(): void
    {
        $enricher = $this->enricherMeasuringInTurn($this->freshTestDatabase(), [40_000, 4_000]);
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', ['components' => self::items(10)]);

        $result = $enricher->enrich($envelope, 'search_architecture', 'compact', 4_000);

        assertSame(['components' => 5], $result->meta['dropped_items']);
        assertSame(5, count($result->data['components']));
    }

    /**
     * A collection holding more items than the payload holds bytes still drops
     * one at a time rather than dividing by a per-item cost of zero.
     */
    #[Group('mcp')]
    public function testACollectionLongerThanThePayloadIsStillTrimmable(): void
    {
        $enricher = $this->enricherMeasuringInTurn($this->freshTestDatabase(), [5, 4]);
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', ['components' => self::items(10)]);

        $result = $enricher->enrich($envelope, 'search_architecture', 'compact', 4);

        assertSame(['components' => 1], $result->meta['dropped_items']);
        assertSame(9, count($result->data['components']));
    }

    /**
     * Decoration pays before findings even when the findings are the larger
     * list: a three-entry legend is trimmed while fifty findings are untouched.
     *
     * Selecting by size alone emptied payloads while every legend entry
     * survived, which is the failure this tier ordering exists to prevent.
     */
    #[Group('mcp')]
    public function testTheSupportingTierPaysFirstEvenWhenItIsTheSmallerCollection(): void
    {
        $enricher = $this->enricherMeasuringInTurn($this->freshTestDatabase(), [5_000, 4_000]);
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', [
            'findings' => self::items(50),
            'component_legend' => ['A\\A' => ['kind' => 'class'], 'A\\B' => ['kind' => 'class'], 'A\\C' => ['kind' => 'class']],
        ]);

        $result = $enricher->enrich($envelope, 'dependency_cycles', 'compact', 4_000);

        assertSame(['component_legend' => 1], $result->meta['dropped_items']);
        assertSame(50, count($result->data['findings']));
        assertSame(2, count($result->data['component_legend']));
    }

    /**
     * $count distinct list items, each small enough that the staged measurer
     * rather than the real encoder decides the sizes.
     *
     * @return list<array<string, int>>
     */
    private static function items(int $count): array
    {
        return array_map(static fn(int $index): array => ['n' => $index], range(1, $count));
    }

    /**
     * An enricher whose measurer answers the given sizes in turn, so a single
     * trimming pass can be staged exactly.
     *
     * @param list<int> $sizes
     */
    private function enricherMeasuringInTurn(PDO $pdo, array $sizes): ResultEnricher
    {
        $remaining = $sizes;
        $last = $sizes[count($sizes) - 1];

        return new ResultEnricher(
            new StalenessProbe($pdo),
            new NextStepPlanner(),
            static function () use (&$remaining, $last): int {
                return array_shift($remaining) ?? $last;
            },
        );
    }

    /** An enricher whose measurer always answers $size, so a budget edge can be stated exactly. */
    private function enricherMeasuring(PDO $pdo, int $size): ResultEnricher
    {
        return new ResultEnricher(
            new StalenessProbe($pdo),
            new NextStepPlanner(),
            static fn(): int => $size,
        );
    }
}
