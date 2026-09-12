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

    private string $project = '';

    private function queries(): ArchitectureQueryService
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $this->project = $ids['project'];

        return new ArchitectureQueryService($pdo);
    }
}
