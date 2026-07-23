<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class SuggestionTest extends KnossosTestCase
{
    #[Group('suggestion')]
    public function testLocationSuggestionsRankDeterministicFactorsAgainstTheEvaluationSet(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->saveEdge(
            StableId::edge($ids['project'], 'calls', $ids['invoice'], $ids['checkout'], 'evaluation-reverse'),
            $ids['project'],
            'calls',
            $ids['invoice'],
            $ids['checkout'],
            $ids['file'],
            30,
            30,
            'ast',
            'certain',
            [],
            'evaluation:file:src/InvoiceService.php',
            $ids['scan'],
        );
        $repository->saveClassification(
            StableId::classification($ids['project'], $ids['checkout'], 'application.checkout', 'evaluation.roles'),
            $ids['project'],
            $ids['checkout'],
            'application.checkout',
            'user_rule',
            'certain',
            'evaluation.roles',
            $ids['file'],
            3,
            18,
            [],
            $ids['scan'],
        );
        $repository->completeScan($ids['project'], $ids['scan']);

        $evaluationJson = file_get_contents(self::repositoryRoot() . '/tests/Fixtures/evaluation/suggest-location.json');
        if (!is_string($evaluationJson)) {
            throw new RuntimeException('Unable to read location evaluation set.');
        }
        $evaluation = json_decode($evaluationJson, true, 32, JSON_THROW_ON_ERROR);
        $query = new ArchitectureQueryService($pdo);
        foreach ($evaluation as $case) {
            $first = $query->suggestLocation($ids['project'], $case['feature_description']);
            $second = $query->suggestLocation($ids['project'], $case['feature_description']);
            assertSame($case['expected_boundary'], $first->data['candidates'][0]['boundary']['name']);
            assertSame($first->data['candidates'], $second->data['candidates']);
            assertSame(true, $first->data['candidates'][0]['score'] > 0);
            assertSame(true, count($first->data['candidates'][0]['matched_tokens']) >= 1);
            assertSame(true, count($first->evidence) >= 1);
        }
        $billingResult = $query->suggestLocation($ids['project'], 'build invoice billing workflow');
        assertSame(12, $billingResult->data['candidates'][0]['factors']['boundary_name_relevance']);
        assertSame('probable', $billingResult->data['candidates'][0]['confidence']);
        assertContains('uniquely correct', $billingResult->warnings[0]);

        $limited = $query->suggestLocation($ids['project'], 'checkout service', limit: 1);
        assertSame(true, $limited->truncated);
        assertSame(true, in_array('result_limit', $limited->data['bounds']['truncation_reasons'], true));
        $memberLimited = $query->suggestLocation($ids['project'], 'checkout service', maxMembers: 1);
        assertSame(true, $memberLimited->truncated);
        assertSame(true, in_array('member_limit', $memberLimited->data['bounds']['truncation_reasons'], true));
        $edgeLimited = $query->suggestLocation($ids['project'], 'checkout service', maxEdges: 1);
        assertSame(true, in_array('edge_limit', $edgeLimited->data['bounds']['truncation_reasons'], true));
        assertThrows(fn() => $query->suggestLocation($ids['project'], 'a i u'), InvalidArgumentException::class);

        $time = 0;
        $timedQuery = new ArchitectureQueryService($pdo, function () use (&$time): int {
            $time += 2_000_000;
            return $time;
        });
        $timed = $timedQuery->suggestLocation($ids['project'], 'checkout service', timeoutMs: 1);
        assertSame(true, $timed->truncated);
        assertSame(true, in_array('time_limit', $timed->data['bounds']['truncation_reasons'], true));
    }

    #[Group('suggestion')]
    public function testFeatureTokensDropStopWordsAndShortTokens(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $query = new ArchitectureQueryService($pdo);

        $result = $query->suggestLocation($ids['project'], 'A new exporter that renders the graph as DOT source');

        $tokens = $result->data['tokens'];
        assertSame(false, in_array('as', $tokens, true));
        assertSame(false, in_array('that', $tokens, true));
        assertSame(false, in_array('the', $tokens, true));
        assertSame(true, in_array('exporter', $tokens, true));
        assertSame(true, in_array('graph', $tokens, true));
        assertSame(true, in_array('dot', $tokens, true));
    }

    #[Group('suggestion')]
    public function testAllStopWordDescriptionFallsBackToUnfilteredTokens(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $query = new ArchitectureQueryService($pdo);

        // Every word is either a stop word or shorter than three characters;
        // the fallback keeps the >= 2-char tokens instead of erroring.
        $result = $query->suggestLocation($ids['project'], 'add new ui db');

        assertSame(['add', 'new', 'ui', 'db'], $result->data['tokens']);
    }
}
