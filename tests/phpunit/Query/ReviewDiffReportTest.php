<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * What a review reports: the sentence it leads with, the keys it carries, and
 * the bound it scanned cycles under.
 *
 * ReviewDiffService scored 41% under mutation testing, the lowest in the tree.
 * Its tests assert section statuses and counts, and never read the summary at
 * all, so every singular and plural in it, and the word that reports whether the
 * gate passed, could change with them green. The same is true of the bound the
 * report advertises and of which change keys reach the caller.
 */
final class ReviewDiffReportTest extends KnossosTestCase
{
    /** The summary counts changed files, and says so in the right number. */
    #[Group('query')]
    public function testTheSummaryAgreesInNumberWithTheFilesItCounted(): void
    {
        $queries = $this->queries();

        $one = $queries->reviewDiff($this->project, files: ['src/Checkout.php']);
        assertSame(true, str_starts_with($one->summary, '1 changed file, '), $one->summary);

        $two = $queries->reviewDiff($this->project, files: ['src/Checkout.php', 'src/Missing.php']);
        assertSame(true, str_starts_with($two->summary, '2 changed files, '), $two->summary);
    }

    /** With nothing to evaluate, the summary says so rather than implying a verdict. */
    #[Group('query')]
    public function testAnUnevaluatedReviewSaysSoInTheSummary(): void
    {
        $result = $this->queries()->reviewDiff($this->project, files: ['src/Checkout.php']);

        assertSame(true, str_contains($result->summary, ', policies not evaluated, '), $result->summary);
        assertSame(true, str_ends_with($result->summary, 'gate not evaluated.'), $result->summary);
    }

    /**
     * A gate that ran reports its verdict in the summary, in the word the
     * caller reads first.
     */
    #[Group('query')]
    public function testAnEvaluatedGateReportsItsVerdictInTheSummary(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        // Archive the first snapshot so it survives as the baseline once a
        // second scan takes over as the active one.
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $second = StableId::scan($ids['project'], 'scan-2');
        $repository->createScan($second, $ids['project'], 'full', hash('sha256', 'scanner-set'));
        $repository->completeScan($ids['project'], $second);

        $result = (new ArchitectureQueryService($pdo))->reviewDiff(
            $ids['project'],
            files: ['src/Checkout.php'],
            budgets: ['new_cycles' => 0],
        );

        assertSame('evaluated', $result->data['quality_gate']['status']);
        assertSame(true, $result->data['quality_gate']['passed'], 'No cycles were added, so a zero-new-cycles budget holds.');
        assertSame(true, str_ends_with($result->summary, 'gate passed.'), $result->summary);
    }

    /**
     * The report carries the change keys a reviewer needs and nothing else, and
     * advertises the bound its cycle scan ran under.
     */
    #[Group('query')]
    public function testTheReportCarriesTheChangeKeysAndTheCycleBound(): void
    {
        $result = $this->queries()->reviewDiff($this->project, files: ['src/Checkout.php']);

        assertSame(
            ['status', 'changed_files', 'unresolved_files', 'direct_components', 'impacted_components', 'git'],
            array_keys($result->data['change']),
            'The change section is a selection, not the whole impact payload.',
        );
        assertSame(['change', 'policy_check', 'quality_gate', 'cycles_touching_change', 'bounds'], array_keys($result->data));
        assertSame(100, $result->data['bounds']['cycle_scan_limit']);
        // The de-duplication of warnings is deliberately not asserted. No two
        // sections emit the same caveat against this fixture, so any assertion
        // about it would hold whether the envelope de-duplicates or not, and
        // would pin nothing. Measured: that mutant survives these tests.
    }

    /**
     * The totals came from the policy check's first page of 100 violations,
     * and the touching ones were filtered out of that page, so a change behind
     * the hundredth violation read as clean.
     */
    #[Group('query')]
    public function testTotalsCountPastTheFirstPage(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $left = StableId::boundary($project, 'Left', 'explicit');
        $right = StableId::boundary($project, 'Right', 'explicit');
        $repository->saveBoundary($left, $project, 'Left', ['path_prefix' => 'src/Left'], 'explicit', $ids['scan']);
        $repository->saveBoundary($right, $project, 'Right', ['path_prefix' => 'src/Right'], 'explicit', $ids['scan']);
        $target = $this->classIn($repository, $ids, 'src/Right.php', 'App\\Right');
        $repository->saveBoundaryMembership($right, $project, $target, $ids['scan']);
        $repository->saveBoundaryMembership($left, $project, $ids['checkout'], $ids['scan']);
        $this->calls($repository, $ids, $ids['checkout'], $target);
        foreach (range(1, 150) as $index) {
            $caller = $this->classIn($repository, $ids, 'src/Left.php', sprintf('App\\Left%03d', $index));
            $repository->saveBoundaryMembership($left, $project, $caller, $ids['scan']);
            $this->calls($repository, $ids, $caller, $target);
        }
        $repository->completeScan($project, $ids['scan']);
        $policies = [['id' => 'left-not-right', 'from_boundary' => 'Left', 'deny_targets' => ['Right']]];

        $review = (new ArchitectureQueryService($pdo))->reviewDiff($project, files: ['src/Checkout.php'], policies: $policies);

        assertSame(151, $review->data['policy_check']['total_violations']);
        assertSame(1, $review->data['policy_check']['touching_violation_count']);
        assertSame(1, count($review->data['policy_check']['violations_touching_change']));
        assertSame('App\\Checkout', $review->data['policy_check']['violations_touching_change'][0]['source']['canonical_name']);
        assertSame(true, str_contains($review->summary, '1 policy violation touching the change'), $review->summary);
    }

    /**
     * A cycle was matched against its first 100 listed members, so a change to
     * a member past them, in a larger cycle, found no cycle at all.
     */
    #[Group('query')]
    public function testACycleIsMatchedByMembersBeyondTheFirstHundred(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $ring = [];
        foreach (range(0, 149) as $index) {
            $ring[] = $this->classIn($repository, $ids, 'src/Ring.php', sprintf('App\\Ring%03d', $index));
        }
        foreach ($ring as $index => $node) {
            $this->calls($repository, $ids, $node, $ring[($index + 1) % 150]);
        }
        $repository->completeScan($project, $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);
        $cycle = $queries->dependencyCycles($project, [], 'possible', 100, 50_000, 100_000, 1000)->data['cycles'][0];
        $shown = array_fill_keys(array_column($cycle['members'], 'id'), true);
        // A member that is not listed and whose dependants within the review's
        // depth of 1 are not listed either, so only member_ids can match it.
        $hidden = null;
        foreach ($ring as $index => $node) {
            if (!isset($shown[$node]) && !isset($shown[$ring[($index + 149) % 150]])) {
                $hidden = $index;
                break;
            }
        }
        assertSame(true, $hidden !== null, 'The fixture needs a member beyond the listed hundred.');
        $file = StableId::file($project, 'src/Hidden.php');
        $repository->saveFile($file, $project, 'src/Hidden.php', hash('sha256', 'hidden'), 10, 1, 'php', '0.1.0', $ids['scan']);
        $name = sprintf('App\\Ring%03d', $hidden);
        $repository->saveNode($ring[$hidden], $project, 'php', 'class', $name, $name, null, $file, 1, 1, 'ast', 'certain', [], 'php:file:src/Hidden.php', $ids['scan']);

        $review = $queries->reviewDiff($project, files: ['src/Hidden.php'], maxDepth: 1);

        assertSame(1, count($review->data['cycles_touching_change']['cycles']));
    }

    /** @param array<string, string> $ids */
    private function classIn(\Knossos\Store\GraphRepository $repository, array $ids, string $path, string $name): string
    {
        $file = StableId::file($ids['project'], $path);
        $repository->saveFile($file, $ids['project'], $path, hash('sha256', $path), 10, 1, 'php', '0.1.0', $ids['scan']);
        $id = StableId::symbol($ids['project'], 'php', 'class', $name);
        $repository->saveNode($id, $ids['project'], 'php', 'class', $name, $name, null, $file, 1, 1, 'ast', 'certain', [], 'php:file:' . $path, $ids['scan']);
        return $id;
    }

    /** @param array<string, string> $ids */
    private function calls(\Knossos\Store\GraphRepository $repository, array $ids, string $source, string $target): void
    {
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $source, $target, 'review'), $ids['project'], 'calls', $source, $target, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Review.php', $ids['scan']);
    }

    private string $project = '';

    private function queries(): ArchitectureQueryService
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $this->project = $ids['project'];

        return new ArchitectureQueryService($pdo);
    }
}
