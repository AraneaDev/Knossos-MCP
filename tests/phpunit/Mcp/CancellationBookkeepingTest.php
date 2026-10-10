<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\McpDispatcher;
use Knossos\Mcp\ResourceService;
use Knossos\Mcp\StdioServer;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Which requests a cancellation notice applies to.
 *
 * A notice names one request by its id, and a JSON-RPC id is a string or a
 * number: "3" and 3 are different requests. The pending notices are bounded,
 * and an entry must leave once its request has been answered, so a notice can
 * only ever cancel the request it named.
 */
final class CancellationBookkeepingTest extends KnossosTestCase
{
    /**
     * After 1,024 pending cancels, array_shift renumbered the integer keys and
     * cancelled unrelated low-numbered requests.
     */
    #[Group('mcp')]
    public function testEvictionDoesNotCancelAnUnrelatedRequest(): void
    {
        $server = $this->initializedServer();
        foreach (range(5000, 6024) as $requestId) { // 1,025 cancels: one eviction
            $this->cancel($server, $requestId);
        }

        $response = $this->scan($server, 3);

        assertSame(true, $response !== null, 'Request 3 was never cancelled and must be answered.');
        assertSame(false, $response['result']['isError']);
    }

    /** The newest cancel survives an eviction, so it still withdraws its own request. */
    #[Group('mcp')]
    public function testEvictionKeepsTheNewestCancel(): void
    {
        $server = $this->initializedServer();
        foreach (range(5000, 6024) as $requestId) {
            $this->cancel($server, $requestId);
        }

        assertSame(null, $this->scan($server, 6024), 'Request 6024 was cancelled last and must be withdrawn.');
    }

    /** A cancel naming the string "3" was stored under the same key as the integer 3. */
    #[Group('mcp')]
    public function testAStringIdAndAnIntegerIdAreDifferentRequests(): void
    {
        $server = $this->initializedServer();
        $this->cancel($server, '3');

        $response = $this->scan($server, 3);

        assertSame(true, $response !== null, 'Request 3 (an integer) was not the one cancelled.');
        assertSame(false, $response['result']['isError']);
        assertSame(null, $this->scan($server, '3'), 'Request "3" (a string) was cancelled and must be withdrawn.');
    }

    /**
     * An entry was cleared only after a tools/call, so a cancel for a request
     * answered by any other method lingered and withdrew a later request that
     * reused the id.
     */
    #[Group('mcp')]
    public function testAnEntryIsClearedWhenItsRequestIsAnsweredByAnyMethod(): void
    {
        $server = $this->initializedServer();
        $this->cancel($server, 4);
        $ping = $server->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping']);
        assertSame(4, $ping['id']);

        $response = $this->scan($server, 4);

        assertSame(true, $response !== null, 'The cancel for request 4 left with the ping that answered it.');
        assertSame(false, $response['result']['isError']);
    }

    /** The entry left with its request even when answering it threw. */
    #[Group('mcp')]
    public function testAnEntryIsClearedWhenAnsweringItsRequestThrows(): void
    {
        [$tools] = $this->toolServiceWithScannedFixture();
        // Resources over an unmigrated database: reading one throws.
        $server = new McpDispatcher($tools, resources: new ResourceService(new ArchitectureQueryService(SqliteConnection::open(':memory:'))));
        $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => McpDispatcher::PROTOCOL_VERSION]]);
        $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->cancel($server, 4);
        $threw = false;
        try {
            $server->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/read', 'params' => ['uri' => 'knossos://project_' . str_repeat('0', 64) . '/summary']]);
        } catch (\Throwable) {
            $threw = true;
        }
        assertSame(true, $threw, 'The read must fail for this test to mean anything.');

        $response = $this->scan($server, 4);

        assertSame(true, $response !== null, 'The cancel for request 4 left with it, though answering it threw.');
    }

    /**
     * A cancel the transport finds while the tool runs withdraws that request,
     * and leaves with it, so a later request reusing the id is answered.
     */
    #[Group('mcp')]
    public function testACancelTheTransportPollsForWithdrawsOnlyTheRunningRequest(): void
    {
        $server = $this->initializedServer();
        $asked = [];
        $poll = static function (int|string $id) use (&$asked): bool {
            $asked[] = $id;

            return true;
        };

        assertSame(null, $server->handle($this->scanRequest(9), $poll), 'The polled cancel must withdraw request 9.');
        assertSame(true, $asked !== [] && array_unique($asked) === [9], 'The poll is asked about the running request only.');
        $response = $server->handle($this->scanRequest(9), static fn(int|string $id): bool => false);
        assertSame(true, $response !== null, 'The polled cancel left with request 9.');
        assertSame(false, $response['result']['isError']);
    }

    /**
     * Over stdio, a cancel that arrives while the tool runs withdraws it: the
     * transport reads ahead for the running request and tells the dispatcher.
     */
    #[Group('mcp')]
    public function testStdioWithdrawsARequestCancelledWhileItRuns(): void
    {
        [$tools] = $this->toolServiceWithScannedFixture();
        $input = fopen('php://temp', 'w+');
        $output = fopen('php://temp', 'w+');
        $errors = fopen('php://temp', 'w+');
        if (!is_resource($input) || !is_resource($output) || !is_resource($errors)) {
            throw new \RuntimeException('Unable to allocate stdio streams.');
        }
        foreach ([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => McpDispatcher::PROTOCOL_VERSION]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $this->scanRequest('s1'),
            ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 's1']],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
        ] as $frame) {
            fwrite($input, json_encode($frame, JSON_THROW_ON_ERROR) . "\n");
        }
        rewind($input);

        (new StdioServer(new McpDispatcher($tools), readinessWaiter: static fn(): int => 1))->run($input, $output, $errors);

        rewind($output);
        $frames = array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) stream_get_contents($output)))),
        );
        assertSame([1, 2], array_column($frames, 'id'), 'Only initialize and ping are answered; s1 was withdrawn.');
        assertSame(true, isset($frames[0]['result']['protocolVersion']));
        assertSame([], (array) $frames[1]['result']);
    }

    private function initializedServer(): McpDispatcher
    {
        [$tools] = $this->toolServiceWithScannedFixture();
        $server = new McpDispatcher($tools);
        $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => McpDispatcher::PROTOCOL_VERSION]]);
        $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        return $server;
    }

    private function cancel(McpDispatcher $server, int|string $requestId): void
    {
        $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => $requestId]]);
    }

    /**
     * A scan request, which polls for its own cancellation while it runs.
     *
     * @return array<string, mixed>|null
     */
    private function scan(McpDispatcher $server, int|string $id): ?array
    {
        return $server->handle($this->scanRequest($id));
    }

    /** @return array<string, mixed> */
    private function scanRequest(int|string $id): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => [
            'name' => 'scan_project',
            'arguments' => ['path' => self::repositoryRoot() . '/tests/Fixtures/mixed'],
        ]];
    }
}
