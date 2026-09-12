<?php

declare(strict_types=1);

namespace Knossos\Query\Drift;

/** Whether a path beside the graph is one the scanner would have put in it. */
interface TrackedPathPredicate
{
    /**
     * Whether discovery would have tracked this path.
     *
     * Answering costs a stat, and for an extensionless file a read of its
     * first line, so it is the expensive half of examining a directory entry
     * and is what the walk's per-directory budget is spent on.
     */
    public function tracks(string $relativePath, string $absolutePath): bool;
}
