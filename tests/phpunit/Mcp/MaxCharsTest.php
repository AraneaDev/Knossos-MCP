<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ResultEnvelope;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class MaxCharsTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testEnricherTrimsLargestListToFitBudget(): void
    {
        $pdo = $this->freshTestDatabase();
        $enricher = new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner());
        $rows = array_map(static fn(int $i): array => ['name' => 'component_' . $i, 'padding' => str_repeat('x', 200)], range(1, 100));
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', ['components' => $rows, 'bounds' => ['limit' => 100]]);
        $result = $enricher->enrich($envelope, 'search_architecture', 'compact', 4000);
        assertSame(true, $result->truncated);
        assertSame(true, strlen((string) json_encode($result->jsonSerialize(), JSON_UNESCAPED_SLASHES)) <= 4000);
        assertSame(true, $result->meta['dropped_items']['components'] > 0);
        assertSame(4000, $result->meta['max_chars']);
        // Determinism: same input, same output.
        assertSame($result->jsonSerialize(), $enricher->enrich($envelope, 'search_architecture', 'compact', 4000)->jsonSerialize());
        // No budget: untouched.
        assertSame(100, count($enricher->enrich($envelope, 'search_architecture', 'compact')->data['components']));
    }

    #[Group('mcp')]
    public function testEnricherTrimsNestedListsToFitBudget(): void
    {
        $pdo = $this->freshTestDatabase();
        $enricher = new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner());
        $rows = array_map(static fn(int $i): array => ['name' => 'component_' . $i, 'padding' => str_repeat('x', 200)], range(1, 100));
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', [
            'change' => ['status' => 'complete', 'direct_components' => $rows, 'impacted_components' => $rows],
            'bounds' => ['limit' => 100],
        ]);
        $result = $enricher->enrich($envelope, 'review_diff', 'compact', 4000);
        assertSame(true, $result->truncated);
        assertSame(true, strlen((string) json_encode($result->jsonSerialize(), JSON_UNESCAPED_SLASHES)) <= 4000);
        assertSame(true, ($result->meta['dropped_items']['change.direct_components'] ?? 0) > 0);
        assertSame(true, ($result->meta['dropped_items']['change.impacted_components'] ?? 0) > 0);
        assertSame(false, in_array('The max_chars budget could not be fully met by trimming result lists.', $result->warnings, true));
        // Determinism: same input, same output.
        assertSame($result->jsonSerialize(), $enricher->enrich($envelope, 'review_diff', 'compact', 4000)->jsonSerialize());
    }

    #[Group('mcp')]
    public function testEnricherTrimsListsInsideListElementsToFitBudget(): void
    {
        $pdo = $this->freshTestDatabase();
        $enricher = new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner());
        $roles = array_map(static fn(int $i): array => ['role' => 'role_' . $i, 'padding' => str_repeat('x', 200)], range(1, 100));
        // impact_analysis shape: entry_points is a list whose elements each nest a roles list.
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', [
            'entry_points' => [['node' => ['name' => 'Entry'], 'roles' => $roles]],
            'bounds' => ['limit' => 100],
        ]);
        $result = $enricher->enrich($envelope, 'impact_analysis', 'compact', 4000);
        assertSame(true, strlen((string) json_encode($result->jsonSerialize(), JSON_UNESCAPED_SLASHES)) <= 4000);
        assertSame(true, ($result->meta['dropped_items']['entry_points.0.roles'] ?? 0) > 0);
        assertSame(false, in_array('The max_chars budget could not be fully met by trimming result lists.', $result->warnings, true));
    }

    #[Group('mcp')]
    public function testEnricherTrimsEvidenceListToFitBudget(): void
    {
        $pdo = $this->freshTestDatabase();
        $enricher = new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner());
        $evidence = array_map(static fn(int $i): array => ['path' => 'src/File' . $i . '.php', 'padding' => str_repeat('e', 200)], range(1, 100));
        // full verbosity keeps the evidence intact, so the budget must be met by
        // trimming the evidence list itself (there is no large data list here).
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', ['bounds' => ['limit' => 100]], $evidence);
        $result = $enricher->enrich($envelope, 'impact_analysis', 'full', 4000);
        assertSame(true, strlen((string) json_encode($result->jsonSerialize(), JSON_UNESCAPED_SLASHES)) <= 4000);
        assertSame(true, ($result->meta['dropped_items']['evidence'] ?? 0) > 0);
        assertSame(true, count($result->evidence) < 100);
        assertSame(count($result->evidence), $result->meta['evidence_shown']);
    }

    #[Group('mcp')]
    public function testEnricherTrimsSingleElementDominantList(): void
    {
        $pdo = $this->freshTestDatabase();
        $enricher = new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner());
        // A payload dominated by one single-element list must still be trimmable,
        // rather than reported as an unmet budget.
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', ['items' => [['blob' => str_repeat('z', 6000)]]]);
        $result = $enricher->enrich($envelope, 'search_architecture', 'compact', 4000);
        assertSame(0, count($result->data['items']));
        assertSame(true, ($result->meta['dropped_items']['items'] ?? 0) > 0);
        assertSame(false, in_array('The max_chars budget could not be fully met by trimming result lists.', $result->warnings, true));
    }

    #[Group('mcp')]
    public function testEnricherSurfacesUnmetBudgetWhenNoListIsTrimmable(): void
    {
        $pdo = $this->freshTestDatabase();
        $enricher = new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner());
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', ['bounds' => ['limit' => 100, 'blob' => str_repeat('y', 6000)]]);
        $result = $enricher->enrich($envelope, 'search_architecture', 'compact', 4000);
        assertSame(true, in_array('The max_chars budget could not be fully met by trimming result lists.', $result->warnings, true));
        assertSame(4000, $result->meta['max_chars']);
        assertSame(false, array_key_exists('dropped_items', $result->meta));
    }

    /**
     * The budget must cost decoration before it costs findings.
     *
     * Victim selection went by list length alone, and legends are the longest
     * thing in a bounded result, so trimming emptied the payload while every
     * legend entry survived. dependency_cycles came back saying "Found 16
     * dependency cycle components" over `cycles: []` — a summary flatly
     * contradicting the data a caller actually reads.
     */
    #[Group('mcp')]
    public function testLegendsAreTrimmedBeforeTheFindingsTheyAnnotate(): void
    {
        $pdo = $this->freshTestDatabase();
        $enricher = new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner());
        $cycles = array_map(
            static fn(int $i): array => ['size' => 2, 'members' => ['App\\A' . $i, 'App\\B' . $i]],
            range(1, 16),
        );
        $legend = [];
        foreach (range(1, 60) as $i) {
            $legend['App\\Component' . $i] = ['kind' => 'class', 'confidence' => 'certain', 'padding' => str_repeat('x', 120)];
        }
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'Found 16 dependency cycle components.', [
            'cycles' => $cycles,
            'component_legend' => $legend,
            'bounds' => ['limit' => 20],
        ]);

        $result = $enricher->enrich($envelope, 'dependency_cycles', 'compact', 4000);

        assertSame(true, strlen((string) json_encode($result->jsonSerialize(), JSON_UNESCAPED_SLASHES)) <= 4000);
        // The findings survive; the legend that merely annotates them pays first.
        assertSame(true, count($result->data['cycles']) > 0);
        assertSame(true, ($result->meta['dropped_items']['component_legend'] ?? 0) > 0);
    }

    /**
     * A caller who names no budget still gets a bounded result.
     *
     * Without a default, response size was bounded by the graph rather than by
     * anything the host could accept: changed_files_impact over a ten-file diff
     * serialized to ~70,000 characters and the client rejected the whole frame,
     * so the caller received nothing instead of a trimmed answer that reports
     * what it dropped.
     */
    #[Group('mcp')]
    public function testAToolCallWithoutMaxCharsIsStillBudgeted(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        foreach (range(1, 400) as $index) {
            $name = sprintf('App\\Generated\\VeryLongComponentName%04d', $index);
            $node = StableId::symbol($ids['project'], 'php', 'class', $name);
            $repository->saveNode($node, $ids['project'], 'php', 'class', $name, sprintf('VeryLongComponentName%04d', $index), null, $ids['file'], $index, $index + 5, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            $repository->saveEdge(StableId::edge($ids['project'], 'calls', $node, $ids['checkout'], (string) $index), $ids['project'], 'calls', $node, $ids['checkout'], $ids['file'], $index, $index, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );

        $usages = ['project_id' => $ids['project'], 'symbol' => 'App\\Checkout', 'limit' => 400, 'verbosity' => 'full'];
        $result = $tools->call('list_usages', $usages);

        assertSame(true, $result->meta['result_bytes'] <= 30_000);
        assertSame(30_000, $result->meta['max_chars']);
        assertSame(true, $result->truncated);
        // An explicit budget still wins: raising it returns what the default trimmed.
        $wider = $tools->call('list_usages', $usages + ['max_chars' => 100_000]);
        assertSame(true, $wider->meta['result_bytes'] > 30_000);
        assertSame(true, count($wider->data['usages']) > count($result->data['usages']));
    }

    #[Group('mcp')]
    public function testTrimmingConvergesInAFewPassesRatherThanOnePerDroppedItem(): void
    {
        // 800 items against a 4000-char budget: one item per pass meant ~780
        // full re-encodes of a shrinking payload plus two whole-tree walks each.
        $pdo = $this->freshTestDatabase();
        $passes = 0;
        $enricher = new ResultEnricher(
            new StalenessProbe($pdo),
            new NextStepPlanner(),
            static function (ResultEnvelope $candidate) use (&$passes): int {
                ++$passes;

                return strlen((string) json_encode($candidate->jsonSerialize(), JSON_UNESCAPED_SLASHES));
            },
        );
        $rows = array_map(static fn(int $i): array => ['name' => 'component_' . $i, 'padding' => str_repeat('x', 40)], range(1, 800));
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', ['components' => $rows]);

        $result = $enricher->enrich($envelope, 'search_architecture', 'compact', 4000);

        assertSame(true, strlen((string) json_encode($result->jsonSerialize(), JSON_UNESCAPED_SLASHES)) <= 4000);
        // The lower bound guards the seam itself: PHP silently discards a surplus
        // argument to a userland constructor, so an enricher that never wired the
        // measurer would leave the count at zero and satisfy the upper bound
        // without measuring anything.
        assertSame(true, $passes > 0);
        assertSame(true, $passes < 25);
    }

    #[Group('mcp')]
    public function testReportedResultBytesIncludesTheMetaBlock(): void
    {
        $pdo = $this->freshTestDatabase();
        $enricher = new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner());
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', ['components' => [1, 2, 3]]);

        $result = $enricher->enrich($envelope, 'search_architecture', 'compact', 30_000);

        assertSame(
            strlen((string) json_encode($result->jsonSerialize(), JSON_UNESCAPED_SLASHES)),
            $result->meta['result_bytes'],
        );
    }

    #[Group('mcp')]
    public function testToolServiceStripsAndValidatesMaxChars(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );
        $result = $tools->call('architecture_summary', ['project_id' => $ids['project'], 'max_chars' => 4000]);
        assertSame(true, $result->meta['result_bytes'] <= 4000);
        assertThrows(fn() => $tools->call('architecture_summary', ['project_id' => $ids['project'], 'max_chars' => 10]), InvalidArgumentException::class);
        // architecture_context keeps its own max_chars semantics (handler validates).
        assertSame($ids['project'], $tools->call('architecture_context', ['project_id' => $ids['project'], 'task_description' => 'checkout billing', 'max_chars' => 5000])->projectId);
    }
}
