<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use JsonException;
use Knossos\Mcp\Protocol\Profile20260728;
use Knossos\Mcp\Protocol\ProtocolNegotiator;
use Knossos\Mcp\Protocol\UnsupportedProtocolVersionException;
use RuntimeException;
use Throwable;

/**
 * Streamable HTTP transport for the MCP endpoint.
 *
 * Deliberately constrained: one POST path, no SSE streaming, no server-initiated
 * requests. Origin, Host, and bearer checks run before anything reads the body,
 * and internal failures are reduced to a generic error so a SQLSTATE never
 * reaches a client. Which protocol revision applies is decided per request, so
 * the handshake-era session machinery here serves only clients that still need
 * it. stdio remains the recommended transport; docs/operate/http-threat-model.md
 * records what this profile does and does not defend against.
 */
final readonly class HttpEndpoint
{
    /** Mirrored headers disagree with the request body, or a required one is absent. */
    private const HEADER_MISMATCH = -32020;
    private const BASE64_PREFIX = '=?base64?';
    private const BASE64_SUFFIX = '?=';

    /** @param list<string> $allowedHosts @param list<string> $allowedOrigins */
    public function __construct(
        private ToolService $tools,
        private HttpSessionStore $sessions,
        private array $allowedHosts,
        private array $allowedOrigins,
        private ?string $bearerToken = null,
        private int $maxRequestBytes = 1_048_576,
        private int $maxResponseBytes = 1_048_576,
        private ?ResourceService $resources = null,
        private ?PromptService $prompts = null,
    ) {}

    /**
     * Answer one HTTP request: transport checks, then the envelope, then the
     * revision's own path. Each step either refuses the request or hands on.
     *
     * @param array<string, string> $headers
     * @param string|null $peer Remote address of the caller (e.g. REMOTE_ADDR); used to keep
     *                          an unauthenticated endpoint loopback-only. null skips the check.
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(string $method, array $headers, string $body, ?string $peer = null): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $baseHeaders = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        $refusal = $this->rejectTransport($headers, $peer, $baseHeaders);
        if ($refusal !== null) {
            return $refusal;
        }
        // `2026-07-28` removed sessions and the GET stream, so a caller that
        // declares it gets 405 for both rather than the legacy machinery.
        $protocol = $headers['mcp-protocol-version'] ?? null;
        $modern = $protocol === Profile20260728::VERSION;
        if ($modern && ($method === 'GET' || $method === 'DELETE')) {
            return $this->problem(405, 'This protocol version supports POST only.', $baseHeaders + ['Allow' => 'POST']);
        }
        if ($method === 'DELETE') {
            return $this->handleDelete($headers['mcp-session-id'] ?? '', $baseHeaders);
        }
        if ($method !== 'POST') {
            return $this->problem(405, 'Only POST and session DELETE are supported.', $baseHeaders + ['Allow' => 'POST, DELETE']);
        }
        $message = [];
        $refusal = $this->rejectEnvelope($headers, $body, $baseHeaders, $message);
        if ($refusal !== null) {
            return $refusal;
        }
        $rpcMethod = $message['method'] ?? null;
        if ($modern) {
            return $this->handleModern($message, $headers, $baseHeaders);
        }
        if ($rpcMethod !== 'initialize' && $protocol === null) {
            return $this->problem(400, 'MCP-Protocol-Version is required after initialization.', $baseHeaders);
        }
        $session = $headers['mcp-session-id'] ?? null;
        if ($rpcMethod === 'initialize') {
            return $this->handleInitialize($message, $session, $baseHeaders);
        }
        $refusal = $this->requireLiveSession($session, $rpcMethod, $baseHeaders);
        if ($refusal !== null) {
            return $refusal;
        }
        if (!array_key_exists('id', $message)) {
            // Stateless PHP HTTP workers cannot interrupt an already-running request;
            // cancellation notifications are accepted for protocol compatibility.
            return ['status' => 202, 'headers' => $baseHeaders, 'body' => ''];
        }
        return $this->serveSessionRequest($message, (string) $protocol, $headers, $baseHeaders);
    }

    /**
     * The refusal for a caller this endpoint does not serve, or null: Host, then
     * Origin, then authentication, all before anything reads the body.
     *
     * @param array<string, string> $headers lower-cased request headers
     * @param array<string, string> $baseHeaders
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private function rejectTransport(array $headers, ?string $peer, array $baseHeaders): ?array
    {
        $host = strtolower(trim($headers['host'] ?? ''));
        if ($host === '' || !in_array($host, $this->allowedHosts, true)) {
            return $this->problem(421, 'Unrecognized Host header.', $baseHeaders);
        }
        $origin = $headers['origin'] ?? null;
        if ($origin !== null && !in_array($origin, $this->allowedOrigins, true)) {
            return $this->problem(403, 'Origin is not allowed.', $baseHeaders);
        }
        // Without a bearer token, any reachable client could open sessions and
        // exhaust capacity. Refuse non-loopback callers so an unauthenticated
        // endpoint is only usable from the local host (a token lifts this).
        if ($this->bearerToken === null && $peer !== null && !self::isLoopback($peer)) {
            return $this->problem(401, 'Bearer authentication is required from non-loopback clients.', $baseHeaders + ['WWW-Authenticate' => 'Bearer']);
        }
        if ($this->bearerToken !== null) {
            $authorization = $headers['authorization'] ?? '';
            $presented = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
            if (!hash_equals($this->bearerToken, $presented)) {
                return $this->problem(401, 'Bearer authentication is required.', $baseHeaders + ['WWW-Authenticate' => 'Bearer']);
            }
        }

        return null;
    }

    /**
     * End a 2025-11-25 session. An unknown or empty session id is
     * acknowledged with the same 204.
     *
     * @param array<string, string> $baseHeaders
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function handleDelete(string $session, array $baseHeaders): array
    {
        $unavailable = $this->withSessionStore(function () use ($session): void {
            if ($session !== '' && $this->sessions->exists($session)) {
                $this->sessions->delete($session);
            }
        }, $baseHeaders);

        return $unavailable ?? ['status' => 204, 'headers' => $baseHeaders, 'body' => ''];
    }

    /**
     * The refusal for a POST whose envelope is unusable, or null with the
     * decoded message in $message.
     *
     * Checked in a fixed order: size, Content-Type, Accept, a declared revision
     * this server does not serve, then the body itself.
     *
     * @param array<string, string> $headers lower-cased request headers
     * @param array<string, string> $baseHeaders
     * @param array<mixed> $message
     * @param-out array<mixed> $message
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private function rejectEnvelope(array $headers, string $body, array $baseHeaders, array &$message): ?array
    {
        if (strlen($body) > $this->maxRequestBytes) {
            return $this->problem(413, 'Request body exceeds the configured byte limit.', $baseHeaders);
        }
        if (!str_starts_with(strtolower($headers['content-type'] ?? ''), 'application/json')) {
            return $this->problem(415, 'Content-Type must be application/json.', $baseHeaders);
        }
        $accept = strtolower($headers['accept'] ?? '');
        if (!str_contains($accept, 'application/json') || !str_contains($accept, 'text/event-stream')) {
            return $this->problem(406, 'Accept must include application/json and text/event-stream.', $baseHeaders);
        }
        $protocol = $headers['mcp-protocol-version'] ?? null;
        // supported(), not the SUPPORTED constant: with KNOSSOS_LEGACY_PROTOCOL=0
        // the constant still lists the withdrawn revision, so a legacy client
        // would slip past this gate into the session path and be refused further
        // in — surfacing as 200 with an embedded error instead of 400, and
        // advertising a revision this server has stopped serving.
        if ($protocol !== null && !in_array($protocol, ProtocolNegotiator::supported(), true)) {
            // The specification requires the supported set to be advertised, so a
            // client can retry on common ground instead of blindly downgrading.
            $unsupported = new UnsupportedProtocolVersionException($protocol, ProtocolNegotiator::supported());
            return $this->json(400, [
                'jsonrpc' => '2.0', 'id' => null,
                'error' => ['code' => $unsupported->getCode(), 'message' => $unsupported->getMessage(), 'data' => $unsupported->data()],
            ], $baseHeaders);
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new JsonException('JSON-RPC body must be an object.');
            }
        } catch (JsonException) {
            return $this->json(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], $baseHeaders);
        }
        // The same id rule as stdio, before anything echoes the id back.
        if (JsonRpcId::isInvalid($decoded)) {
            return $this->json(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid Request']], $baseHeaders);
        }
        $message = $decoded;

        return null;
    }

    /**
     * Open a 2025-11-25 session: answer `initialize`, then store the session
     * only when the handshake succeeded.
     *
     * @param array<string, mixed> $message
     * @param array<string, string> $baseHeaders
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function handleInitialize(array $message, ?string $session, array $baseHeaders): array
    {
        if ($session !== null) {
            return $this->problem(400, 'Initialization must not supply a session ID.', $baseHeaders);
        }
        try {
            $response = $this->dispatcher()->handle($message);
        } catch (Throwable $error) {
            return $this->internalError($message['id'] ?? null, $error, $baseHeaders);
        }
        if ($response === null) {
            // initialize delivered as a notification (no id): acknowledge with
            // no session rather than an empty JSON body.
            return ['status' => 202, 'headers' => $baseHeaders, 'body' => ''];
        }
        if (isset($response['error'])) {
            return $this->json(200, $response, $baseHeaders);
        }
        $created = '';
        $unavailable = $this->withSessionStore(function () use (&$created): void {
            $created = $this->sessions->create();
        }, $baseHeaders);

        return $unavailable ?? $this->json(200, $response, $baseHeaders + ['Mcp-Session-Id' => $created]);
    }

    /**
     * The response that ends a session request before dispatch, or null when
     * the session exists, has completed its handshake, and has been touched.
     *
     * `notifications/initialized` is answered here, since completing the
     * handshake is the session's own transition and needs no dispatcher.
     *
     * @param array<string, string> $baseHeaders
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private function requireLiveSession(?string $session, mixed $rpcMethod, array $baseHeaders): ?array
    {
        $exists = false;
        $unavailable = $this->withSessionStore(function () use ($session, &$exists): void {
            $exists = is_string($session) && $this->sessions->exists($session);
        }, $baseHeaders);
        if ($unavailable !== null) {
            return $unavailable;
        }
        if (!$exists || $session === null) {
            return $this->problem(404, 'Unknown or expired MCP session.', $baseHeaders);
        }
        if ($rpcMethod === 'notifications/initialized') {
            $transition = '';
            $unavailable = $this->withSessionStore(function () use ($session, &$transition): void {
                $transition = $this->sessions->markInitialized($session);
            }, $baseHeaders);
            return $unavailable ?? match ($transition) {
                HttpSessionStore::INITIALIZED => ['status' => 202, 'headers' => $baseHeaders, 'body' => ''],
                HttpSessionStore::ALREADY_INITIALIZED => $this->problem(409, 'MCP session has already been initialized.', $baseHeaders),
                default => $this->problem(404, 'Unknown or expired MCP session.', $baseHeaders),
            };
        }
        $initialized = false;
        $unavailable = $this->withSessionStore(function () use ($session, &$initialized): void {
            $initialized = $this->sessions->initialized($session);
        }, $baseHeaders);
        if ($unavailable !== null) {
            return $unavailable;
        }
        if (!$initialized) {
            return $this->problem(409, 'MCP session has not been initialized.', $baseHeaders);
        }

        // Slide the expiry forward so an actively used session survives.
        return $this->withSessionStore(fn() => $this->sessions->touch($session), $baseHeaders);
    }

    /**
     * Run one session-store operation, or answer 503 when the store fails.
     *
     * The store's own detail never reaches the client, with one exception: a
     * full store says so, because that is the caller's to act on.
     *
     * @param \Closure(): mixed $operation
     * @param array<string, string> $baseHeaders
     * @return array{status: int, headers: array<string, string>, body: string}|null null when the operation succeeded
     */
    private function withSessionStore(\Closure $operation, array $baseHeaders): ?array
    {
        try {
            $operation();
        } catch (Throwable $error) {
            return $this->problem(503, $error instanceof RuntimeException && $error->getCode() === HttpSessionStore::CAPACITY_ERROR
                ? $error->getMessage()
                : 'HTTP session storage is temporarily unavailable.', $baseHeaders);
        }

        return null;
    }

    /**
     * Serve a request inside an initialized 2025-11-25 session, under the
     * revision that session negotiated.
     *
     * @param array<string, mixed> $message a request: it carries an id
     * @param array<string, string> $headers lower-cased request headers
     * @param array<string, string> $baseHeaders
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function serveSessionRequest(array $message, string $protocol, array $headers, array $baseHeaders): array
    {
        // The session's revision is the header's: a body that declares another
        // one is refused, as on the 2026-07-28 path, instead of silently winning.
        $mismatch = $this->versionMismatch($message, $headers);
        if ($mismatch !== null) {
            return $this->json(400, ['jsonrpc' => '2.0', 'id' => $message['id'] ?? null, 'error' => ['code' => self::HEADER_MISMATCH, 'message' => $mismatch]], $baseHeaders);
        }
        try {
            $dispatcher = $this->dispatcher();
            $dispatcher->resumeSession($protocol);
            $response = $dispatcher->handle($message);
        } catch (Throwable $error) {
            return $this->internalError($message['id'] ?? null, $error, $baseHeaders);
        }
        if ($response === null) {
            return ['status' => 202, 'headers' => $baseHeaders, 'body' => ''];
        }
        return $this->json(200, $response, $baseHeaders);
    }

    /**
     * Serve a `2026-07-28` request: no session, no handshake, and the mirrored
     * request headers validated against the body.
     *
     * @param array<string, mixed> $message
     * @param array<string, string> $headers lower-cased request headers
     * @param array<string, string> $baseHeaders
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function handleModern(array $message, array $headers, array $baseHeaders): array
    {
        $rpcMethod = $message['method'] ?? null;
        $id = $message['id'] ?? null;

        // This revision defines no client-to-server notification over Streamable
        // HTTP, and explicitly leaves header rules for notification POSTs
        // undefined; accept and acknowledge rather than invent a requirement.
        if (!array_key_exists('id', $message)) {
            return ['status' => 202, 'headers' => $baseHeaders, 'body' => ''];
        }
        if (!is_string($rpcMethod)) {
            return $this->json(400, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32600, 'message' => 'Invalid Request']], $baseHeaders);
        }
        $mismatch = $this->headerMismatch($message, $rpcMethod, $headers);
        if ($mismatch !== null) {
            return $this->json(400, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => self::HEADER_MISMATCH, 'message' => $mismatch]], $baseHeaders);
        }
        try {
            $response = $this->dispatcher()->handle($message);
        } catch (Throwable $error) {
            return $this->internalError($id, $error, $baseHeaders);
        }
        if ($response === null) {
            return ['status' => 202, 'headers' => $baseHeaders, 'body' => ''];
        }
        // An unimplemented method answers 404, which is how a client tells a
        // modern server apart from a legacy endpoint that does not host this
        // path at all. The JSON-RPC body is what disambiguates the two.
        $status = ($response['error']['code'] ?? null) === -32601 ? 404 : 200;

        return $this->json($status, $response, $baseHeaders);
    }

    /**
     * A fresh dispatcher for one request.
     *
     * Fresh every time: the session's state lives in the session store, and a
     * dispatcher's own state (handshake, pinned revision, pending cancels) must
     * not carry from one request to the next. It is also why a cancel never
     * reaches a running tool here: the worker serving the tool cannot read the
     * request that names it, so no cancellation poll is passed.
     */
    private function dispatcher(): McpDispatcher
    {
        return new McpDispatcher($this->tools, $this->resources, $this->prompts);
    }

    /**
     * Why the mirrored headers do not match the body, or null when they agree.
     *
     * Intermediaries route and authorise on these headers while the server acts
     * on the body; letting the two disagree is the confused-deputy the rule
     * exists to close.
     *
     * @param array<string, mixed> $message
     * @param array<string, string> $headers
     */
    private function headerMismatch(array $message, string $rpcMethod, array $headers): ?string
    {
        $versionMismatch = $this->versionMismatch($message, $headers);
        if ($versionMismatch !== null) {
            return $versionMismatch;
        }
        $headerMethod = $headers['mcp-method'] ?? null;
        if ($headerMethod === null) {
            return 'Header mismatch: Mcp-Method header is required.';
        }
        if ($headerMethod !== $rpcMethod) {
            return sprintf('Header mismatch: Mcp-Method header value %s does not match body value %s', json_encode($headerMethod), json_encode($rpcMethod));
        }

        $params = $message['params'] ?? [];
        $bodyName = match ($rpcMethod) {
            'tools/call', 'prompts/get' => is_array($params) ? ($params['name'] ?? null) : null,
            'resources/read' => is_array($params) ? ($params['uri'] ?? null) : null,
            default => null,
        };
        if (!in_array($rpcMethod, ['tools/call', 'prompts/get', 'resources/read'], true)) {
            return null;
        }
        $headerName = $headers['mcp-name'] ?? null;
        if ($headerName === null) {
            return 'Header mismatch: Mcp-Name header is required for ' . $rpcMethod . '.';
        }
        $decoded = self::decodeHeaderValue($headerName);
        if ($decoded === null) {
            return 'Header mismatch: Mcp-Name header is not a valid Base64 sentinel value.';
        }
        if (!is_string($bodyName) || $decoded !== $bodyName) {
            return sprintf('Header mismatch: Mcp-Name header value %s does not match body value %s', json_encode($decoded), json_encode($bodyName));
        }

        return null;
    }

    /**
     * Why the body's `_meta` revision contradicts the MCP-Protocol-Version header, or null when it agrees or declares none.
     *
     * @param array<string, mixed> $message
     * @param array<string, string> $headers lower-cased request headers
     */
    private function versionMismatch(array $message, array $headers): ?string
    {
        $declaredVersion = $headers['mcp-protocol-version'] ?? null;
        $bodyVersion = ProtocolNegotiator::requestedVersion($message);
        if ($bodyVersion !== null && $bodyVersion !== $declaredVersion) {
            return sprintf('Header mismatch: MCP-Protocol-Version header value %s does not match body value %s', json_encode($declaredVersion), json_encode($bodyVersion));
        }

        return null;
    }

    /**
     * Resolve a mirrored header value, unwrapping the `=?base64?…?=` sentinel
     * that carries values which cannot travel as plain ASCII.
     *
     * Returns null when the sentinel is present but its payload is not valid
     * Base64 — a malformed value must be rejected, not silently compared raw.
     */
    private static function decodeHeaderValue(string $value): ?string
    {
        if (!str_starts_with($value, self::BASE64_PREFIX) || !str_ends_with($value, self::BASE64_SUFFIX)) {
            return $value;
        }
        $payload = substr($value, strlen(self::BASE64_PREFIX), -strlen(self::BASE64_SUFFIX));
        $decoded = base64_decode($payload, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Map an unexpected transport-layer failure to a generic JSON-RPC internal
     * error. The raw detail is logged for the operator; nothing about the
     * exception (message or trace) reaches the client.
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function internalError(mixed $id, Throwable $error, array $headers): array
    {
        error_log('knossos http endpoint: ' . $error->getMessage());
        return $this->json(500, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => 'Internal error']], $headers);
    }

    /**
     * A JSON response, downgraded to an error when it exceeds the byte cap.
     *
     * @param array<string, mixed> $payload @param array<string, string> $headers @return array{status: int, headers: array<string, string>, body: string}
     */
    private function json(int $status, array $payload, array $headers): array
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($encoded) > $this->maxResponseBytes) {
            $status = 500;
            $encoded = json_encode(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? null, 'error' => ['code' => -32001, 'message' => 'Response exceeds the configured byte limit.']], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return ['status' => $status, 'headers' => $headers + ['Content-Type' => 'application/json'], 'body' => $encoded];
    }

    /**
     * A non-JSON-RPC problem response, used for transport-level refusals.
     *
     * @param array<string, string> $headers @return array{status: int, headers: array<string, string>, body: string}
     */
    private function problem(int $status, string $message, array $headers): array
    {
        return $this->json($status, ['error' => $message], $headers);
    }

    /** Whether a remote address is the local host (IPv4 127.0.0.0/8 or IPv6 ::1). */
    private static function isLoopback(string $peer): bool
    {
        $peer = trim($peer);
        if ($peer === '::1' || $peer === '::ffff:127.0.0.1') {
            return true;
        }
        return filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($peer, '127.');
    }
}
