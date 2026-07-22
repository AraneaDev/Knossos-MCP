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
        assertSame(true, $result->meta['result_bytes'] <= 4000);
        assertSame(true, $result->meta['dropped_items']['components'] > 0);
        assertSame(4000, $result->meta['max_chars']);
        // Determinism: same input, same output.
        assertSame($result->jsonSerialize(), $enricher->enrich($envelope, 'search_architecture', 'compact', 4000)->jsonSerialize());
        // No budget: untouched.
        assertSame(100, count($enricher->enrich($envelope, 'search_architecture', 'compact')->data['components']));
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
