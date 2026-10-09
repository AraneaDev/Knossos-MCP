<?php

declare(strict_types=1);

namespace Knossos\Mcp;

/**
 * The JSON-RPC request id rule, shared by every transport.
 *
 * MCP requires a request id to be an integer or a string. Any other id
 * (null, a fraction, an array, an object, or a number past the float range
 * that decodes to INF) cannot be echoed back safely, so the request is
 * invalid and answered with id null. Kept apart from the transports so stdio
 * and HTTP apply one rule rather than two copies of it.
 */
final class JsonRpcId
{
    /** The id a reply may echo: the request's own when it is an integer or a string, otherwise null. */
    public static function reply(mixed $id): int|string|null
    {
        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * Whether a message carries an id that is neither an integer nor a string.
     *
     * @param array<string, mixed> $message
     */
    public static function isInvalid(array $message): bool
    {
        return array_key_exists('id', $message) && self::reply($message['id']) === null;
    }
}
