<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\{NextStepPlanner, ResultEnricher, ToolService};
use Knossos\Query\{ArchitectureQueryService, StalenessProbe};
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A page trimmed to fit `max_chars` points its cursor at the first item it
 * left out. Pointing past the trimmed items, a caller following the cursor
 * never saw them.
 */
#[Group('mcp')]
final class TrimmedPaginationTest extends KnossosTestCase
{
    public function testATrimmedPageResumesAtTheFirstItemItLeftOut(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $insert = $pdo->prepare("INSERT INTO diagnostics (id, project_id, scan_id, file_id, severity, code, message, start_line, end_line, owner_key) VALUES (?, ?, ?, ?, 'warning', 'W', ?, ?, ?, 'test')");
        for ($i = 1; $i <= 60; $i++) {
            $insert->execute(['d' . $i, $ids['project'], $ids['scan'], $ids['file'], str_repeat('x', 200), $i, $i]);
        }
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );

        $page = $tools->call('list_diagnostics', ['project_id' => $ids['project'], 'max_chars' => 4000, 'limit' => 50]);
        $shown = count($page->data['diagnostics']);
        self::assertLessThan(50, $shown);
        self::assertSame($shown, $page->data['pagination']['next_offset']);
        $next = $tools->call('list_diagnostics', ['project_id' => $ids['project'], 'max_chars' => 4000, 'limit' => 50, 'offset' => $shown]);
        self::assertSame($shown + 1, $next->data['diagnostics'][0]['line']);
    }
}
