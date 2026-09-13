<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * The real read, straight off the filesystem.
 *
 * Bounded by the caller rather than here: discovery rejects a file over
 * {@see DiscoveryConfig::$maxFileBytes} before it asks for the bytes, so this
 * never holds more than that in memory, and the manifests it is asked for sit
 * far below it.
 */
final readonly class FilesystemContentReader implements FileContentReader
{
    /** Silenced rather than checked first: a file that vanishes between the check and the read would pass the check anyway. */
    public function read(string $absolutePath): ?string
    {
        $contents = @file_get_contents($absolutePath);

        return is_string($contents) ? $contents : null;
    }
}
