<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\HttpEndpoint;
use Knossos\Mcp\HttpSessionStore;
use Knossos\Mcp\Protocol\Profile20251125;
use Knossos\Mcp\Protocol\Profile20260728;
use Knossos\Mcp\Protocol\ProtocolNegotiator;
use Knossos\Mcp\Protocol\UnsupportedProtocolVersionException;
use Knossos\Mcp\ResourceService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * A 2025-11-25 session over HTTP runs under 2025-11-25, as it does over stdio.
 *
 * The endpoint builds a fresh server for every request after the handshake.
 * That server was never told which revision the session negotiated, so a
 * request without a `_meta` version fell through to the stateless 2026-07-28
 * profile: its envelope fields and its resource-not-found code.
 */
final class HttpLegacySessionTest extends KnossosTestCase
{
    private const HEADERS = [
        'Host' => '127.0.0.1:8080', 'Origin' => 'http://127.0.0.1:8080',
        'Authorization' => 'Bearer secret', 'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25',
    ];

    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            $this->removeTempTree($this->root);
        }
        parent::tearDown();
    }

    /** A fresh server per request never pinned the session's revision, so 2025-11-25 sessions got 2026-07-28 envelopes. */
    #[Group('mcp')]
    public function testALegacySessionGetsTheLegacyEnvelope(): void
    {
        [$endpoint, $headers] = $this->initializedLegacySession();

        $list = $endpoint->handle('POST', $headers, $this->body(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']));

        assertSame(200, $list['status']);
        $body = json_decode($list['body'], true, 512, JSON_THROW_ON_ERROR);
        assertSame(true, isset($body['result']['tools']));
        foreach (['resultType', 'ttlMs', 'cacheScope', '_meta'] as $key) {
            assertSame(false, array_key_exists($key, $body['result']), $key . ' belongs to 2026-07-28 only.');
        }
    }

    /** The 2025-11-25 code for a missing resource is -32002; 2026-07-28 answers -32602. */
    #[Group('mcp')]
    public function testALegacySessionGetsTheLegacyResourceNotFoundCode(): void
    {
        [$endpoint, $headers] = $this->initializedLegacySession();

        $read = $endpoint->handle('POST', $headers, $this->body([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/read',
            'params' => ['uri' => 'knossos://project_' . str_repeat('0', 64) . '/summary'],
        ]));

        $body = json_decode($read['body'], true, 512, JSON_THROW_ON_ERROR);
        assertSame(3, $body['id']);
        assertSame(-32002, $body['error']['code']);
    }

    /** A body version that contradicts the session's header silently won; it is refused as on the 2026-07-28 path. */
    #[Group('mcp')]
    public function testABodyVersionThatContradictsTheSessionHeaderIsRefused(): void
    {
        [$endpoint, $headers] = $this->initializedLegacySession();

        $response = $endpoint->handle('POST', $headers, $this->body([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list',
            'params' => ['_meta' => [ProtocolNegotiator::VERSION_META_KEY => Profile20260728::VERSION]],
        ]));

        assertSame(400, $response['status']);
        $body = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        assertSame('2.0', $body['jsonrpc']);
        assertSame(4, $body['id']);
        assertSame(-32020, $body['error']['code']);
        assertSame(true, str_starts_with($body['error']['message'], 'Header mismatch: MCP-Protocol-Version'));
    }

    /** A body that declares the session's own revision is not a mismatch. */
    #[Group('mcp')]
    public function testABodyVersionThatAgreesWithTheSessionHeaderIsServed(): void
    {
        [$endpoint, $headers] = $this->initializedLegacySession();

        $response = $endpoint->handle('POST', $headers, $this->body([
            'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list',
            'params' => ['_meta' => [ProtocolNegotiator::VERSION_META_KEY => Profile20251125::VERSION]],
        ]));

        assertSame(200, $response['status']);
    }

    /**
     * HTTP echoed any id back. MCP requires an integer or a string, the same
     * rule stdio applies: anything else is -32600 with id null, and the
     * session carries on.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function idsThatAreNeitherIntegersNorStrings(): iterable
    {
        yield 'array' => [[1]];
        yield 'fractional' => [1.5];
        yield 'null' => [null];
    }

    #[Group('mcp')]
    #[DataProvider('idsThatAreNeitherIntegersNorStrings')]
    public function testARequestWhoseIdIsNeitherAnIntegerNorAStringIsInvalid(mixed $id): void
    {
        [$endpoint, $headers] = $this->initializedLegacySession();

        $refused = $endpoint->handle('POST', $headers, $this->body(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/list']));
        $served = $endpoint->handle('POST', $headers, $this->body(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/list']));

        assertSame(400, $refused['status']);
        $body = json_decode($refused['body'], true, 512, JSON_THROW_ON_ERROR);
        assertSame('2.0', $body['jsonrpc']);
        assertSame(true, array_key_exists('id', $body) && $body['id'] === null);
        assertSame(-32600, $body['error']['code']);
        assertSame(200, $served['status'], 'A normal request in the same session is still served.');
    }

    #[Group('mcp')]
    public function testPinningSelectsThePinnedRevisionForAMessageWithoutMeta(): void
    {
        $negotiator = new ProtocolNegotiator();
        $negotiator->pin(Profile20251125::VERSION);
        assertSame(true, $negotiator->select(['method' => 'tools/list']) instanceof Profile20251125);

        $negotiator->pin(Profile20260728::VERSION);
        assertSame(true, $negotiator->select(['method' => 'tools/list']) instanceof Profile20260728);
    }

    #[Group('mcp')]
    public function testPinningARevisionNotOnOfferThrows(): void
    {
        $this->expectException(UnsupportedProtocolVersionException::class);

        (new ProtocolNegotiator())->pin('1999-01-01');
    }

    /**
     * An endpoint over a scanned fixture, with a 2025-11-25 session that has
     * completed initialize and notifications/initialized over HTTP.
     *
     * @return array{HttpEndpoint, array<string, string>}
     */
    private function initializedLegacySession(): array
    {
        [$tools, , $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        $this->root = $root;
        $endpoint = new HttpEndpoint(
            $tools,
            new HttpSessionStore($pdo, ttlSeconds: 60, maxSessions: 4),
            ['127.0.0.1:8080'],
            ['http://127.0.0.1:8080'],
            'secret',
            resources: new ResourceService(ArchitectureQueryService::forDatabase($pdo)),
        );
        $initialize = $endpoint->handle('POST', self::HEADERS, $this->body(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => Profile20251125::VERSION, 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]]));
        $headers = self::HEADERS + ['Mcp-Session-Id' => $initialize['headers']['Mcp-Session-Id']];
        $initialized = $endpoint->handle('POST', $headers, $this->body(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        assertSame(202, $initialized['status']);

        return [$endpoint, $headers];
    }

    /** @param array<string, mixed> $message */
    private function body(array $message): string
    {
        return json_encode($message, JSON_THROW_ON_ERROR);
    }
}
