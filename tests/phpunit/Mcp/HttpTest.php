<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\HttpEndpoint;
use Knossos\Mcp\HttpSessionStore;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class HttpTest extends KnossosTestCase
{
    #[Group('http')]
    public function testStreamableHttpEndpointEnforcesSessionsOriginAuthAndProtocolCaps(): void
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
        $store = new HttpSessionStore($pdo, ttlSeconds: 60, maxSessions: 4);
        $endpoint = new HttpEndpoint(
            $tools,
            $store,
            ['127.0.0.1:8080'],
            ['http://127.0.0.1:8080'],
            'secret',
            maxRequestBytes: 1024,
            maxResponseBytes: 1_000_000,
        );
        $headers = [
            'Host' => '127.0.0.1:8080', 'Origin' => 'http://127.0.0.1:8080',
            'Authorization' => 'Bearer secret', 'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25',
        ];
        $initialize = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]], JSON_THROW_ON_ERROR);
        assertSame(405, $endpoint->handle('GET', $headers, '')['status']);
        $badHost = $headers;
        $badHost['Host'] = 'evil.test';
        assertSame(421, $endpoint->handle('POST', $badHost, $initialize)['status']);
        $badOrigin = $headers;
        $badOrigin['Origin'] = 'https://evil.test';
        assertSame(403, $endpoint->handle('POST', $badOrigin, $initialize)['status']);
        $badAuth = $headers;
        $badAuth['Authorization'] = 'Bearer wrong';
        assertSame(401, $endpoint->handle('POST', $badAuth, $initialize)['status']);
        $badAccept = $headers;
        $badAccept['Accept'] = 'application/json';
        assertSame(406, $endpoint->handle('POST', $badAccept, $initialize)['status']);
        $badProtocol = $headers;
        $badProtocol['MCP-Protocol-Version'] = '2025-06-18';
        assertSame(400, $endpoint->handle('POST', $badProtocol, $initialize)['status']);
        assertSame(413, $endpoint->handle('POST', $headers, str_repeat('x', 1025))['status']);

        $initialized = $endpoint->handle('POST', $headers, $initialize);
        assertSame(200, $initialized['status']);
        $session = $initialized['headers']['Mcp-Session-Id'];
        assertSame(64, strlen($session));
        assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM http_sessions WHERE id = '" . $session . "'")->fetchColumn());
        $sessionHeaders = $headers + ['Mcp-Session-Id' => $session];
        $sessionHeaders['Mcp-Session-Id'] = $session;
        assertSame(400, $endpoint->handle('POST', $sessionHeaders, $initialize)['status']);
        $list = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], JSON_THROW_ON_ERROR);
        assertSame(409, $endpoint->handle('POST', $sessionHeaders, $list)['status']);
        $notification = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR);
        assertSame(202, $endpoint->handle('POST', $sessionHeaders, $notification)['status']);
        $duplicateInitialization = $endpoint->handle('POST', $sessionHeaders, $notification);
        assertSame(409, $duplicateInitialization['status']);
        assertContains('already been initialized', $duplicateInitialization['body']);
        $listed = $endpoint->handle('POST', $sessionHeaders, $list);
        assertSame(200, $listed['status']);
        $listPayload = json_decode($listed['body'], true, 512, JSON_THROW_ON_ERROR);
        assertSame(31, count($listPayload['result']['tools']));
        $cancel = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 99]], JSON_THROW_ON_ERROR);
        assertSame(202, $endpoint->handle('POST', $sessionHeaders, $cancel)['status']);

        $small = new HttpEndpoint($tools, $store, ['127.0.0.1:8080'], ['http://127.0.0.1:8080'], 'secret', maxResponseBytes: 100);
        $oversized = $small->handle('POST', $sessionHeaders, $list);
        assertSame(500, $oversized['status']);
        assertContains('Response exceeds', $oversized['body']);
        $capacity = new HttpEndpoint($tools, new HttpSessionStore($pdo, maxSessions: 1), ['127.0.0.1:8080'], ['http://127.0.0.1:8080'], 'secret');
        assertSame(503, $capacity->handle('POST', $headers, $initialize)['status']);
        assertSame(204, $endpoint->handle('DELETE', $sessionHeaders, '')['status']);
        assertSame(404, $endpoint->handle('POST', $sessionHeaders, $list)['status']);

        $brokenPdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($brokenPdo, self::repositoryRoot() . '/migrations'))->migrate();
        $brokenPdo->exec('DROP TABLE http_sessions');
        $brokenEndpoint = new HttpEndpoint($tools, new HttpSessionStore($brokenPdo), ['127.0.0.1:8080'], ['http://127.0.0.1:8080'], 'secret');
        foreach ([
            $brokenEndpoint->handle('POST', $headers, $initialize),
            $brokenEndpoint->handle('POST', $sessionHeaders, $list),
            $brokenEndpoint->handle('DELETE', $sessionHeaders, ''),
        ] as $storageFailure) {
            assertSame(503, $storageFailure['status']);
            assertContains('temporarily unavailable', $storageFailure['body']);
            assertSame(false, str_contains($storageFailure['body'], 'no such table'));
        }
    }

    #[Group('http')]
    public function testHttpBackstopPeerGuardSlidingExpiryAndNotificationInitialize(): void
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
        $store = new HttpSessionStore($pdo, ttlSeconds: 1000, maxSessions: 8);
        $headers = [
            'Host' => '127.0.0.1:8080', 'Origin' => 'http://127.0.0.1:8080',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25',
        ];
        $initialize = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]], JSON_THROW_ON_ERROR);

        // Without a bearer token, non-loopback callers are refused but loopback is served.
        $unauthenticated = new HttpEndpoint($tools, $store, ['127.0.0.1:8080'], ['http://127.0.0.1:8080'], null);
        assertSame(401, $unauthenticated->handle('POST', $headers, $initialize, '203.0.113.9')['status']);
        assertSame(200, $unauthenticated->handle('POST', $headers, $initialize, '127.0.0.1')['status']);
        assertSame(200, $unauthenticated->handle('POST', $headers, $initialize, null)['status']);

        // initialize delivered as a notification (no id) is acknowledged with 202 and no body.
        $initNotification = json_encode(['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]], JSON_THROW_ON_ERROR);
        $ack = $unauthenticated->handle('POST', $headers, $initNotification);
        assertSame(202, $ack['status']);
        assertSame('', $ack['body']);
        assertSame(false, array_key_exists('Mcp-Session-Id', $ack['headers']));

        // Sliding expiry: touch() pushes a live session's expiry forward, but never revives an expired one.
        $sessionId = $store->create();
        $store->markInitialized($sessionId);
        $hashed = hash('sha256', $sessionId);
        $now = time();
        $pdo->prepare('UPDATE http_sessions SET expires_at = :e WHERE id = :id')->execute(['e' => $now + 1, 'id' => $hashed]);
        $store->touch($sessionId);
        assertSame(true, (int) $pdo->query("SELECT expires_at FROM http_sessions WHERE id = '$hashed'")->fetchColumn() > $now + 500);
        $pdo->prepare('UPDATE http_sessions SET expires_at = 0 WHERE id = :id')->execute(['id' => $hashed]);
        $store->touch($sessionId);
        assertSame(0, (int) $pdo->query("SELECT expires_at FROM http_sessions WHERE id = '$hashed'")->fetchColumn());

        // Transport backstop: an unexpected failure while dispatching returns a generic
        // -32603 with no internal detail leaked.
        $brokenPdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($brokenPdo, self::repositoryRoot() . '/migrations'))->migrate();
        $brokenPdo->exec('DROP TABLE projects');
        $backstop = new HttpEndpoint(
            $tools,
            $store,
            ['127.0.0.1:8080'],
            ['http://127.0.0.1:8080'],
            'secret',
            resources: new \Knossos\Mcp\ResourceService(new ArchitectureQueryService($brokenPdo)),
        );
        $authHeaders = $headers + ['Authorization' => 'Bearer secret'];
        $init = $backstop->handle('POST', $authHeaders, $initialize);
        assertSame(200, $init['status']);
        $session = $init['headers']['Mcp-Session-Id'];
        $sessionHeaders = $authHeaders + ['Mcp-Session-Id' => $session];
        $backstop->handle('POST', $sessionHeaders, json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR));
        // The endpoint logs the suppressed detail for the operator, which is the
        // point: the response must stay generic while the cause stays
        // diagnosable. PHPUnit captures error_log() and reprints it, so without
        // redirecting it this deliberate failure prints a SQLSTATE mid-run that
        // reads like a broken test. Redirect for this call only, so a *real*
        // unexpected error elsewhere in the suite still surfaces.
        $failure = self::withErrorLogRedirected(
            static fn(): array => $backstop->handle('POST', $sessionHeaders, json_encode(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'resources/list', 'params' => []], JSON_THROW_ON_ERROR)),
        );
        assertSame(500, $failure['status']);
        $payload = json_decode($failure['body'], true, 512, JSON_THROW_ON_ERROR);
        assertSame(-32603, $payload['error']['code']);
        assertSame('Internal error', $payload['error']['message']);
        assertSame(false, str_contains($failure['body'], 'no such table'));
        // The detail the response withheld is still recorded for the operator.
        assertContains('no such table', self::$lastErrorLog);
    }

    /** The error_log() output captured by the most recent {@see withErrorLogRedirected()} call. */
    private static string $lastErrorLog = '';

    /**
     * Run $operation with error_log() pointed at a temporary file.
     *
     * Scoped to one call rather than the whole suite: PHPUnit surfacing
     * unexpected error_log() output is a feature worth keeping, so only the
     * deliberately-provoked failures opt out of it.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private static function withErrorLogRedirected(callable $operation): mixed
    {
        $capture = tempnam(sys_get_temp_dir(), 'knossos-errorlog-');
        if ($capture === false) {
            throw new RuntimeException('Unable to allocate an error-log capture file.');
        }
        $previous = ini_get('error_log');
        ini_set('error_log', $capture);
        try {
            return $operation();
        } finally {
            $previous === false ? ini_restore('error_log') : ini_set('error_log', $previous);
            self::$lastErrorLog = (string) @file_get_contents($capture);
            @unlink($capture);
        }
    }

    #[Group('http')]
    public function testHttpSessionCapacityAndInitializationTransitionsAreAtomicAcrossConnections(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-http-session-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate HTTP session database.');
        }
        try {
            $firstPdo = SqliteConnection::open($path);
            (new MigrationRunner($firstPdo, self::repositoryRoot() . '/migrations'))->migrate();
            $secondPdo = SqliteConnection::open($path);
            $first = new HttpSessionStore($firstPdo, ttlSeconds: 60, maxSessions: 1);
            $second = new HttpSessionStore($secondPdo, ttlSeconds: 60, maxSessions: 1);

            $session = $first->create();
            $capacityError = captureThrows(fn() => $second->create(), RuntimeException::class);
            assertSame(HttpSessionStore::CAPACITY_ERROR, $capacityError->getCode());
            assertSame(1, (int) $firstPdo->query('SELECT COUNT(*) FROM http_sessions')->fetchColumn());

            assertSame(HttpSessionStore::INITIALIZED, $first->markInitialized($session));
            assertSame(HttpSessionStore::ALREADY_INITIALIZED, $second->markInitialized($session));
            assertSame(1, (int) $firstPdo->query('SELECT initialized FROM http_sessions')->fetchColumn());
            $first->delete($session);
            assertSame(HttpSessionStore::UNKNOWN_OR_EXPIRED, $second->markInitialized($session));

            $expired = $first->create();
            $firstPdo->exec('UPDATE http_sessions SET expires_at = 0');
            assertSame(HttpSessionStore::UNKNOWN_OR_EXPIRED, $second->markInitialized($expired));
            $replacement = $second->create();
            assertSame(true, $first->exists($replacement));
            assertSame(1, (int) $firstPdo->query('SELECT COUNT(*) FROM http_sessions')->fetchColumn());

            $first->delete($replacement);
            $firstPdo->beginTransaction();
            $rolledBack = $first->create();
            assertSame(true, $firstPdo->inTransaction());
            assertSame(true, $first->exists($rolledBack));
            $firstPdo->rollBack();
            assertSame(false, $second->exists($rolledBack));
        } finally {
            unset($second, $first, $secondPdo, $firstPdo);
            foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }

    /**
     * Header handling that mutation testing found unasserted, all of it
     * security-relevant: the no-store directive, and the normalisation that makes
     * the Host allow-list a real check rather than a case-sensitive one.
     */
    #[Group('http')]
    public function testEveryResponseCarriesTheCachingAndSniffingDirectives(): void
    {
        [$endpoint, $headers] = $this->modernEndpoint();

        // no-store matters because a response can name the user's projects, and
        // nosniff because an error body is JSON that must not be sniffed as HTML.
        $refused = $endpoint->handle('POST', ['Host' => 'evil.test'] + $headers, '{}');
        assertSame('no-store', $refused['headers']['Cache-Control']);
        assertSame('nosniff', $refused['headers']['X-Content-Type-Options']);

        $served = $endpoint->handle('POST', $headers + ['Mcp-Method' => 'tools/list'], self::modernBody('tools/list'));
        assertSame('no-store', $served['headers']['Cache-Control']);
        assertSame('nosniff', $served['headers']['X-Content-Type-Options']);
    }

    #[Group('http')]
    public function testTheHostAllowListIsCaseInsensitiveAndTolerantOfPadding(): void
    {
        [$endpoint, $headers] = $this->modernEndpoint();
        $body = self::modernBody('tools/list');

        // A proxy may forward the Host with different casing or surrounding
        // whitespace. Without normalisation those are rejected as unknown hosts,
        // which reads to an operator as a misconfigured allow-list.
        foreach (['127.0.0.1:8080', '127.0.0.1:8080  ', ' 127.0.0.1:8080'] as $host) {
            $response = $endpoint->handle('POST', ['Host' => $host] + $headers + ['Mcp-Method' => 'tools/list'], $body);
            assertSame(200, $response['status']);
        }
        // Still a real allow-list: a different host is refused.
        assertSame(421, $endpoint->handle('POST', ['Host' => 'other.test'] + $headers, $body)['status']);
    }

    /** A modern-revision request body for one method. */
    private static function modernBody(string $method): string
    {
        return json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method,
            'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array{0: HttpEndpoint, 1: array<string, string>} */
    private function modernEndpoint(): array
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
        $endpoint = new HttpEndpoint($tools, new HttpSessionStore($pdo), ['127.0.0.1:8080'], ['http://127.0.0.1:8080'], 'secret');
        $headers = [
            'Host' => '127.0.0.1:8080', 'Origin' => 'http://127.0.0.1:8080',
            'Authorization' => 'Bearer secret', 'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2026-07-28',
        ];

        return [$endpoint, $headers];
    }


    /**
     * The request cap admits a body of exactly its size.
     *
     * Same shape as the stdio caps: the existing oversized-body test passes under
     * both `>` and `>=`, so the mutant that shifts the effective limit by one byte
     * survived. Pinning the accepted side is what makes the limit a limit.
     */
    #[Group('http')]
    public function testARequestBodyOfExactlyTheCapIsAcceptedAndOneMoreByteIsRejected(): void
    {
        [, $headers] = $this->modernEndpoint();
        // The 2026-07-28 profile mirrors the RPC method into a header.
        $headers['Mcp-Method'] = 'initialize';
        $body = self::initializeBodyOfExactly(512);
        assertSame(512, strlen($body));

        $capped = $this->cappedEndpoint(512);
        $atCap = $capped->handle('POST', $headers, $body, '127.0.0.1');
        assertSame(200, $atCap['status']);

        $overCap = $this->cappedEndpoint(511)->handle('POST', $headers, $body, '127.0.0.1');
        assertSame(413, $overCap['status']);
        assertContains('byte limit', $overCap['body']);
    }

    private function cappedEndpoint(int $maxRequestBytes): HttpEndpoint
    {
        $pdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );

        return new HttpEndpoint(
            $tools,
            new HttpSessionStore($pdo),
            ['127.0.0.1:8080'],
            ['http://127.0.0.1:8080'],
            'secret',
            maxRequestBytes: $maxRequestBytes,
        );
    }

    /** A valid initialize body padded to exactly $bytes, the padding carried in clientInfo.name. */
    private static function initializeBodyOfExactly(int $bytes): string
    {
        $build = static fn(string $name): string => json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2026-07-28', 'capabilities' => [], 'clientInfo' => ['name' => $name, 'version' => '1']],
        ], JSON_THROW_ON_ERROR);
        $padding = $bytes - strlen($build(''));
        if ($padding < 0) {
            self::fail('A valid initialize body does not fit in ' . $bytes . ' bytes.');
        }

        return $build(str_repeat('p', $padding));
    }

}
