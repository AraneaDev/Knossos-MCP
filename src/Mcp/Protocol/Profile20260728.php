<?php

declare(strict_types=1);

namespace Knossos\Mcp\Protocol;

use Knossos\Application;

/**
 * MCP `2026-07-28`: the stateless revision.
 *
 * Drops the `initialize` handshake, protocol-level sessions, and `ping`; adds a
 * mandatory `resultType` discriminator, cache hints on list-shaped results, and
 * per-result server identity.
 */
final readonly class Profile20260728 implements ProtocolProfile
{
    public const VERSION = '2026-07-28';

    /** Static server-defined data: safe to cache for an hour and to share. */
    private const STATIC_TTL_MS = 3_600_000;
    /** Project-derived data: changes on rescan, and names the user's projects. */
    private const PROJECT_TTL_MS = 60_000;

    /**
     * Cache hints per method, as required by the revision's `CacheableResult`.
     *
     * `cacheScope` is `private` wherever the payload names the user's scanned
     * projects: `public` would authorise a shared intermediary to cache and
     * serve that to another caller.
     *
     * @var array<string, array{ttlMs: int, cacheScope: string}>
     */
    private const CACHE_HINTS = [
        'tools/list' => ['ttlMs' => self::STATIC_TTL_MS, 'cacheScope' => 'public'],
        'prompts/list' => ['ttlMs' => self::STATIC_TTL_MS, 'cacheScope' => 'public'],
        'resources/list' => ['ttlMs' => self::PROJECT_TTL_MS, 'cacheScope' => 'private'],
        'resources/read' => ['ttlMs' => self::PROJECT_TTL_MS, 'cacheScope' => 'private'],
    ];

    public function version(): string
    {
        return self::VERSION;
    }

    public function decorate(array $result, string $method): array
    {
        // `resultType` describes the protocol envelope, not the outcome: a tool
        // that ran and reported failure is still a `complete` result carrying
        // `isError: true`. `task` is reserved for the tasks extension.
        $result['resultType'] = 'complete';
        if (isset(self::CACHE_HINTS[$method])) {
            $result += self::CACHE_HINTS[$method];
        }
        $meta = $result['_meta'] ?? [];
        $meta['io.modelcontextprotocol/serverInfo'] = self::serverInfo();
        $result['_meta'] = $meta;

        return $result;
    }

    public function resourceNotFoundCode(): int
    {
        return -32602;
    }

    public function emitsKeepalive(): bool
    {
        return false;
    }

    public function requiresHandshake(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    public static function serverInfo(): array
    {
        return ['name' => 'knossos', 'version' => Application::VERSION];
    }
}
