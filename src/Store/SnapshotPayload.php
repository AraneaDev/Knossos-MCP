<?php

declare(strict_types=1);

namespace Knossos\Store;

use RuntimeException;

/**
 * How an archived snapshot's facts are stored.
 *
 * A snapshot is a full copy of a project's graph, kept so a later scan can be
 * diffed against it. Stored as plain JSON, this repository's own snapshot is
 * 30 MB, and the five retained per project dominated the database: 328 MB of
 * file for a graph of 8,301 nodes and 39,716 edges. The payload is highly
 * repetitive — the same column names and stable-id prefixes on every row — so
 * it compresses to about a fifth of that.
 *
 * The stored form is base64 behind a sentinel prefix rather than raw deflate
 * bytes: the column is declared TEXT, and keeping it ASCII means a dump, a
 * backup, or a bundle export cannot mangle it. Anything without the prefix is
 * read as the plain JSON earlier versions wrote, so existing snapshots keep
 * answering after an upgrade.
 */
final readonly class SnapshotPayload
{
    /**
     * Marks a compressed payload. Chosen so it can never be confused with JSON,
     * which always starts with `{` here.
     */
    private const PREFIX = 'gzip64:';

    /**
     * Balanced setting: on a 29.6 MB payload, level 6 reaches 20% of the
     * original against 23% for level 1, and the extra ~340 ms is repaid by
     * writing 24 MB fewer bytes.
     */
    private const LEVEL = 6;

    /** Compress an encoded payload for storage. */
    public static function encode(string $json): string
    {
        return self::PREFIX . base64_encode(gzencode($json, self::LEVEL));
    }

    /**
     * Return the payload's JSON, decompressing it when it was stored that way.
     *
     * @throws RuntimeException when a compressed payload cannot be read back,
     *         which means the row is damaged rather than merely old
     */
    public static function decode(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }
        $compressed = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        $json = $compressed === false ? false : @gzdecode($compressed);
        if (!is_string($json)) {
            throw new RuntimeException('A stored snapshot payload could not be decompressed; the row is damaged.');
        }

        return $json;
    }
}
