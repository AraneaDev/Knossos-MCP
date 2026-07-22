<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use JsonException;
use RuntimeException;
use Throwable;

final readonly class HttpEndpoint
{
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
    ) {}

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(string $method, array $headers, string $body): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $baseHeaders = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        $host = strtolower(trim($headers['host'] ?? ''));
        if ($host === '' || !in_array($host, $this->allowedHosts, true)) {
            return $this->problem(421, 'Unrecognized Host header.', $baseHeaders);
        }
        $origin = $headers['origin'] ?? null;
        if ($origin !== null && !in_array($origin, $this->allowedOrigins, true)) {
            return $this->problem(403, 'Origin is not allowed.', $baseHeaders);
        }
        if ($this->bearerToken !== null) {
            $authorization = $headers['authorization'] ?? '';
            $presented = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
            if (!hash_equals($this->bearerToken, $presented)) {
                return $this->problem(401, 'Bearer authentication is required.', $baseHeaders + ['WWW-Authenticate' => 'Bearer']);
            }
        }
        if ($method === 'DELETE') {
            $session = $headers['mcp-session-id'] ?? '';
            try {
                if ($session !== '' && $this->sessions->exists($session)) {
                    $this->sessions->delete($session);
                }
            } catch (Throwable) {
                return $this->problem(503, 'HTTP session storage is temporarily unavailable.', $baseHeaders);
            }
            return ['status' => 204, 'headers' => $baseHeaders, 'body' => ''];
        }
        if ($method !== 'POST') {
            return $this->problem(405, 'Only POST and session DELETE are supported.', $baseHeaders + ['Allow' => 'POST, DELETE']);
        }
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
        if ($protocol !== null && $protocol !== StdioServer::PROTOCOL_VERSION) {
            return $this->problem(400, 'Unsupported MCP-Protocol-Version.', $baseHeaders);
        }
        try {
            $message = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($message) || array_is_list($message)) {
                throw new JsonException('JSON-RPC body must be an object.');
            }
        } catch (JsonException $error) {
            return $this->json(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], $baseHeaders);
        }
        $rpcMethod = $message['method'] ?? null;
        if ($rpcMethod !== 'initialize' && $protocol === null) {
            return $this->problem(400, 'MCP-Protocol-Version is required after initialization.', $baseHeaders);
        }
        $session = $headers['mcp-session-id'] ?? null;
        if ($rpcMethod === 'initialize') {
            if ($session !== null) {
                return $this->problem(400, 'Initialization must not supply a session ID.', $baseHeaders);
            }
            $server = new StdioServer($this->tools, resources: $this->resources);
            $response = $server->handle($message);
            if ($response === null || isset($response['error'])) {
                return $this->json(200, $response ?? [], $baseHeaders);
            }
            try {
                $session = $this->sessions->create();
            } catch (Throwable $error) {
                return $this->problem(503, $error instanceof RuntimeException && $error->getCode() === HttpSessionStore::CAPACITY_ERROR
                    ? $error->getMessage()
                    : 'HTTP session storage is temporarily unavailable.', $baseHeaders);
            }
            return $this->json(200, $response, $baseHeaders + ['Mcp-Session-Id' => $session]);
        }
        try {
            $sessionExists = is_string($session) && $this->sessions->exists($session);
        } catch (Throwable) {
            return $this->problem(503, 'HTTP session storage is temporarily unavailable.', $baseHeaders);
        }
        if (!$sessionExists) {
            return $this->problem(404, 'Unknown or expired MCP session.', $baseHeaders);
        }
        if ($rpcMethod === 'notifications/initialized') {
            try {
                $transition = $this->sessions->markInitialized($session);
            } catch (Throwable) {
                return $this->problem(503, 'HTTP session storage is temporarily unavailable.', $baseHeaders);
            }
            return match ($transition) {
                HttpSessionStore::INITIALIZED => ['status' => 202, 'headers' => $baseHeaders, 'body' => ''],
                HttpSessionStore::ALREADY_INITIALIZED => $this->problem(409, 'MCP session has already been initialized.', $baseHeaders),
                default => $this->problem(404, 'Unknown or expired MCP session.', $baseHeaders),
            };
        }
        try {
            $initialized = $this->sessions->initialized($session);
        } catch (Throwable) {
            return $this->problem(503, 'HTTP session storage is temporarily unavailable.', $baseHeaders);
        }
        if (!$initialized) {
            return $this->problem(409, 'MCP session has not been initialized.', $baseHeaders);
        }
        if (!array_key_exists('id', $message)) {
            // Stateless PHP HTTP workers cannot interrupt an already-running request;
            // cancellation notifications are accepted for protocol compatibility.
            return ['status' => 202, 'headers' => $baseHeaders, 'body' => ''];
        }
        $server = new StdioServer($this->tools, resources: $this->resources);
        $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $response = $server->handle($message);
        return $this->json(200, $response ?? [], $baseHeaders);
    }

    /** @param array<string, mixed> $payload @param array<string, string> $headers @return array{status: int, headers: array<string, string>, body: string} */
    private function json(int $status, array $payload, array $headers): array
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > $this->maxResponseBytes) {
            $status = 500;
            $encoded = json_encode(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? null, 'error' => ['code' => -32001, 'message' => 'Response exceeds the configured byte limit.']], JSON_THROW_ON_ERROR);
        }
        return ['status' => $status, 'headers' => $headers + ['Content-Type' => 'application/json'], 'body' => $encoded];
    }

    /** @param array<string, string> $headers @return array{status: int, headers: array<string, string>, body: string} */
    private function problem(int $status, string $message, array $headers): array
    {
        return $this->json($status, ['error' => $message], $headers);
    }
}
