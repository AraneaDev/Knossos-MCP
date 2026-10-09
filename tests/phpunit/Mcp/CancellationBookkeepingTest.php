<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\StdioServer;
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

    /** A request whose id no cancel can name (neither a string nor an integer) runs to completion. */
    #[Group('mcp')]
    public function testARequestWithAFractionalIdIsNeverCancelled(): void
    {
        $server = $this->initializedServer();

        $response = $server->handle(['jsonrpc' => '2.0', 'id' => 1.5, 'method' => 'tools/call', 'params' => [
            'name' => 'scan_project',
            'arguments' => ['path' => self::repositoryRoot() . '/tests/Fixtures/mixed'],
        ]]);

        assertSame(1.5, $response['id']);
        assertSame(false, $response['result']['isError']);
    }

    private function initializedServer(): StdioServer
    {
        [$tools] = $this->toolServiceWithScannedFixture();
        $server = new StdioServer($tools);
        $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => StdioServer::PROTOCOL_VERSION]]);
        $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        return $server;
    }

    private function cancel(StdioServer $server, int|string $requestId): void
    {
        $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => $requestId]]);
    }

    /**
     * A scan request, which polls for its own cancellation while it runs.
     *
     * @return array<string, mixed>|null
     */
    private function scan(StdioServer $server, int|string $id): ?array
    {
        return $server->handle(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => [
            'name' => 'scan_project',
            'arguments' => ['path' => self::repositoryRoot() . '/tests/Fixtures/mixed'],
        ]]);
    }
}
