<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * Bounded single-pass fingerprint of a discovered file. Streams the byte
 * content exactly once to compute both the SHA-256 content hash and the
 * physical line count, and never executes or evaluates project code.
 */
final readonly class FileFingerprint
{
    public function __construct(public string $contentHash, public int $lineCount) {}

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
        return new self(hash_final($context), $lines);
    }
}
