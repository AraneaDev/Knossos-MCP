<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\StdioServer;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class LimitsTest extends KnossosTestCase
{
    #[Group('limits')]
    public function testProtocolByteCapsAndStableToolDiagnosticsContainFloods(): void
    {
        $pdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        $root = self::repositoryRoot() . '/tests/Fixtures/mixed';
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [$root]),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );

        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'r+');
        $errors = fopen('php://temp', 'r+');
        fwrite($input, str_repeat('x', 256) . "\n");
        rewind($input);
        (new StdioServer($tools, maxLineBytes: 128))->run($input, $output, $errors);
        rewind($output);
        $frame = json_decode(trim((string) stream_get_contents($output)), true, 512, JSON_THROW_ON_ERROR);
        assertSame(-32700, $frame['error']['code']);

        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'r+');
        $errors = fopen('php://temp', 'r+');
        fwrite($input, json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'cap', 'version' => '1']],
        ], JSON_THROW_ON_ERROR) . "\n");
        rewind($input);
        (new StdioServer($tools, maxResponseBytes: 100))->run($input, $output, $errors);
        rewind($output);
        $frame = json_decode(trim((string) stream_get_contents($output)), true, 512, JSON_THROW_ON_ERROR);
        assertSame(-32001, $frame['error']['code']);
        assertContains('byte limit', $frame['error']['message']);
    }
}
