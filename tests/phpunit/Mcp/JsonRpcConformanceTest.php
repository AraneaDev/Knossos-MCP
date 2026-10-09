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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
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
    use StdioFrames;

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

    /**
     * The size cap judged the whole read buffer, so a valid frame followed in
     * the same read by the next frame's bytes was rejected as oversized and its
     * request was never answered.
     */
    #[Group('mcp')]
    public function testAPipelinedFrameInTheSameReadDoesNotMakeTheFirstOversized(): void
    {
        [$initialize, $ping] = $this->pipelinedPair();
        $limit = strlen($initialize) + 1 + 10; // first frame fits; both together do not
        assertSame(true, strlen($initialize) + strlen($ping) + 2 > $limit);

        $frames = $this->frames([$initialize . "\n" . $ping . "\n"], maxLineBytes: $limit);

        assertSame(2, count($frames));
        assertSame(1, $frames[0]['id']);
        assertSame(true, isset($frames[0]['result']), 'The first frame is within the limit and must be answered.');
        assertSame(2, $frames[1]['id']);
        assertSame(true, isset($frames[1]['result']));
    }

    /** A frame one byte over the limit is still refused when the next frame arrives with it. */
    #[Group('mcp')]
    public function testAnOversizedFrameFollowedInTheSameReadIsRefusedAndTheNextAnswered(): void
    {
        [$initialize, $ping] = $this->pipelinedPair();

        // The newline makes the first frame one byte over the limit.
        $frames = $this->frames([$initialize . "\n" . $ping . "\n"], maxLineBytes: strlen($initialize));

        assertSame(2, count($frames));
        assertSame(-32700, $frames[0]['error']['code']);
        assertSame(null, $frames[0]['id']);
        assertSame(2, $frames[1]['id']);
        assertSame(true, isset($frames[1]['result']));
    }

    /**
     * MCP requires a request id to be an integer or a string. Any other id was
     * echoed back, and one JSON cannot encode (1e999 decodes to INF) made the
     * answer itself fail. Such a request is invalid, answered with id null,
     * and the session carries on.
     *
     * @return iterable<string, array{string}>
     */
    public static function idsThatAreNeitherIntegersNorStrings(): iterable
    {
        yield 'fractional' => ['1.5'];
        yield 'array' => ['[1]'];
        yield 'past the float range' => ['1e999'];
        yield 'null' => ['null'];
    }

    #[Group('mcp')]
    #[DataProvider('idsThatAreNeitherIntegersNorStrings')]
    public function testARequestWhoseIdIsNeitherAnIntegerNorAStringIsInvalid(string $id): void
    {
        $frames = $this->frames(['{"jsonrpc":"2.0","id":' . $id . ',"method":"ping"}' . "\n", '{"jsonrpc":"2.0","id":2,"method":"ping"}' . "\n"]);

        assertSame(2, count($frames));
        assertSame(-32600, $frames[0]['error']['code']);
        assertSame(null, $frames[0]['id']);
        assertSame(2, $frames[1]['id'], 'A normal request after the invalid one is still answered.');
        assertSame(true, isset($frames[1]['result']));
    }

    /** A notification carries no id at all, and the id rule does not touch it. */
    #[Group('mcp')]
    public function testANotificationIsStillAnsweredWithNothing(): void
    {
        $frames = $this->frames(['{"jsonrpc":"2.0","method":"notifications/initialized"}' . "\n", '{"jsonrpc":"2.0","id":"a","method":"ping"}' . "\n"]);

        assertSame(1, count($frames));
        assertSame('a', $frames[0]['id']);
    }

    /** A frame longer than one 8 KB read, but within the cap, is assembled from several reads and answered. */
    #[Group('mcp')]
    public function testAFrameSpanningSeveralReadsIsAnswered(): void
    {
        $initialize = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => StdioServer::PROTOCOL_VERSION,
            'clientInfo' => ['name' => str_repeat('c', 20_000), 'version' => '1'],
        ]], JSON_THROW_ON_ERROR);

        $frames = $this->frames([$initialize . "\n"]);

        assertSame(1, count($frames));
        assertSame(1, $frames[0]['id']);
        assertSame(true, isset($frames[0]['result']));
    }

    /** The cap counts the newline: a line exactly maxLineBytes long is accepted, one byte more is refused. */
    #[Group('mcp')]
    public function testALineExactlyAtTheCapIsAcceptedAndOneByteMoreIsRefused(): void
    {
        [$initialize] = $this->pipelinedPair();
        $line = $initialize . "\n";

        $atCap = $this->frames([$line], maxLineBytes: strlen($line));
        $overCap = $this->frames([$line], maxLineBytes: strlen($line) - 1);

        assertSame(1, $atCap[0]['id']);
        assertSame(true, isset($atCap[0]['result']));
        assertSame(-32700, $overCap[0]['error']['code']);
        assertSame(null, $overCap[0]['id']);
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
        return $this->runFrames(new StdioServer($this->tools(), maxLineBytes: $maxLineBytes, maxResponseBytes: $maxResponseBytes), $lines);
    }

    /**
     * An initialize frame of about 3 KB and a ping, small enough to arrive in one 8 KB read.
     *
     * @return array{string, string}
     */
    private function pipelinedPair(): array
    {
        $initialize = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => StdioServer::PROTOCOL_VERSION,
            'clientInfo' => ['name' => str_repeat('c', 3000), 'version' => '1'],
        ]], JSON_THROW_ON_ERROR);
        $ping = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'], JSON_THROW_ON_ERROR);

        return [$initialize, $ping];
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
