<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

/**
 * A stream that reports an arbitrary size and counts every byte handed out.
 *
 * Exists because "the read stopped at the limit" is not observable from a
 * reader's return value: a reader that loads a whole oversized file and then
 * measures it reports exactly what a bounded one reports, and only the bytes it
 * asked the stream for tell the two apart. Counting them here is how a bound
 * can be asserted without writing a file large enough for memory to show it,
 * which would be slow, machine-dependent, and still only circumstantial.
 *
 * The content is synthetic and never materialised beyond the chunk being
 * served, so a stream can claim to be megabytes without costing any.
 */
final class CountingStreamWrapper
{
    /** The scheme these streams are addressed by; registered and unregistered around a test. */
    public const SCHEME = 'knossos-counting';

    /** Bytes handed to the caller since the last {@see reset}, across every stream. */
    public static int $bytesServed = 0;

    /** How many bytes a stream claims to hold before reporting EOF. */
    public static int $length = 0;

    /** Set by PHP on every user-space wrapper, whether or not a context was given. */
    public mixed $context = null;

    private int $position = 0;

    /** Register the wrapper and arm it with the size the next stream claims. */
    public static function reset(int $length): void
    {
        self::$bytesServed = 0;
        self::$length = $length;
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
        stream_wrapper_register(self::SCHEME, self::class);
    }

    /** Take the wrapper back out of the process, so no later test inherits it. */
    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    /** Any path under the scheme opens; there is no real file behind it to fail on. */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;

        return true;
    }

    /** Serve up to $count synthetic bytes and record exactly how many were taken. */
    public function stream_read(int $count): string
    {
        $served = max(0, min($count, self::$length - $this->position));
        $this->position += $served;
        self::$bytesServed += $served;

        return str_repeat('x', $served);
    }

    /** EOF only at the claimed length, so an unbounded read runs the whole way. */
    public function stream_eof(): bool
    {
        return $this->position >= self::$length;
    }

    /** Where the read has got to, which file_get_contents consults while copying. */
    public function stream_tell(): int
    {
        return $this->position;
    }

    /** Seekable, so a caller's size probe cannot change what is counted. */
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $target = match ($whence) {
            SEEK_CUR => $this->position + $offset,
            SEEK_END => self::$length + $offset,
            default => $offset,
        };
        if ($target < 0) {
            return false;
        }
        $this->position = $target;

        return true;
    }

    /**
     * The claimed size, with no other stat fields.
     *
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => self::$length];
    }

    /**
     * The same size for a path that was never opened, which is what a caller's
     * pre-read size check would see.
     *
     * @return array<string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        return ['size' => self::$length, 'mode' => 0o100644];
    }
}
