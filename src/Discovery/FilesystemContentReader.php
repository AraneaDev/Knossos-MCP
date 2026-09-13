<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * The real read, straight off the filesystem, bounded here rather than trusted
 * to have been bounded earlier.
 *
 * Discovery does check a file's size against
 * {@see DiscoveryConfig::$maxFileBytes} before asking for its bytes, but that
 * check and this read are two moments, and a manifest an attacker or a build
 * step grows in between was loaded whole by an unbounded file_get_contents():
 * the limit the caller enforced bounded nothing this actually did. The limit
 * therefore travels with the request and is applied to the read itself, so no
 * more than it can ever be resident.
 */
final readonly class FilesystemContentReader implements FileContentReader
{
    /**
     * Read no further than one byte past the limit, and report anything that
     * reaches that byte as oversized.
     *
     * One past, not exactly the limit: a read that stops at $maxBytes cannot
     * tell a file of exactly that size, which is allowed, from a larger one
     * truncated to it, which is not. The extra byte is what makes the two
     * distinguishable without holding the overage.
     *
     * Silenced rather than checked first: a file that vanishes between the
     * check and the read would pass the check anyway.
     */
    public function read(string $absolutePath, int $maxBytes): FileContent
    {
        $contents = @file_get_contents($absolutePath, false, null, 0, $maxBytes + 1);
        if (!is_string($contents)) {
            return FileContent::unreadable();
        }

        return strlen($contents) > $maxBytes ? FileContent::oversized() : FileContent::of($contents);
    }
}
