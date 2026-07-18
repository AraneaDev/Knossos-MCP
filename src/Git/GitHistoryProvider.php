<?php

declare(strict_types=1);

namespace Knossos\Git;

interface GitHistoryProvider
{
    /**
     * Return bounded, read-only change history for project-relative files.
     *
     * @return array{files: array<string, array{commit_count: int, authors: list<string>, last_changed_at: string}>, commits_examined: int, truncated: bool}
     */
    public function history(string $projectRoot, int $sinceDays, int $maxCommits, int $timeoutMs): array;
}
