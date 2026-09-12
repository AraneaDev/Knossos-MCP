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
 * commit it recorded. Git names a blob by `sha1("blob <length>\0" + content)`,
 * so computing it over the bytes discovery is already streaming costs one more
 * hash context and no extra read, and it is the only value that can be
 * compared against a commit's tree without fetching that commit's content.
 * Deciding dirtiness any other way means asking git at some later moment,
 * which is a different moment than this read and can disagree with it.
 */
final readonly class FileFingerprint
{
    /**
     * @param ?string $gitBlobHash the Git blob id of the bytes read, or null
     *        when the file changed size underneath the read. Null is "this
     *        read cannot be compared against a commit", never "it matched".
     */
    public function __construct(public string $contentHash, public int $lineCount, public ?string $gitBlobHash = null) {}

    /**
     * Physical line count is the number of newline terminators plus a trailing
     * unterminated line: an empty file is 0 lines, "a\n" and "a" are both 1,
     * and CRLF terminators are counted once (by their "\n").
     */
    public static function compute(string $absolutePath): ?self
    {
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            return null;
        }
        $context = hash_init('sha256');
        // Git's blob header carries the length, so the size has to be known
        // before the first byte is hashed. It is verified against what the
        // stream actually yielded below rather than trusted: a file that grew
        // or shrank underneath the read would otherwise produce a blob id that
        // matches nothing and silently reads as "not dirty".
        $size = @filesize($absolutePath);
        $blob = hash_init('sha1');
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
