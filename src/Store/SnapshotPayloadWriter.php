<?php

declare(strict_types=1);

namespace Knossos\Store;

use DeflateContext;

/**
 * Builds a snapshot payload without ever holding it whole.
 *
 * The straightforward way to archive a snapshot — fetch every row into arrays,
 * json_encode the lot, compress the result — costs several times the payload in
 * peak memory: this repository's 30 MB snapshot needed 145 MB to write, and the
 * multiplier grows with the project rather than with anything the operator can
 * see. Compression runs incrementally instead, so what is held at once is one
 * row plus the compressed output.
 *
 * The uncompressed size is counted as it goes, because that is what callers
 * record as the snapshot's size and what the byte ceiling is measured against.
 */
final class SnapshotPayloadWriter
{
    private DeflateContext $deflate;

    private string $compressed = '';

    private int $bytes = 0;

    private bool $exceeded = false;

    /**
     * @param non-empty-string $prefix marker distinguishing a compressed payload from a plain one
     * @param int $maxBytes uncompressed ceiling past which the payload is abandoned
     */
    public function __construct(private readonly string $prefix, int $level, private readonly int $maxBytes)
    {
        $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => $level]);
        // Only a malformed encoding or level reaches this, both of which are
        // fixed constants here.
        $this->deflate = $deflate === false ? throw new \RuntimeException('Unable to start snapshot compression.') : $deflate;
    }

    /**
     * Append a fragment of the payload.
     *
     * Once the ceiling is passed nothing further is compressed: the payload is
     * already being abandoned, and continuing to deflate it would spend the
     * effort the ceiling exists to avoid.
     */
    public function write(string $chunk): void
    {
        if ($this->exceeded) {
            return;
        }
        $this->bytes += strlen($chunk);
        if ($this->bytes > $this->maxBytes) {
            $this->exceeded = true;

            return;
        }
        $this->compressed .= deflate_add($this->deflate, $chunk, ZLIB_NO_FLUSH);
    }

    /** Whether the payload outgrew its ceiling and must be recorded as incomplete. */
    public function exceeded(): bool
    {
        return $this->exceeded;
    }

    /** The uncompressed size written so far, which is the snapshot's reported size. */
    public function byteSize(): int
    {
        return $this->bytes;
    }

    /** Close the stream and return the stored representation. */
    public function finish(): string
    {
        $this->compressed .= deflate_add($this->deflate, '', ZLIB_FINISH);

        return $this->prefix . base64_encode($this->compressed);
    }
}
