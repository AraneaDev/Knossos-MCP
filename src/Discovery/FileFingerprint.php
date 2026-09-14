<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * Bounded single-pass fingerprint of a discovered file. Streams the byte
 * content exactly once to compute the SHA-256 content hash, the physical line
 * count and the Git blob id of the same bytes, and never executes or evaluates
 * project code.
 *
 * The blob id is there so a scan can say whether what it read matched the
 * commit it recorded. Git names a blob by hashing `"blob <length>\0" +
 * content`, so computing it over the bytes discovery is already streaming
 * costs one more hash context and no extra read, and it is the only value that
 * can be compared against a commit's tree without fetching that commit's
 * content. Deciding dirtiness any other way means asking git at some later
 * moment, which is a different moment than this read and can disagree with it.
 *
 * Which hash names a blob is the repository's own choice, not a constant. A
 * repository created with `--object-format=sha256` names every object by
 * SHA-256, so a blob id computed as SHA-1 matches nothing in its trees: every
 * tracked file reads as dirty, the recorded set blows its own bound, and the
 * git oracle declines for the life of that graph. The algorithm is therefore
 * threaded in from the caller, which knows it from the length of the commit it
 * captured.
 */
final readonly class FileFingerprint
{
    /**
     * @param ?string $gitBlobHash the Git blob id of the bytes read, under the
     *        repository's own object format, or null when the file changed
     *        size underneath the read. Null is "this read cannot be compared
     *        against a commit", never "it matched".
     */
    public function __construct(public string $contentHash, public int $lineCount, public ?string $gitBlobHash = null) {}

    /**
     * The same fingerprint over bytes the caller has already read.
     *
     * For a file discovery has to read twice anyway, once to fingerprint and
     * once to parse, streaming it here would mean two reads of one path with
     * nothing tying them together: the hash would describe one moment and the
     * parse another. Deriving both from one buffer is what makes a manifest's
     * hash and its metadata describe the same content by construction.
     *
     * Cannot fail, and returns no null blob id: the length the Git header
     * needs is the buffer's own, so there is no size to race against the way
     * {@see self::compute()} has to.
     */
    public static function fromContents(string $contents, string $gitObjectHash = 'sha1'): self
    {
        $lines = substr_count($contents, "\n");
        if ($contents !== '' && !str_ends_with($contents, "\n")) {
            ++$lines;
        }

        return new self(
            hash('sha256', $contents),
            $lines,
            hash($gitObjectHash, 'blob ' . strlen($contents) . "\0" . $contents),
        );
    }

    /**
     * Just the content hash of a file, for a caller comparing one read against
     * another rather than describing a file.
     *
     * Lives here, beside {@see self::compute()} and {@see self::fromContents()},
     * because a second implementation of "the hash discovery stored" is exactly
     * how two hashes that must agree stop agreeing. It is the same algorithm
     * over the same bytes, so a value from any of the three is comparable with
     * a value from either other one.
     *
     * Separate from compute() because compute() also derives a line count and a
     * Git blob id, and a caller that only needs to know whether a file still
     * hashes the same pays for both: measured over 553 files, compute() costs
     * about 1.4x this. That is a real cost on a pass a scan pays for every file
     * it discovered.
     *
     * Null means the bytes could not be read, never "it matched". A path that
     * is not a regular file, such as a directory or a FIFO, is null too: a
     * directory yields no bytes and would hash like an empty file, and opening
     * a FIFO blocks until a writer appears, which would hang the scan re-reading
     * a file swapped for one instead of failing it.
     */
    public static function contentHashOf(string $absolutePath): ?string
    {
        // The type is asked before the open, since the open itself is what
        // blocks on a FIFO. The stat cache is dropped for this path first: a
        // long-running server may have stat()ed it before it was swapped.
        clearstatcache(true, $absolutePath);
        if (!self::isRegular(@stat($absolutePath))) {
            return null;
        }
        // Suppressed, not guarded by is_readable(): a check followed by a read
        // is two moments, and only the read's own failure says what this call
        // actually got. Checked again on the handle for a swap after the stat.
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            if (!self::isRegular(fstat($handle))) {
                return null;
            }
            $context = hash_init('sha256');
            hash_update_stream($context, $handle);

            return hash_final($context);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Whether a stat() or fstat() result describes a regular file; false for a
     * failed stat, a directory, a FIFO, or any other file type.
     *
     * @param array<array-key, int>|false $stat
     */
    private static function isRegular(array|false $stat): bool
    {
        return is_array($stat) && (($stat['mode'] ?? 0) & 0o170000) === 0o100000;
    }

    /**
     * Physical line count is the number of newline terminators plus a trailing
     * unterminated line: an empty file is 0 lines, "a\n" and "a" are both 1,
     * and CRLF terminators are counted once (by their "\n").
     *
     * `$gitObjectHash` is the repository's object format, `sha1` or `sha256`,
     * and decides only the blob id. A caller with no repository to match may
     * leave it at the default; the blob id is then simply never compared
     * against anything. {@see \Knossos\Discovery\DiscoveryConfig} is where the
     * value is validated.
     *
     * Null for a path that is not a regular file, for the reasons
     * {@see self::contentHashOf()} gives: discovery only fingerprints regular
     * files, but the contribution cache re-reads a discovered path later, and a
     * file swapped for a FIFO by then would block the open instead of reading
     * as unreadable.
     */
    public static function compute(string $absolutePath, string $gitObjectHash = 'sha1'): ?self
    {
        clearstatcache(true, $absolutePath);
        if (!self::isRegular(@stat($absolutePath))) {
            return null;
        }
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            return null;
        }
        if (!self::isRegular(fstat($handle))) {
            fclose($handle);

            return null;
        }
        $context = hash_init('sha256');
        // Git's blob header carries the length, so the size has to be known
        // before the first byte is hashed. It is verified against what the
        // stream actually yielded below rather than trusted: a file that grew
        // or shrank underneath the read would otherwise produce a blob id that
        // matches nothing and silently reads as "not dirty".
        $size = @filesize($absolutePath);
        $blob = hash_init($gitObjectHash);
        hash_update($blob, 'blob ' . (is_int($size) ? $size : 0) . "\0");
        $read = 0;
        $lines = 0;
        $sawContent = false;
        $endsWithNewline = false;
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65_536);
                if ($chunk === false) {
                    return null;
                }
                if ($chunk === '') {
                    continue;
                }
                hash_update($context, $chunk);
                hash_update($blob, $chunk);
                $read += strlen($chunk);
                $sawContent = true;
                $lines += substr_count($chunk, "\n");
                $endsWithNewline = $chunk[strlen($chunk) - 1] === "\n";
            }
        } finally {
            fclose($handle);
        }
        if ($sawContent && !$endsWithNewline) {
            ++$lines;
        }
        return new self(hash_final($context), $lines, $size === $read ? hash_final($blob) : null);
    }
}
