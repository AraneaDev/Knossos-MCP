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
        /**
         * The Git blob id of the bytes discovery read, or null when they could
         * not be pinned to one. It is what lets a scan decide, against the
         * commit it captured, whether the content it stored a hash for was the
         * committed content — a question no later call to git can answer about
         * this moment. See {@see \Knossos\Git\DirtyPathResolver}.
         */
        public ?string $gitBlobHash = null,
    ) {}
}
