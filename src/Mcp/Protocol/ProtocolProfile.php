<?php

declare(strict_types=1);

namespace Knossos\Mcp\Protocol;

/**
 * One MCP revision's lifecycle and result-envelope rules.
 *
 * The revisions Knossos speaks share their method semantics — `tools/list`,
 * `tools/call`, `resources/*`, and `prompts/*` behave identically — and differ
 * only in how a connection is established and how a result is shaped. Isolating
 * that difference behind a profile keeps one dispatcher in {@see \Knossos\Mcp\StdioServer}
 * instead of forking the server per revision.
 */
interface ProtocolProfile
{
    /** The revision this profile implements, e.g. `2026-07-28`. */
    public function version(): string;

    /**
     * Apply the revision's result-envelope rules to a successful result.
     *
     * @param array<string, mixed> $result
     * @param string $method the JSON-RPC method that produced $result
     * @return array<string, mixed>
     */
    public function decorate(array $result, string $method): array;

    /**
     * JSON-RPC error code for a resource URI the server does not serve.
     *
     * `2025-11-25` used -32002; `2026-07-28` aligns with JSON-RPC by returning
     * -32602 (Invalid Params).
     */
    public function resourceNotFoundCode(): int;

    /**
     * Whether the server should emit periodic `ping` requests to keep an idle
     * stdio transport warm.
     *
     * `2026-07-28` removes `ping` from the protocol. It can afford to: the
     * revision is stateless, so a dropped connection costs only a reconnect.
     */
    public function emitsKeepalive(): bool;

    /**
     * Whether requests must be preceded by `initialize` and
     * `notifications/initialized`.
     */
    public function requiresHandshake(): bool;
}
