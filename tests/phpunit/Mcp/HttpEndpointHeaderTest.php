<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\HttpEndpoint;
use Knossos\Mcp\HttpSessionStore;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the endpoint puts in its headers, and where its byte ceiling sits.
 *
 * HttpEndpoint scored 84% under mutation testing. Its tests assert the status
 * code and little else, so the case folding on Host, Content-Type and Accept,
 * the WWW-Authenticate and Allow headers that tell a refused client what to do
 * next, the Content-Type on every JSON response, and the inclusive comparison
 * on the response ceiling could all change with the suite green.
 *
 * A hostname rather than an address is used throughout because folding case is
 * only observable on a host that has letters to fold.
 */
final class HttpEndpointHeaderTest extends KnossosTestCase
{
    private const HOSTS = ['localhost:8080'];
    private const ORIGINS = ['http://localhost:8080'];

    /** Host, Content-Type and Accept are matched without regard to case. */
    #[Group('http')]
    public function testTheRequestHeadersAreMatchedCaseInsensitively(): void
    {
        $pdo = self::migrated();
        $headers = self::headers() + ['Mcp-Protocol-Version' => '2025-11-25'];
        $headers['Host'] = 'LOCALHOST:8080';
        $headers['Content-Type'] = 'Application/JSON';
        $headers['Accept'] = 'Application/JSON, Text/Event-Stream';

        $result = $this->endpoint($pdo)->handle('POST', $headers, self::initialize());

        assertSame(200, $result['status'], 'A shouted Host, Content-Type and Accept are the same headers.');
        assertSame('application/json', $result['headers']['Content-Type']);
    }

    /**
     * A caller refused for want of authentication is told how to authenticate,
     * whether it was refused for being remote or for presenting a bad token.
     */
    #[Group('http')]
    public function testEveryAuthenticationRefusalCarriesTheChallenge(): void
    {
        $pdo = self::migrated();
        $headers = self::headers() + ['Mcp-Protocol-Version' => '2025-11-25'];

        $remote = $this->endpoint($pdo, null)->handle('POST', $headers, self::initialize(), '10.0.0.1');
        assertSame(401, $remote['status']);
        assertSame('Bearer', $remote['headers']['WWW-Authenticate']);

        $wrongToken = $headers + [];
        $wrongToken['Authorization'] = 'Bearer wrong';
        $refused = $this->endpoint($pdo)->handle('POST', $wrongToken, self::initialize(), '127.0.0.1');
        assertSame(401, $refused['status']);
        assertSame('Bearer', $refused['headers']['WWW-Authenticate']);
    }

    /** A loopback address is still loopback with whitespace around it. */
    #[Group('http')]
    public function testALoopbackPeerIsRecognisedWithSurroundingWhitespace(): void
    {
        $pdo = self::migrated();
        $headers = self::headers() + ['Mcp-Protocol-Version' => '2025-11-25'];

        $result = $this->endpoint($pdo, null)->handle('POST', $headers, self::initialize(), " 127.0.0.1\n");

        assertSame(200, $result['status']);
    }

    /** A refused method names the methods that would have been accepted. */
    #[Group('http')]
    public function testARefusedMethodNamesWhatIsAllowed(): void
    {
        $pdo = self::migrated();
        $endpoint = $this->endpoint($pdo);

        $modern = $endpoint->handle('GET', self::headers() + ['Mcp-Protocol-Version' => '2026-07-28'], '');
        assertSame(405, $modern['status']);
        assertSame('POST', $modern['headers']['Allow'], 'The session-less profile takes POST and nothing else.');

        $legacy = $endpoint->handle('PUT', self::headers() + ['Mcp-Protocol-Version' => '2025-11-25'], '');
        assertSame(405, $legacy['status']);
        assertSame('POST, DELETE', $legacy['headers']['Allow']);
    }

    /** A JSON array is valid JSON and an invalid JSON-RPC body. */
    #[Group('http')]
    public function testAJsonArrayBodyIsRefusedAsAParseError(): void
    {
        $pdo = self::migrated();
        $headers = self::headers() + ['Mcp-Protocol-Version' => '2025-11-25'];

        $result = $this->endpoint($pdo)->handle('POST', $headers, '[{"jsonrpc":"2.0","method":"tools/list","id":1}]');

        assertSame(400, $result['status']);
        $decoded = json_decode($result['body'], true, 8, JSON_THROW_ON_ERROR);
        assertSame(-32700, $decoded['error']['code']);
    }

    /**
     * The protocol header is required only after initialization, so the
     * initialize call itself is accepted without it.
     */
    #[Group('http')]
    public function testInitializeIsAcceptedWithoutTheProtocolHeader(): void
    {
        $pdo = self::migrated();

        $result = $this->endpoint($pdo)->handle('POST', self::headers(), self::initialize());

        assertSame(200, $result['status'], 'A client cannot know the agreed version before it initializes.');
        assertSame(64, strlen($result['headers']['Mcp-Session-Id']));
    }

    /** A response measuring exactly the ceiling is served; one byte more is refused. */
    #[Group('http')]
    public function testAResponseExactlyAtTheByteCeilingIsStillServed(): void
    {
        $pdo = self::migrated();
        $store = new HttpSessionStore($pdo, ttlSeconds: 60, maxSessions: 8);
        $headers = self::headers() + ['Mcp-Protocol-Version' => '2025-11-25'];
        $session = $this->endpoint($pdo, 'secret', 1_000_000, $store)
            ->handle('POST', $headers, self::initialize())['headers']['Mcp-Session-Id'];
        $sessionHeaders = $headers + ['Mcp-Session-Id' => $session];
        $list = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], JSON_THROW_ON_ERROR);
        $this->endpoint($pdo, 'secret', 1_000_000, $store)->handle(
            'POST',
            $sessionHeaders,
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR),
        );
        $full = $this->endpoint($pdo, 'secret', 1_000_000, $store)->handle('POST', $sessionHeaders, $list);
        $size = strlen($full['body']);

        $atCeiling = $this->endpoint($pdo, 'secret', $size, $store)->handle('POST', $sessionHeaders, $list);
        assertSame(200, $atCeiling['status'], 'Exactly the configured size is within the configured size.');
        assertSame($size, strlen($atCeiling['body']));

        $overCeiling = $this->endpoint($pdo, 'secret', $size - 1, $store)->handle('POST', $sessionHeaders, $list);
        assertSame(500, $overCeiling['status']);
        $decoded = json_decode($overCeiling['body'], true, 8, JSON_THROW_ON_ERROR);
        assertSame(-32001, $decoded['error']['code']);
        assertSame('2.0', $decoded['jsonrpc']);
        assertSame('application/json', $overCeiling['headers']['Content-Type']);
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return [
            'Host' => 'localhost:8080',
            'Origin' => 'http://localhost:8080',
            'Authorization' => 'Bearer secret',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ];
    }

    private static function initialize(): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]], JSON_THROW_ON_ERROR);
    }

    private static function migrated(): PDO
    {
        $pdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();

        return $pdo;
    }

    private function endpoint(PDO $pdo, ?string $token = 'secret', int $maxResponseBytes = 1_000_000, ?HttpSessionStore $store = null): HttpEndpoint
    {
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );

        return new HttpEndpoint(
            $tools,
            $store ?? new HttpSessionStore($pdo, ttlSeconds: 60, maxSessions: 8),
            self::HOSTS,
            self::ORIGINS,
            $token,
            maxResponseBytes: $maxResponseBytes,
        );
    }
}
