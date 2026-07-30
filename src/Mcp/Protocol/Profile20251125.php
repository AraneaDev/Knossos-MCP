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

    /** {@inheritDoc} */
    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * {@inheritDoc}
     *
     * This revision defines no envelope additions, so results travel exactly as
     * the dispatcher built them.
     */
    public function decorate(array $result, string $method): array
    {
        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * -32002, which predates this revision's successor aligning the code with
     * JSON-RPC's -32602 (Invalid Params).
     */
    public function resourceNotFoundCode(): int
    {
        return -32002;
    }

    /**
     * {@inheritDoc}
     *
     * True: `ping` still exists in this revision, and hosts close an idle stdio
     * transport at 60 seconds.
     */
    public function emitsKeepalive(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * True: `initialize` then `notifications/initialized` must precede any other
     * method, and this profile enforces that gate.
     */
    public function requiresHandshake(): bool
    {
        return true;
    }
}
