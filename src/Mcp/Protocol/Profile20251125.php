<?php

declare(strict_types=1);

namespace Knossos\Mcp\Protocol;

/**
 * MCP `2025-11-25`: the handshake-based revision.
 *
 * Deprecated in favour of {@see Profile20260728}, but retained for clients that
 * have not migrated. Its output is deliberately byte-identical to what Knossos
 * emitted before revision support was introduced, so the existing conformance
 * tests remain a regression net rather than being rewritten alongside the code
 * they guard.
 *
 * Scheduled for removal once the specification's twelve-month deprecation
 * window for initialization-based revisions closes (no earlier than 2027-07).
 */
final readonly class Profile20251125 implements ProtocolProfile
{
    public const VERSION = '2025-11-25';

    public function version(): string
    {
        return self::VERSION;
    }

    public function decorate(array $result, string $method): array
    {
        // This revision defines no envelope additions: results travel exactly as
        // the dispatcher built them.
        return $result;
    }

    public function resourceNotFoundCode(): int
    {
        return -32002;
    }

    public function emitsKeepalive(): bool
    {
        return true;
    }

    public function requiresHandshake(): bool
    {
        return true;
    }
}
