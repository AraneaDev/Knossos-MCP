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

    /**
     * The caps must admit a frame that is exactly at the limit.
     *
     * The "well over the limit" cases above pass whether the comparison is `>` or
     * `>=`, so mutating one to the other left the tests green while the effective
     * cap silently moved by a byte. A limit is two claims — this is rejected, that
     * is accepted — and only the second one distinguishes them.
     */
    #[Group('limits')]
    public function testAFrameOfExactlyTheLineCapIsAccepted(): void
    {
        $tools = $this->toolService();
        $frame = self::initializeFrameOfExactly(256);
        assertSame(256, strlen($frame));

        $output = $this->runFrame($tools, $frame, maxLineBytes: 256);

        // Not a parse error: 256 bytes is at the cap, not past it.
        assertSame(false, isset($output['error']));
        assertSame('2025-11-25', $output['result']['protocolVersion']);
    }

    #[Group('limits')]
    public function testAFrameOneByteOverTheLineCapIsRejected(): void
    {
        $tools = $this->toolService();

        $output = $this->runFrame($tools, self::initializeFrameOfExactly(257), maxLineBytes: 256);

        assertSame(-32700, $output['error']['code']);
    }

    #[Group('limits')]
    public function testAResponseOfExactlyTheByteCapIsWrittenUnchanged(): void
    {
        $tools = $this->toolService();
        $frame = self::initializeFrameOfExactly(256);
        // Measure the real response rather than guessing its size: the cap is
        // compared against the encoded frame, so the boundary is whatever this
        // server actually produces.
        $uncapped = $this->runFrame($tools, $frame, maxLineBytes: 256);
        $size = strlen((string) json_encode($uncapped, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        $atCap = $this->runFrame($tools, $frame, maxLineBytes: 256, maxResponseBytes: $size);
        assertSame($uncapped, $atCap);

        $overCap = $this->runFrame($tools, $frame, maxLineBytes: 256, maxResponseBytes: $size - 1);
        assertSame(-32001, $overCap['error']['code']);
    }

    /**
     * Run one frame through a server with the given caps and decode its reply.
     *
     * @return array<string, mixed>
     */
    private function runFrame(ToolService $tools, string $frame, int $maxLineBytes = 1_048_576, int $maxResponseBytes = 1_048_576): array
    {
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'r+');
        $errors = fopen('php://temp', 'r+');
        fwrite($input, $frame);
        rewind($input);
        (new StdioServer($tools, maxLineBytes: $maxLineBytes, maxResponseBytes: $maxResponseBytes))->run($input, $output, $errors);
        rewind($output);

        return json_decode(trim((string) stream_get_contents($output)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A valid initialize frame padded to exactly $bytes, newline included.
     *
     * Padding lives in clientInfo.name, which the server echoes nowhere and
     * validates only as a string, so the frame stays legitimate at any size.
     */
    private static function initializeFrameOfExactly(int $bytes): string
    {
        $build = static fn(string $name): string => json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => $name, 'version' => '1']],
        ], JSON_THROW_ON_ERROR);
        $padding = $bytes - 1 - strlen($build(''));
        if ($padding < 0) {
            self::fail('A valid initialize frame does not fit in ' . $bytes . ' bytes.');
        }

        return $build(str_repeat('p', $padding)) . "\n";
    }

    private function toolService(): ToolService
    {
        $pdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();

        return new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
    }

}
