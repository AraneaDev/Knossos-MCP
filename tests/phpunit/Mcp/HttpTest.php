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
        assertSame(29, count($listPayload['result']['tools']));
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
}
