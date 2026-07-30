<?php

declare(strict_types=1);

namespace Knossos\Mcp\Protocol;

/**
 * Chooses the {@see ProtocolProfile} that governs a message.
 *
 * `2026-07-28` removed the handshake, so a connection no longer announces its
 * revision once and for all. Selection is therefore per message, with one piece
 * of state: a client that opened with `initialize` has identified itself as
 * handshake-era and stays there, because such a client never sends the `_meta`
 * that would identify it otherwise.
 */
final class ProtocolNegotiator
{
    /** `_meta` key carrying the revision on every `2026-07-28` request. */
    public const VERSION_META_KEY = 'io.modelcontextprotocol/protocolVersion';

    /** Newest first: this is the order advertised by `server/discover`. */
    public const SUPPORTED = [Profile20260728::VERSION, Profile20251125::VERSION];

    private ?ProtocolProfile $pinned = null;

    /**
     * Whether the deprecated handshake revision is still served.
     *
     * Defaults on because the clients in the field still speak it — Claude Code
     * 2.1.220 ships `2025-11-25` as its newest supported revision and does not
     * recognise `2026-07-28`. Set `KNOSSOS_LEGACY_PROTOCOL=0` to refuse it, so
     * removal can be rehearsed against real clients before the code is deleted.
     */
    public static function legacyEnabled(): bool
    {
        return getenv('KNOSSOS_LEGACY_PROTOCOL') !== '0';
    }

    /**
     * The profile governing one message, pinning a handshake client to its revision.
     *
     * @param array<string, mixed> $message
     * @throws UnsupportedProtocolVersionException when the client names a revision this server does not implement
     */
    public function select(array $message): ProtocolProfile
    {
        $legacy = self::legacyEnabled();
        if (($message['method'] ?? null) === 'initialize') {
            if (!$legacy) {
                throw new UnsupportedProtocolVersionException(Profile20251125::VERSION, self::supported());
            }
            // Only a handshake-era client sends this; pin the connection so its
            // later, `_meta`-less requests are not mistaken for stateless ones.
            return $this->pinned = new Profile20251125();
        }

        $requested = self::requestedVersion($message);
        if ($requested !== null) {
            return match (true) {
                $requested === Profile20260728::VERSION => new Profile20260728(),
                $requested === Profile20251125::VERSION && $legacy => new Profile20251125(),
                default => throw new UnsupportedProtocolVersionException($requested, self::supported()),
            };
        }

        // No declared revision: honour the connection's pin, otherwise treat the
        // caller as stateless. A `2025-11-25` client always sends `initialize`
        // first, so it has pinned itself before reaching this line.
        return $this->pinned ?? new Profile20260728();
    }

    /**
     * The revision a message declares in `_meta`, or null when it declares none.
     *
     * @param array<string, mixed> $message
     */
    public static function requestedVersion(array $message): ?string
    {
        $params = $message['params'] ?? null;
        if (!is_array($params)) {
            return null;
        }
        $meta = $params['_meta'] ?? null;
        if (!is_array($meta)) {
            return null;
        }
        $version = $meta[self::VERSION_META_KEY] ?? null;

        return is_string($version) ? $version : null;
    }

    /**
     * The revisions actually on offer, which is what `server/discover` must
     * advertise — promising a revision the negotiator would then refuse is
     * worse than not offering it.
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        return self::legacyEnabled() ? self::SUPPORTED : [Profile20260728::VERSION];
    }
}
