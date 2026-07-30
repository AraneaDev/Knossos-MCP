<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/** One file discovery selected, with the fingerprint incremental reuse is keyed on. */
final readonly class DiscoveredFile
{
    public function __construct(
        public string $relativePath,
        public string $absolutePath,
        public string $language,
        public int $size,
        public int $mtime,
        public string $contentHash,
        public int $lineCount = 0,
    ) {}
}
