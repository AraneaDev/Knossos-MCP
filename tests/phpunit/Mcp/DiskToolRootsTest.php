<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Discovery\AllowedRoots;
use Knossos\Discovery\DiscoveryException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\StalenessProbe;
use Knossos\Runtime\ServerEnvironment;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A server's allowed roots bound what it reads from disk, not only what it
 * scans: the database is shared with the CLI, so it can hold projects from
 * anywhere on the machine.
 */
final class DiskToolRootsTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testDiskReadingToolsRefuseAProjectOutsideTheServersRoots(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-disk-roots-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/mixed', $root);
        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $confined = $this->toolsConfinedTo($pdo, ['/nonexistent-allowed-root']);

            foreach ([
                ['file_context', ['project_id' => $projectId, 'path' => 'src/CheckoutService.php']],
                ['file_context', ['project_id' => " {$projectId} ", 'path' => 'src/CheckoutService.php']],
                ['test_impact', ['project_id' => $projectId, 'files' => ['src/CheckoutService.php']]],
                ['architecture_context', ['project_id' => $projectId, 'task_description' => 'checkout', 'include_source' => true]],
            ] as [$name, $arguments]) {
                assertThrows(fn() => $confined->call($name, $arguments), DiscoveryException::class);
            }

            // Graph-only answers stay available for every project in the database.
            assertSame($projectId, $confined->call('architecture_summary', ['project_id' => $projectId])->projectId);
            assertSame($projectId, $confined->call('architecture_context', ['project_id' => $projectId, 'task_description' => 'checkout'])->projectId);

            // Inside the roots, the same disk-reading call is allowed.
            assertSame($projectId, $this->toolsConfinedTo($pdo, [$root])->call('file_context', ['project_id' => $projectId, 'path' => 'src/CheckoutService.php'])->projectId);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A root that no longer exists is the tool's own "missing" answer, not an "outside the roots" refusal. */
    #[Group('mcp')]
    public function testAVanishedRootIsNotReportedAsOutsideTheRoots(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-disk-roots-gone-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/mixed', $root);
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
        $this->removeTempTree($root);

        try {
            $this->toolsConfinedTo($pdo, ['/nonexistent-allowed-root'])->call('file_context', ['project_id' => $projectId, 'path' => 'src/CheckoutService.php']);
        } catch (DiscoveryException $error) {
            self::fail('A vanished root was reported as outside the allowed roots: ' . $error->getMessage());
        } catch (\Throwable) {
            // Any other failure is the tool's own handling of a missing root.
        }
        $this->addToAssertionCount(1);
    }

    /** @param list<string> $roots */
    private function toolsConfinedTo(PDO $pdo, array $roots): ToolService
    {
        return new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), $roots),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
            new ServerEnvironment(new AllowedRoots($roots), ':memory:', self::repositoryRoot(), $pdo),
        );
    }
}
