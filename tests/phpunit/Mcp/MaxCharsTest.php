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
        $rows = array_map(static fn(int $i): array => ['name' => 'dependant_' . $i, 'padding' => str_repeat('x', 200)], range(1, 100));
        // impact_analysis shape: one distance group holding the entire result set.
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', [
            'by_distance' => [['distance' => 1, 'dependants' => $rows]],
            'bounds' => ['limit' => 100],
        ]);
        $result = $enricher->enrich($envelope, 'impact_analysis', 'compact', 4000);
        assertSame(true, strlen((string) json_encode($result->jsonSerialize(), JSON_UNESCAPED_SLASHES)) <= 4000);
        assertSame(true, ($result->meta['dropped_items']['by_distance.0.dependants'] ?? 0) > 0);
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
