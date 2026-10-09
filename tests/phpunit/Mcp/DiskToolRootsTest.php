<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Discovery\AllowedRoots;
use Knossos\Discovery\DiscoveryException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\McpServerAssembly;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ResultEnvelope;
use Knossos\Query\StalenessProbe;
use Knossos\Runtime\ServerEnvironment;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\CountingDriftOracle;
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

    /**
     * The staleness probe ran git and a directory walk in every project in the
     * database. On the MCP path neither refresh_if_stale nor the result enricher
     * may probe a project outside the roots; the result reports unverified.
     */
    #[Group('mcp')]
    public function testNoToolCallProbesAProjectOutsideTheRoots(): void
    {
        [$calls, $result] = $this->findComponentProbed(static fn(string $root): array => ['/nonexistent-allowed-root']);

        assertSame(0, $calls, 'Neither refresh_if_stale nor the result enricher may probe it.');
        assertSame('unverified', $result->staleness['state']);
        assertSame([], $result->warnings, 'The skipped refresh is not a warning: the staleness already says why.');
    }

    /** Inside the roots, the same call still probes. */
    #[Group('mcp')]
    public function testAToolCallStillProbesAProjectInsideTheRoots(): void
    {
        [$calls, $result] = $this->findComponentProbed(static fn(string $root): array => [$root]);

        assertSame(true, $calls >= 1);
        assertSame('fresh', $result->staleness['state']);
    }

    /** The server the transports actually run is wired with the confined probe. */
    #[Group('mcp')]
    public function testTheAssembledServerReportsAProjectOutsideTheRootsAsUnverified(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-probe-assembly-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/mixed', $root);
        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $assembly = new McpServerAssembly($pdo, self::repositoryRoot(), ':memory:', new AllowedRoots(['/nonexistent-allowed-root']));

            $result = $assembly->tools->call('architecture_summary', ['project_id' => $projectId]);

            assertSame('unverified', $result->staleness['state']);
            assertSame(true, str_contains($result->staleness['guidance'], 'allowed roots'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Scan a copy of the mixed fixture, then call find_component on it through
     * a server whose roots $allowedRoots derives from that copy's path, with
     * one counting oracle behind both probe entry points.
     *
     * @param \Closure(string): list<string> $allowedRoots
     * @return array{int, ResultEnvelope} the oracle's call count and the result
     */
    private function findComponentProbed(\Closure $allowedRoots): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-probe-roots-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/mixed', $root);
        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $counting = new CountingDriftOracle();
            $environment = new ServerEnvironment(new AllowedRoots($allowedRoots($root)), ':memory:', self::repositoryRoot(), $pdo);
            $tools = new ToolService(
                new ProjectScanService($pdo, self::repositoryRoot(), $allowedRoots($root)),
                new ArchitectureQueryService($pdo, driftOracle: $counting),
                new DatabaseMaintenanceService($pdo, ':memory:'),
                new ResultEnricher(new StalenessProbe($pdo, oracle: $counting, rootAdmitted: $environment->admitsRoot(...)), new NextStepPlanner()),
                $environment,
            );
            $result = $tools->call('find_component', ['project_id' => $projectId, 'name' => 'Checkout']);

            return [$counting->calls, $result];
        } finally {
            $this->removeTempTree($root);
        }
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
