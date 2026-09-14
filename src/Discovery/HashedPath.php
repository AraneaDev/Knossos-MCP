<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * One path discovery hashed, whether as a source file, a project unit, or a
 * manifest that did not parse: what a worker's read of it, and the post-worker
 * snapshot check, are compared against.
 */
final readonly class HashedPath
{
    public function __construct(
        public string $relativePath,
        public string $contentHash,
    ) {}
}
