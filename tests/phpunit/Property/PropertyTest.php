<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Property;

use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\JsonConfig;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\StdioServer;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class PropertyTest extends KnossosTestCase
{
    #[Group('property')]
    public function testJsoncRandomizedStringsRetainCommentTokensAndTrailingCommaSemantics(): void
    {
        for ($case = 0; $case < 200; ++$case) {
            $token = sprintf('https://example.test/%d/*literal*/ // value', $case);
            $json = sprintf("{\n// generated comment\n\"token\":%s,\"values\":[%d,%d,],\n}\n", json_encode($token, JSON_THROW_ON_ERROR), $case, $case + 1);
            $decoded = JsonConfig::decode($json, true);
            assertSame($token, $decoded['token']);
            assertSame([$case, $case + 1], $decoded['values']);
        }
    }

    #[Group('property')]
    public function testSeededMalformedConfigurationAndContributionCorporaFailClosed(): void
    {
        $root = sys_get_temp_dir() . '/knossos-invalid-config-' . bin2hex(random_bytes(6));
        if (!mkdir($root, 0700)) {
            throw new RuntimeException('Unable to create fuzz configuration fixture.');
        }
        $invalidConfigurations = [
            ['version' => 2],
            ['version' => 1, 'unknown' => true],
            ['version' => 1, 'ignores' => ['../escape']],
            ['version' => 1, 'limits' => ['max_files' => 0]],
            ['version' => 1, 'boundaries' => [['name' => 'missing matcher']]],
            ['version' => 1, 'frameworks' => ['dynamic-framework']],
            ['version' => 1, 'quality_budgets' => ['new_cycles' => -1]],
        ];
        try {
            for ($case = 0; $case < 140; ++$case) {
                $configuration = $invalidConfigurations[$case % count($invalidConfigurations)];
                file_put_contents($root . '/knossos.json', json_encode($configuration, JSON_THROW_ON_ERROR));
                $error = captureThrows(fn() => ProjectConfigurationLoader::load($root, [$root]), DiscoveryException::class);
                assertSame(true, str_starts_with($error->getMessage(), 'PROJECT_CONFIG_'));

                $invalidContribution = [
                    'owner_key' => $case % 3 === 0 ? '' : 'owner',
                    'nodes' => $case % 3 === 1 ? 'not-a-list' : [],
                    'edges' => [],
                    'diagnostics' => $case % 3 === 2 ? [['severity' => 1]] : [],
                ];
                $workerError = captureThrows(
                    fn() => \Knossos\Scanner\Worker\ContributionDecoder::decode($invalidContribution),
                    WorkerException::class,
                );
                assertSame('WORKER_CONTRIBUTION_INVALID', $workerError->diagnosticCode);
            }
        } finally {
            @unlink($root . '/knossos.json');
            @rmdir($root);
        }
    }

    #[Group('property')]
    public function testStableIdentifierPropertiesAreDeterministicDomainSeparatedAndCollisionFree(): void
    {
        $seen = [];
        for ($case = 0; $case < 1000; ++$case) {
            $root = sprintf('/workspace/generated/%08x', $case * 2654435761 & 0xffffffff);
            $project = StableId::project($root);
            assertSame($project, StableId::project($root));
            assertSame(false, isset($seen[$project]));
            $seen[$project] = true;
            assertNotSame($project, StableId::scan($project, 'same-input'));
            assertNotSame(StableId::file($project, 'same-input'), StableId::symbol($project, 'php', 'file', 'same-input'));
        }
    }

    #[Group('property')]
    public function testSeededEditSequencesKeepIncrementalAndFullGraphsEquivalent(): void
    {
        $root = sys_get_temp_dir() . '/knossos-incremental-' . bin2hex(random_bytes(6));
        $database = tempnam(sys_get_temp_dir(), 'knossos-differential-');
        if ($database === false || !mkdir($root . '/src', 0700, true)) {
            throw new RuntimeException('Unable to create differential fixture.');
        }
        file_put_contents($root . '/composer.json', '{"name":"fixture/differential","autoload":{"psr-4":{"Fixture\\\\":"src/"}}}');
        foreach (range(0, 4) as $index) {
            file_put_contents(
                sprintf('%s/src/Service%d.php', $root, $index),
                sprintf("<?php\nnamespace Fixture;\nfinal class Service%d { public const REVISION = 0; }\n", $index),
            );
        }
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $service->scan($root, mode: 'full');
            $state = 0xC0FFEE;
            for ($round = 1; $round <= 5; ++$round) {
                $state = (int) (($state * 1664525 + 1013904223) & 0x7fffffff);
                $index = $state % 5;
                file_put_contents(
                    sprintf('%s/src/Service%d.php', $root, $index),
                    sprintf("<?php\nnamespace Fixture;\nfinal class Service%d { public const REVISION = %d; }\n", $index, $round),
                );
                $incremental = $service->scan($root, mode: 'incremental');
                assertSame('incremental', $incremental->data['mode']);
                assertSame(1, $incremental->data['parsed_files']);
                $incrementalGraph = $this->graphSignature($pdo);
                $service->scan($root, mode: 'full');
                assertSame($incrementalGraph, $this->graphSignature($pdo));
            }
        } finally {
            unset($service, $pdo);
            $this->removeFixtureTree($root);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                @unlink($candidate);
            }
        }
    }

    #[Group('property')]
    public function testSeededJsonRpcMessageShapesReturnBoundedProtocolResponses(): void
    {
        [$pdo] = $this->storeFixture();
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
        $server = new StdioServer($tools, maxResponseBytes: 4096);
        $templates = [
            [],
            ['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 17],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'initialize', 'params' => []],
            ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping', 'params' => [1, 2]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => ['nested']]],
            ['jsonrpc' => '2.0', 'id' => 5, 'method' => str_repeat('x', 1000)],
        ];
        for ($case = 0; $case < 700; ++$case) {
            $message = $templates[$case % count($templates)];
            if (isset($message['id'])) {
                $message['id'] = $case;
            }
            $response = $server->handle($message);
            if ($response !== null) {
                assertSame('2.0', $response['jsonrpc']);
                assertSame(true, strlen(json_encode($response, JSON_THROW_ON_ERROR)) < 4096);
                assertSame(true, isset($response['error']) || isset($response['result']));
            }
        }
    }
}
