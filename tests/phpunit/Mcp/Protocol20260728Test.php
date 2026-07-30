<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Application;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\HttpEndpoint;
use Knossos\Mcp\HttpSessionStore;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\PromptService;
use Knossos\Mcp\Protocol\Profile20251125;
use Knossos\Mcp\Protocol\Profile20260728;
use Knossos\Mcp\Protocol\ProtocolNegotiator;
use Knossos\Mcp\ResourceService;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\StdioServer;
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
 * Conformance for MCP `2026-07-28`: the stateless revision.
 *
 * The handshake-era behaviour these tests sit beside is covered by McpTest and
 * HttpTest, which are deliberately left untouched — a revision profile that
 * changed legacy output would be a regression, so those files passing unedited
 * is itself part of this suite's assertion.
 */
final class Protocol20260728Test extends KnossosTestCase
{
    private const VERSION = '2026-07-28';

    #[Group('mcp')]
    public function testStatelessRequestsNeedNoHandshakeAndCarryRevisionEnvelopeFields(): void
    {
        $server = new StdioServer($this->tools(), resources: null, prompts: null);

        // The gate that answers -32003 until `notifications/initialized` arrives
        // must not fire: this revision has no handshake to wait for.
        $tools = $server->handle($this->request(1, 'tools/list'));
        assertSame(false, isset($tools['error']));
        assertSame('complete', $tools['result']['resultType']);
        assertSame(
            ['name' => 'knossos', 'version' => Application::VERSION],
            $tools['result']['_meta']['io.modelcontextprotocol/serverInfo'],
        );
    }

    #[Group('mcp')]
    public function testServerDiscoverAdvertisesSupportedRevisionsCapabilitiesAndIdentity(): void
    {
        $server = new StdioServer($this->tools(), resources: null, prompts: null);
        $result = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover'])['result'];

        // Newest first: a client picking the head of this list gets the best
        // revision both sides speak.
        assertSame([self::VERSION, '2025-11-25'], $result['protocolVersions']);
        assertSame('knossos', $result['serverInfo']['name']);
        assertSame(Application::VERSION, $result['serverInfo']['version']);
        // `extensions` must be present even while empty: absent is
        // indistinguishable from a server too old to report extensions at all.
        assertSame(true, array_key_exists('extensions', $result['capabilities']));
        assertSame([], (array) $result['capabilities']['extensions']);
    }

    #[Group('mcp')]
    public function testListResultsCarryCacheHintsAndProjectDataStaysPrivate(): void
    {
        $server = new StdioServer(
            $this->tools(),
            resources: new ResourceService(new ArchitectureQueryService($this->database())),
            prompts: new PromptService(),
        );

        $tools = $server->handle($this->request(1, 'tools/list'))['result'];
        assertSame(3_600_000, $tools['ttlMs']);
        assertSame('public', $tools['cacheScope']);

        $prompts = $server->handle($this->request(2, 'prompts/list'))['result'];
        assertSame(3_600_000, $prompts['ttlMs']);
        assertSame('public', $prompts['cacheScope']);

        // resources/list names the caller's scanned projects. `public` would let
        // a shared intermediary cache that and serve it to a different caller.
        $resources = $server->handle($this->request(3, 'resources/list'))['result'];
        assertSame(60_000, $resources['ttlMs']);
        assertSame('private', $resources['cacheScope']);
    }

    #[Group('mcp')]
    public function testUnknownRevisionIsRejectedWithTheSupportedSet(): void
    {
        $server = new StdioServer($this->tools(), resources: null, prompts: null);
        $error = $server->handle($this->request(1, 'tools/list', version: '1999-01-01'))['error'];

        assertSame(-32022, $error['code']);
        // The supported set must travel with the error, or a client can only
        // downgrade blindly.
        assertSame([self::VERSION, '2025-11-25'], $error['data']['supported']);
        assertSame('1999-01-01', $error['data']['requested']);
    }

    #[Group('mcp')]
    public function testResourceNotFoundUsesTheRevisionSpecificErrorCode(): void
    {
        $resources = new ResourceService(new ArchitectureQueryService($this->database()));
        $server = new StdioServer($this->tools(), resources: $resources, prompts: null);
        $missing = ['uri' => 'knossos://project_' . str_repeat('a', 64) . '/summary'];

        $modern = $server->handle($this->request(1, 'resources/read', $missing));
        assertSame(-32602, $modern['error']['code']);

        // The same lookup under the handshake revision keeps the old code, which
        // is what makes the legacy suite valid without edit.
        $legacy = new StdioServer($this->tools(), resources: $resources, prompts: null);
        $legacy->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']]);
        $legacy->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        assertSame(-32002, $legacy->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => $missing])['error']['code']);
    }

    #[Group('mcp')]
    public function testHandshakeResponsesStayFreeOfRevisionSpecificFields(): void
    {
        $server = new StdioServer($this->tools(), resources: null, prompts: null);
        $result = $server->handle([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25'],
        ])['result'];

        assertSame('2025-11-25', $result['protocolVersion']);
        // `extensions`, `resultType`, and `_meta` postdate this revision. Emitting
        // them here would be the quiet kind of protocol drift.
        assertSame(false, array_key_exists('extensions', $result['capabilities']));
        assertSame(false, array_key_exists('resultType', $result));
        assertSame(false, array_key_exists('_meta', $result));
    }

    #[Group('mcp')]
    public function testAHandshakeClientStaysOnItsRevisionForLaterMetaLessRequests(): void
    {
        $server = new StdioServer($this->tools(), resources: null, prompts: null);
        $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']]);

        // Before `notifications/initialized`, a pinned handshake client must still
        // meet the gate. Defaulting it to the stateless revision would silently
        // drop that requirement.
        assertSame(-32003, $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])['error']['code']);

        $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $listed = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'])['result'];
        assertSame(false, array_key_exists('resultType', $listed));
    }

    #[Group('mcp')]
    public function testTheHandshakeRevisionCanAlsoBeSelectedThroughMeta(): void
    {
        $server = new StdioServer($this->tools(), resources: null, prompts: null);

        // A client may name the older revision in `_meta` without ever sending
        // `initialize`. Nothing exercised that arm before, so removing it entirely
        // went unnoticed.
        $error = $server->handle($this->request(1, 'tools/list', version: '2025-11-25'))['error'];

        // -32003, not -32022: the arm resolved to the handshake profile, which then
        // enforced *its own* requirement. Removing the arm would reject the revision
        // outright with -32022, so the distinction is what pins the arm down.
        assertSame(-32003, $error['code']);
    }

    #[Group('mcp')]
    public function testDecorationPreservesMetaTheResultAlreadyCarried(): void
    {
        $profile = new Profile20260728();

        // Overwriting rather than merging would silently drop a progress token or
        // trace context a caller put in `_meta`.
        $decorated = $profile->decorate(['_meta' => ['caller/token' => 'abc'], 'ok' => true], 'tools/list');

        assertSame('abc', $decorated['_meta']['caller/token']);
        assertSame(
            ['name' => 'knossos', 'version' => Application::VERSION],
            $decorated['_meta']['io.modelcontextprotocol/serverInfo'],
        );
    }

    #[Group('mcp')]
    public function testARequestWithNonArrayParamsDeclaresNoRevision(): void
    {
        // requestedVersion() has to tolerate a malformed frame rather than reading
        // `_meta` off a non-array; the negotiator then falls back to the default.
        assertSame(null, ProtocolNegotiator::requestedVersion(['jsonrpc' => '2.0', 'params' => 'nonsense']));
        assertSame(null, ProtocolNegotiator::requestedVersion(['jsonrpc' => '2.0']));
        assertSame(null, ProtocolNegotiator::requestedVersion(['jsonrpc' => '2.0', 'params' => ['_meta' => 'nonsense']]));
    }

    #[Group('mcp')]
    public function testEachProfileNamesItsOwnRevision(): void
    {
        assertSame('2025-11-25', (new Profile20251125())->version());
        assertSame(self::VERSION, (new Profile20260728())->version());
        // Every advertised revision must have a profile behind it, or
        // server/discover promises something the negotiator cannot honour.
        assertSame(
            ProtocolNegotiator::SUPPORTED,
            [(new Profile20260728())->version(), (new Profile20251125())->version()],
        );
    }

    #[Group('mcp')]
    public function testKeepaliveIsEmittedOnlyForRevisionsThatDefinePing(): void
    {
        assertSame(true, (new Profile20251125())->emitsKeepalive());
        // `2026-07-28` removed `ping`. It can afford to: the revision is
        // stateless, so a dropped idle connection costs only a reconnect —
        // measured at well under a tenth of a second even against a large graph.
        assertSame(false, (new Profile20260728())->emitsKeepalive());
    }

    #[Group('mcp')]
    public function testToolOrderIsStableSoClientsCanCacheTheList(): void
    {
        $names = array_column($this->tools()->definitions(), 'name');
        assertSame($names, array_column($this->tools()->definitions(), 'name'));
        // Ordering is load-bearing under this revision: clients cache the list
        // and reorderings cost prompt-cache hits, so it is asserted rather than
        // left to the accident of a static array.
        assertSame('list_projects', $names[0]);
        assertSame(count($names), count(array_unique($names)));
    }

    #[Group('http')]
    public function testHttpRejectsMirroredHeadersThatDisagreeWithTheBody(): void
    {
        [$endpoint, $headers] = $this->endpoint();

        $call = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => 'list_projects', 'arguments' => [],
            '_meta' => [ProtocolNegotiator::VERSION_META_KEY => self::VERSION],
        ]];
        $body = json_encode($call, JSON_THROW_ON_ERROR);

        $ok = $endpoint->handle('POST', $headers + ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'list_projects'], $body);
        assertSame(200, $ok['status']);

        // A gateway that routes on the header while the server acts on the body
        // is the confused deputy this rule closes.
        $wrongName = $endpoint->handle('POST', $headers + ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'scan_project'], $body);
        assertSame(400, $wrongName['status']);
        assertSame(-32020, json_decode($wrongName['body'], true, 512, JSON_THROW_ON_ERROR)['error']['code']);

        $wrongMethod = $endpoint->handle('POST', $headers + ['Mcp-Method' => 'tools/list', 'Mcp-Name' => 'list_projects'], $body);
        assertSame(-32020, json_decode($wrongMethod['body'], true, 512, JSON_THROW_ON_ERROR)['error']['code']);

        $missing = $endpoint->handle('POST', $headers + ['Mcp-Name' => 'list_projects'], $body);
        assertSame(-32020, json_decode($missing['body'], true, 512, JSON_THROW_ON_ERROR)['error']['code']);

        $missingName = $endpoint->handle('POST', $headers + ['Mcp-Method' => 'tools/call'], $body);
        assertSame(-32020, json_decode($missingName['body'], true, 512, JSON_THROW_ON_ERROR)['error']['code']);
    }

    #[Group('http')]
    public function testHttpRejectsAProtocolHeaderThatContradictsTheBody(): void
    {
        [$endpoint, $headers] = $this->endpoint();
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [
            '_meta' => [ProtocolNegotiator::VERSION_META_KEY => '2025-11-25'],
        ]], JSON_THROW_ON_ERROR);

        $response = $endpoint->handle('POST', $headers + ['Mcp-Method' => 'tools/list'], $body);
        assertSame(400, $response['status']);
        assertSame(-32020, json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['error']['code']);
    }

    #[Group('http')]
    public function testHttpDecodesTheBase64SentinelBeforeComparingNames(): void
    {
        [$endpoint, $headers] = $this->endpoint();
        $uri = 'knossos://project_' . str_repeat('a', 64) . '/summary';
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => [
            'uri' => $uri, '_meta' => [ProtocolNegotiator::VERSION_META_KEY => self::VERSION],
        ]], JSON_THROW_ON_ERROR);
        $sentinel = '=?base64?' . base64_encode($uri) . '?=';

        // An encoded header must compare equal to the plain body value; comparing
        // raw would reject every value that needed encoding in the first place.
        $encoded = $endpoint->handle('POST', $headers + ['Mcp-Method' => 'resources/read', 'Mcp-Name' => $sentinel], $body);
        assertSame(-32602, json_decode($encoded['body'], true, 512, JSON_THROW_ON_ERROR)['error']['code']);

        // A malformed sentinel payload is rejected, not silently compared raw.
        $malformed = $endpoint->handle('POST', $headers + ['Mcp-Method' => 'resources/read', 'Mcp-Name' => '=?base64?!!!not-base64!!!?='], $body);
        assertSame(-32020, json_decode($malformed['body'], true, 512, JSON_THROW_ON_ERROR)['error']['code']);
    }

    #[Group('http')]
    public function testHttpDropsSessionsAndTheGetStreamForThisRevision(): void
    {
        [$endpoint, $headers] = $this->endpoint();

        // Sessions and the GET stream are gone from this revision; both verbs
        // that served them answer 405 rather than reaching legacy machinery.
        assertSame(405, $endpoint->handle('GET', $headers, '')['status']);
        assertSame(405, $endpoint->handle('DELETE', $headers, '')['status']);

        // No session is minted, and none is demanded: a first request succeeds
        // with no handshake and no Mcp-Session-Id in either direction.
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [
            '_meta' => [ProtocolNegotiator::VERSION_META_KEY => self::VERSION],
        ]], JSON_THROW_ON_ERROR);
        $response = $endpoint->handle('POST', $headers + ['Mcp-Method' => 'tools/list'], $body);
        assertSame(200, $response['status']);
        assertSame(false, array_key_exists('Mcp-Session-Id', $response['headers']));
    }

    #[Group('http')]
    public function testHttpAnswersUnknownMethodsWith404SoClientsCanTellTheEraApart(): void
    {
        [$endpoint, $headers] = $this->endpoint();
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'nope/nope', 'params' => [
            '_meta' => [ProtocolNegotiator::VERSION_META_KEY => self::VERSION],
        ]], JSON_THROW_ON_ERROR);

        $response = $endpoint->handle('POST', $headers + ['Mcp-Method' => 'nope/nope'], $body);
        // 404 with a JSON-RPC body: the status alone would be ambiguous with a
        // legacy endpoint that does not host this path at all.
        assertSame(404, $response['status']);
        assertSame(-32601, json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['error']['code']);
    }

    #[Group('http')]
    public function testHttpAcceptsNotificationsWithoutDemandingMirroredHeaders(): void
    {
        [$endpoint, $headers] = $this->endpoint();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => [
            'requestId' => 1, '_meta' => [ProtocolNegotiator::VERSION_META_KEY => self::VERSION],
        ]], JSON_THROW_ON_ERROR);

        // The revision leaves header rules for notification POSTs undefined, so a
        // notification is acknowledged rather than held to a rule that does not
        // exist — note the absent Mcp-Method header.
        $response = $endpoint->handle('POST', $headers, $body);
        assertSame(202, $response['status']);
        assertSame('', $response['body']);
    }

    #[Group('http')]
    public function testHttpRejectsARequestWhoseMethodIsNotAString(): void
    {
        [$endpoint, $headers] = $this->endpoint();
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 42, 'params' => [
            '_meta' => [ProtocolNegotiator::VERSION_META_KEY => self::VERSION],
        ]], JSON_THROW_ON_ERROR);

        $response = $endpoint->handle('POST', $headers + ['Mcp-Method' => '42'], $body);
        assertSame(400, $response['status']);
        assertSame(-32600, json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['error']['code']);
    }

    #[Group('http')]
    public function testHttpRefusesTheWithdrawnRevisionWhenLegacyIsDisabled(): void
    {
        [$endpoint, $headers] = $this->endpoint();
        $headers['MCP-Protocol-Version'] = '2025-11-25';
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25',
        ]], JSON_THROW_ON_ERROR);

        // Capture and restore rather than blindly unset: a suite run with this
        // variable already set would otherwise have it cleared for every later
        // test, which changes the supported-version set they observe.
        $previous = getenv('KNOSSOS_LEGACY_PROTOCOL');
        putenv('KNOSSOS_LEGACY_PROTOCOL=0');
        try {
            $response = $endpoint->handle('POST', $headers, $body);
        } finally {
            $previous === false ? putenv('KNOSSOS_LEGACY_PROTOCOL') : putenv('KNOSSOS_LEGACY_PROTOCOL=' . $previous);
        }

        // Gating on the static constant instead of the accessor would let this
        // through to the session path and refuse it deeper in, surfacing as 200
        // with an embedded error -- and would advertise a revision this server
        // has stopped serving.
        assertSame(400, $response['status']);
        $error = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['error'];
        assertSame(-32022, $error['code']);
        assertSame([self::VERSION], $error['data']['supported']);
        assertSame(false, array_key_exists('Mcp-Session-Id', $response['headers']));
    }

    #[Group('http')]
    public function testHttpAdvertisesSupportedRevisionsWhenAskedForAnUnknownOne(): void
    {
        [$endpoint, $headers] = $this->endpoint();
        $headers['MCP-Protocol-Version'] = '1999-01-01';
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR);

        $response = $endpoint->handle('POST', $headers, $body);
        assertSame(400, $response['status']);
        $error = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['error'];
        assertSame(-32022, $error['code']);
        assertSame([self::VERSION, '2025-11-25'], $error['data']['supported']);
    }

    /**
     * A stateless request: the revision travels in `_meta` rather than in a
     * handshake.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function request(int $id, string $method, array $params = [], string $version = self::VERSION): array
    {
        $params['_meta'] = [ProtocolNegotiator::VERSION_META_KEY => $version];

        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
    }

    private function database(): PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $pdo = SqliteConnection::open(':memory:');
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        }

        return $pdo;
    }

    private function tools(): ToolService
    {
        $pdo = $this->database();

        return new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );
    }

    /** @return array{0: HttpEndpoint, 1: array<string, string>} */
    private function endpoint(): array
    {
        $endpoint = new HttpEndpoint(
            $this->tools(),
            new HttpSessionStore($this->database(), ttlSeconds: 60, maxSessions: 4),
            ['127.0.0.1:8080'],
            ['http://127.0.0.1:8080'],
            'secret',
            maxRequestBytes: 4096,
            maxResponseBytes: 1_000_000,
            resources: new ResourceService(new ArchitectureQueryService($this->database())),
            prompts: new PromptService(),
        );
        $headers = [
            'Host' => '127.0.0.1:8080', 'Origin' => 'http://127.0.0.1:8080',
            'Authorization' => 'Bearer secret', 'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => self::VERSION,
        ];

        return [$endpoint, $headers];
    }
}
