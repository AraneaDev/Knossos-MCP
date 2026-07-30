<?php

declare(strict_types=1);

namespace Knossos\Git;

/** Contract for reading uncommitted changes, so review can run against a dirty tree. */
interface GitWorkingTreeProvider
{
    /**
     * Return bounded changed paths and explicit renames without modifying Git.
     *
     * @return array{paths: list<string>, renames: list<array{from: string, to: string}>, truncated: bool}
     */
    public function changes(string $projectRoot, ?string $baseRef, int $maxFiles, int $timeoutMs): array;
}
