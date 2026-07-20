<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\NextStepPlanner;
use Knossos\Query\ResultEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The planner turns a finished tool result into follow-up suggestions. Its whole
 * contract is "suggest the obvious next call, or say nothing" — so the cases below
 * pin both halves: what it must suggest, and when it must stay silent.
 */
final class NextStepPlannerTest extends TestCase
{
    private NextStepPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new NextStepPlanner();
    }

    public function testAmbiguousFindSuggestsInspectingTheTopCandidate(): void
    {
        $steps = $this->plan('find_component', ['components' => [
            ['canonical_name' => 'App\\Checkout'],
            ['canonical_name' => 'App\\CheckoutController'],
        ]]);

        self::assertCount(1, $steps);
        self::assertSame('inspect_component', $steps[0]['tool']);
        self::assertSame('App\\Checkout', $steps[0]['args']['component']);
        self::assertNotSame('', $steps[0]['why']);
    }

    public function testUnambiguousFindSuggestsNothing(): void
    {
        // One match needs no disambiguation, so the boundary is "fewer than two".
        self::assertSame([], $this->plan('find_component', ['components' => [['canonical_name' => 'App\\Only']]]));
    }

    public function testInspectSuggestsImpactAnalysis(): void
    {
        $steps = $this->plan('inspect_component', ['component' => 'App\\Checkout']);

        self::assertCount(1, $steps);
        self::assertSame('impact_analysis', $steps[0]['tool']);
        self::assertSame('App\\Checkout', $steps[0]['args']['symbol']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function hubDossiers(): iterable
    {
        yield 'flagged at the top level' => [['component' => 'App\\Checkout', 'is_hub' => true]];
        yield 'flagged inside the dossier' => [['component' => ['canonical_name' => 'App\\Checkout', 'is_hub' => true]]];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('hubDossiers')]
    public function testHubDossierAlsoSuggestsACycleCheck(array $data): void
    {
        $steps = $this->plan('inspect_component', $data);

        self::assertCount(2, $steps);
        self::assertSame('impact_analysis', $steps[0]['tool']);
        self::assertSame('dependency_cycles', $steps[1]['tool']);
    }

    public function testNonHubDossierDoesNotSuggestACycleCheck(): void
    {
        $steps = $this->plan('inspect_component', ['component' => 'App\\Checkout', 'is_hub' => false]);

        self::assertCount(1, $steps);
        self::assertSame('impact_analysis', $steps[0]['tool']);
    }

    public function testImpactSuggestsTracingTheFlowToADependant(): void
    {
        $steps = $this->plan('impact_analysis', [
            'target' => 'App\\Checkout',
            'direct_dependants' => [['canonical_name' => 'App\\Invoice']],
        ]);

        self::assertCount(1, $steps);
        self::assertSame('explain_flow', $steps[0]['tool']);
        self::assertSame('App\\Checkout', $steps[0]['args']['from']);
        self::assertSame('App\\Invoice', $steps[0]['args']['to']);
    }

    public function testHealthSuggestsInspectingTheTopHotspot(): void
    {
        $steps = $this->plan('architecture_health', [
            'static_hotspots' => [['canonical_name' => 'App\\Hot'], ['canonical_name' => 'App\\Warm']],
        ]);

        self::assertCount(1, $steps);
        self::assertSame('inspect_component', $steps[0]['tool']);
        self::assertSame('App\\Hot', $steps[0]['args']['component']);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function resultsWithNoUsableFollowUp(): iterable
    {
        yield 'tool with no plan' => ['architecture_summary', []];
        yield 'find whose top candidate cannot be named' => ['find_component', ['candidates' => [['score' => 0.9], ['score' => 0.7]]]];
        yield 'inspect with no component' => ['inspect_component', []];
        yield 'inspect naming a non-string scalar' => ['inspect_component', ['component' => 42]];
        // An object must be rejected by the is_array guard, not indexed like an array
        // (which would be a fatal error rather than "no suggestion").
        yield 'inspect naming an object' => ['inspect_component', ['component' => new \stdClass()]];
        yield 'inspect naming an empty string' => ['inspect_component', ['component' => '']];
        yield 'impact with no dependants' => ['impact_analysis', ['target' => 'App\\Checkout']];
        yield 'impact whose dependant cannot be named' => ['impact_analysis', ['target' => 'App\\Checkout', 'impacted' => [['score' => 1]]]];
        yield 'impact with no target' => ['impact_analysis', ['impacted' => [['name' => 'App\\Invoice']]]];
        yield 'health with no hotspots' => ['architecture_health', []];
        yield 'health whose hotspot cannot be named' => ['architecture_health', ['hotspots' => [['score' => 1]]]];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('resultsWithNoUsableFollowUp')]
    public function testSuggestsNothingWhenThereIsNoUsableFollowUp(string $tool, array $data): void
    {
        // Emitting a call the planner knows cannot succeed is worse than silence.
        self::assertSame([], $this->plan($tool, $data));
    }

    public function testNamesAreResolvedThroughNestedWrappers(): void
    {
        // The queries return names under several shapes; all must resolve.
        foreach ([
            ['component' => ['node' => ['canonical_name' => 'App\\Nested']]],
            ['component' => ['name' => 'App\\Nested']],
            ['component' => ['display_name' => 'App\\Nested']],
        ] as $data) {
            $steps = $this->plan('inspect_component', $data);
            self::assertSame('App\\Nested', $steps[0]['args']['symbol']);
        }
    }

    public function testCanonicalNameWinsOverOtherNameKeys(): void
    {
        $steps = $this->plan('inspect_component', [
            'component' => ['display_name' => 'Checkout', 'name' => 'checkout', 'canonical_name' => 'App\\Checkout'],
        ]);

        self::assertSame('App\\Checkout', $steps[0]['args']['symbol']);
    }

    public function testFindAcceptsTheSyntheticCandidatesShape(): void
    {
        // Both the real `components` key and the `candidates` shape must work; if the
        // fallback silently produced an empty list this would report no suggestion.
        $steps = $this->plan('find_component', ['candidates' => [
            ['name' => 'App\\Checkout'],
            ['name' => 'App\\CheckoutController'],
        ]]);

        self::assertCount(1, $steps);
        self::assertSame('App\\Checkout', $steps[0]['args']['component']);
    }

    public function testMalformedCandidateListIsTreatedAsAbsent(): void
    {
        self::assertSame([], $this->plan('find_component', ['candidates' => 'not-a-list']));
    }

    public function testImpactPrefersTheRealTargetKeyOverTheSyntheticOne(): void
    {
        // `target` is what the query emits; `symbol` is the test-only shape. When both
        // are present the real key must win, not merely "one of them".
        $steps = $this->plan('impact_analysis', [
            'target' => 'App\\Real',
            'symbol' => 'App\\Synthetic',
            'direct_dependants' => [['canonical_name' => 'App\\Invoice']],
        ]);

        self::assertSame('App\\Real', $steps[0]['args']['from']);
    }

    public function testDossierWithoutTheHubFlagIsNotTreatedAsAHub(): void
    {
        // A component array that simply omits `is_hub` must not be assumed to be one.
        $steps = $this->plan('inspect_component', ['component' => ['canonical_name' => 'App\\Plain']]);

        self::assertCount(1, $steps);
        self::assertSame('impact_analysis', $steps[0]['tool']);
    }

    public function testNamesResolveThroughANestedComponentWrapper(): void
    {
        // `component` and `node` are both unwrapped when looking for a name.
        $steps = $this->plan('inspect_component', ['component' => ['component' => ['canonical_name' => 'App\\Deep']]]);

        self::assertSame('App\\Deep', $steps[0]['args']['symbol']);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{tool: string, args: array<string, mixed>, why: string}>
     */
    private function plan(string $tool, array $data): array
    {
        return $this->planner->plan($tool, new ResultEnvelope('p1', 's1', 'summary', $data));
    }
}
