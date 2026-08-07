<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class RefreshIfStaleTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testStaleGraphIsRescannedBeforeAnswering(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, "\n// drift\n", FILE_APPEND);
            touch($file, filemtime($file) + 60);
            assertSame('stale', (new StalenessProbe($pdo))->probe($projectId)['state']);

            $result = $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);

            assertSame('fresh', $result->staleness['state']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('mcp')]
    public function testFreshGraphSkipsRescanAndFailedRescanWarnsInsteadOfErroring(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $scans = fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();

            $before = $scans();
            $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);
            assertSame($before, $scans()); // fresh: no rescan attempted

            // Stale project whose root is outside the scanner's allowed roots:
            // the rescan fails, the query still answers, and a warning explains.
            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, "\n// drift\n", FILE_APPEND);
            touch($file, filemtime($file) + 60);
            $confined = new ToolService(
                new ProjectScanService($pdo, self::repositoryRoot(), ['/nonexistent-allowed-root']),
                new ArchitectureQueryService($pdo),
                new DatabaseMaintenanceService($pdo, ':memory:'),
                new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
            );
            $result = $confined->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);
            assertSame($projectId, $result->projectId);
            $joined = implode(' ', $result->warnings);
            assertSame(true, str_contains($joined, 'refresh_if_stale'));

            assertThrows(fn() => $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => 'yes']), InvalidArgumentException::class);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
