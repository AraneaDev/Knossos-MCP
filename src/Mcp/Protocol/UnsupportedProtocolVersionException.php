<?php

declare(strict_types=1);

namespace Knossos\Mcp\Protocol;

use RuntimeException;

/**
 * A client asked for a revision this server does not implement.
 *
 * Carries the supported list because the specification requires the error to
 * advertise it: that is what lets a client retry against a version both sides
 * speak instead of falling back blindly to the handshake era.
 */
final class UnsupportedProtocolVersionException extends RuntimeException
{
    public const CODE = -32022;

    /** @param list<string> $supported */
    public function __construct(public readonly string $requested, public readonly array $supported)
    {
        parent::__construct(sprintf('Unsupported protocol version: %s', $requested), self::CODE);
    }

    /**
     * The error payload, carrying the supported set so a client can retry on common ground.
     *
     * @return array{supported: list<string>, requested: string}
     */
    public function data(): array
    {
        return ['supported' => $this->supported, 'requested' => $this->requested];
    }
}
