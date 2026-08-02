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

    /**
     * Token matching must respect identifier word boundaries. Plain substring
     * matching made "ndjson" match `testRunWithVersionAndJsonFlagReturnsZero`,
     * because "AndJson" lowercases to a string containing "ndjson" — so an
     * unrelated test class scored as evidence for an NDJSON feature.
     */
    #[Group('suggestion')]
    public function testTokensMatchIdentifierWordsRatherThanBareSubstrings(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->boundaryWithMembers($repository, $ids, 'coincidence', [
            'App\\Tests\\ApplicationTest::testRunWithVersionAndJsonFlagReturnsZero',
        ]);
        $this->boundaryWithMembers($repository, $ids, 'transport', [
            'App\\Transport\\NdjsonRpcChannel',
        ]);
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = (new ArchitectureQueryService($pdo))->suggestLocation($ids['project'], 'ndjson channel');
        $byName = [];
        foreach ($result->data['candidates'] as $candidate) {
            $byName[$candidate['boundary']['name']] = $candidate;
        }

        // "AndJson" is not the word "ndjson".
        assertSame([], $byName['coincidence']['related_members']);
        assertSame(false, in_array('ndjson', $byName['coincidence']['matched_tokens'], true));
        // The real one still matches, split out of NdjsonRpcChannel.
        assertSame(true, in_array('ndjson', $byName['transport']['matched_tokens'], true));
    }

    /**
     * Both sides of the comparison must lower-case the same way.
     *
     * Member words go through `mb_strtolower`, so `ÉclairService` indexes as
     * `éclair`. The description went through ASCII-only `strtolower`, which
     * leaves `É` untouched, so the query token stayed `Éclair` and could never
     * match the word it names — silently, and only for non-ASCII identifiers.
     */
    #[Group('suggestion')]
    public function testANonAsciiQueryTokenMatchesTheIdentifierWordItNames(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->boundaryWithMembers($repository, $ids, 'patisserie', [
            'App\\Bakery\\EclairService',
            'App\\Bakery\\ÉclairService',
        ]);
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = (new ArchitectureQueryService($pdo))->suggestLocation($ids['project'], 'Éclair service');
        $byName = [];
        foreach ($result->data['candidates'] as $candidate) {
            $byName[$candidate['boundary']['name']] = $candidate;
        }

        assertSame(true, in_array('éclair', $byName['patisserie']['matched_tokens'], true));
    }

    /**
     * Member relevance summed over a boundary's members, so the score grew with
     * boundary size and the widest boundary always won. Asking where a new
     * worker belongs then returned the repository-root boundary — matcher
     * `path_prefix: ""`, meaning "anywhere" — ahead of `workers/`.
     */
    #[Group('suggestion')]
    public function testAFocusedBoundaryOutranksAWiderOneThatMerelyContainsIt(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $focused = ['App\\Alpha\\WorkerHost', 'App\\Alpha\\WorkerPool'];
        // The wide boundary contains the focused members plus much unrelated
        // material, exactly as a repository-root boundary contains every module.
        $wide = [...$focused, 'App\\Wide\\WorkerAudit', 'App\\Wide\\WorkerNote', 'App\\Wide\\WorkerTag'];
        foreach (range(1, 7) as $index) {
            $wide[] = 'App\\Wide\\Unrelated' . $index;
        }
        $this->boundaryWithMembers($repository, $ids, 'alpha', $focused);
        $this->boundaryWithMembers($repository, $ids, 'omnibus', $wide);
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = (new ArchitectureQueryService($pdo))->suggestLocation($ids['project'], 'worker process');

        assertSame('alpha', $result->data['candidates'][0]['boundary']['name']);
    }

    /**
     * Declare a boundary owning nodes with the given canonical names.
     *
     * @param list<string> $canonicalNames
     */
    private function boundaryWithMembers(object $repository, array $ids, string $name, array $canonicalNames): void
    {
        $boundary = StableId::boundary($ids['project'], $name, 'explicit');
        $repository->saveBoundary($boundary, $ids['project'], $name, ['path_prefix' => 'src/' . $name], 'explicit', $ids['scan']);
        foreach ($canonicalNames as $canonicalName) {
            $node = StableId::symbol($ids['project'], 'php', 'class', $canonicalName);
            $separator = strrpos($canonicalName, '\\');
            $repository->saveNode(
                $node,
                $ids['project'],
                'php',
                'class',
                $canonicalName,
                $separator === false ? $canonicalName : substr($canonicalName, $separator + 1),
                null,
                $ids['file'],
                1,
                2,
                'ast',
                'certain',
                [],
                'php:file:src/Checkout.php',
                $ids['scan'],
            );
            $repository->saveBoundaryMembership($boundary, $ids['project'], $node, $ids['scan']);
        }
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
