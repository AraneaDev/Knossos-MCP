<?php

declare(strict_types=1);

namespace Knossos\Store;

/**
 * The one JSON encoding and timestamp format the graph schema stores.
 *
 * Shared rather than copied into each store class: an attribute column and a
 * snapshot payload must encode identically, and two copies of the flags would
 * be free to drift apart.
 */
final class SqliteValues
{
    /**
     * Encode an attributes array for storage, throwing rather than storing malformed JSON.
     *
     * @param array<string, mixed> $value
     */
    public static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The current timestamp in the format the schema stores. */
    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
