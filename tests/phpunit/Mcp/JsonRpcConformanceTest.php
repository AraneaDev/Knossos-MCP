<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\StdioServer;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use stdClass;

/**
 * The error codes a client receives, which JSON-RPC 2.0 fixes by number.
 *
 * A client dispatches on `error.code`, not on the message text. The existing
 * transport test asserted only that "Parse error" and "Method not found"
 * appeared in the output, so every numeric code could be changed to its
 * neighbour with the suite still green: a parse error reported as -32701 is,
 * to a client, not a parse error at all.
 */
final class JsonRpcConformanceTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testAFrameThatIsNotJsonIsAParseError(): void
    {
        assertSame(-32700, $this->frames(["not-json\n"])[0]['error']['code']);
    }

    /** A message must be an object; a JSON array or a bare scalar is a parse error, not an invalid request. */
    #[Group('mcp')]
    public function testAJsonValueThatIsNotAnObjectIsAParseError(): void
    {
        $frames = $this->frames(["[1,2]\n", "5\n"]);

        assertSame(-32700, $frames[0]['error']['code'], 'A JSON array is not a JSON-RPC message.');
        assertSame(-32700, $frames[1]['error']['code'], 'A bare scalar is not a JSON-RPC message.');
    }

    /**
     * One bad frame costs one error, not the session.
     *
     * The oversized-frame guard answers and moves on to the next line. Were it
     * to stop reading instead, a single overlong line from a client would end
     * the server's whole conversation with it.
     */
    #[Group('mcp')]
    public function testAnOversizedFrameIsRejectedAndTheSessionCarriesOn(): void
    {
        $initialize = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => StdioServer::PROTOCOL_VERSION]], JSON_THROW_ON_ERROR);

        $frames = $this->frames([str_repeat('x', 4096) . "\n", $initialize . "\n"], maxLineBytes: 2048);

        assertSame(-32700, $frames[0]['error']['code']);
        assertSame(1, $frames[1]['id'], 'The request after the oversized frame must still be answered.');
        assertSame(true, isset($frames[1]['result']));
    }

    #[Group('mcp')]
    public function testAResponseOverTheByteLimitIsReplacedByAnError(): void
    {
        $initialize = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => StdioServer::PROTOCOL_VERSION]], JSON_THROW_ON_ERROR);

        $frames = $this->frames([$initialize . "\n"], maxResponseBytes: 100);

        assertSame(-32001, $frames[0]['error']['code']);
        assertSame(1, $frames[0]['id'], 'The error must still answer the request it replaces.');
    }

    #[Group('mcp')]
    public function testAMessageThatIsNotJsonRpcTwoIsAnInvalidRequest(): void
    {
        assertSame(-32600, $this->server()->handle(['jsonrpc' => '1.0', 'id' => 7, 'method' => 'ping'])['error']['code']);
    }

    #[Group('mcp')]
    public function testAMethodThatIsNotAStringIsAnInvalidRequest(): void
    {
        assertSame(-32600, $this->server()->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 42])['error']['code']);
    }

    /**
     * A frame with no method is a response, and is acknowledged silently only
     * when it has an id and exactly one of result or error.
     */
    #[Group('mcp')]
    public function testOnlyAWellFormedResponseFrameIsAcknowledgedSilently(): void
    {
        $server = $this->server();

        assertSame(null, $server->handle(['jsonrpc' => '2.0', 'id' => 9, 'result' => []]));
        assertSame(-32600, $server->handle(['jsonrpc' => '2.0', 'result' => []])['error']['code'], 'No id: malformed.');
        assertSame(-32600, $server->handle(['jsonrpc' => '2.0', 'id' => 9, 'result' => [], 'error' => []])['error']['code'], 'Both result and error: malformed.');
    }

    #[Group('mcp')]
    public function testParamsThatAreAListAreInvalidParams(): void
    {
        assertSame(-32602, $this->server()->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list', 'params' => [1, 2]])['error']['code']);
    }

    /** MCP specifies `{}` for a ping result: an empty object, which must not serialise as `[]`. */
    #[Group('mcp')]
    public function testPingAnswersWithAnEmptyObject(): void
    {
        $server = $this->initialized();

        $result = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'])['result'];

        assertSame(true, $result instanceof stdClass, 'An empty PHP array would serialise as [], not {}.');
        assertSame([], (array) $result);
    }

    /** The tool list is fixed for the life of a server, and the server says so. */
    #[Group('mcp')]
    public function testTheServerDeclaresItsToolListStatic(): void
    {
        $response = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => StdioServer::PROTOCOL_VERSION]]);

        assertSame(false, $response['result']['capabilities']['tools']['listChanged']);
    }

    /**
     * Feed lines through the server's stdio loop and decode each response frame.
     *
     * @param list<string> $lines
     * @return list<array<string, mixed>>
     */
    private function frames(array $lines, int $maxLineBytes = 1_048_576, int $maxResponseBytes = 16_000_000): array
    {
        $input = fopen('php://temp', 'w+');
        $output = fopen('php://temp', 'w+');
        $errors = fopen('php://temp', 'w+');
        if (!is_resource($input) || !is_resource($output) || !is_resource($errors)) {
            throw new RuntimeException('Unable to allocate stdio test streams.');
        }
        fwrite($input, implode('', $lines));
        rewind($input);
        (new StdioServer($this->tools(), maxLineBytes: $maxLineBytes, maxResponseBytes: $maxResponseBytes))->run($input, $output, $errors);
        rewind($output);
        $frames = [];
        foreach (explode("\n", trim((string) stream_get_contents($output))) as $line) {
            if ($line !== '') {
                $frames[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        foreach ([$input, $output, $errors] as $stream) {
            fclose($stream);
        }

        return $frames;
    }

    private function initialized(): StdioServer
    {
        $server = $this->server();
        $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => StdioServer::PROTOCOL_VERSION]]);
        $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        return $server;
    }

    private function server(): StdioServer
    {
        return new StdioServer($this->tools());
    }

    private function tools(): ToolService
    {
        [$pdo] = $this->storeFixture();

        return new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );
    }
}
