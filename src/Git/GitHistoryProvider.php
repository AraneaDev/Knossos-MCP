<?php

declare(strict_types=1);

namespace Knossos\Git;

/**
 * Contract for reading commit history.
 *
 * Abstracted so change-impact still answers on a tree that is not a Git checkout,
 * and so tests need no fixture repository.
 */
interface GitHistoryProvider
{
    /**
     * Return bounded, read-only change history for project-relative files.
     *
     * @return array{files: array<string, array{commit_count: int, authors: list<string>, last_changed_at: string}>, commits_examined: int, truncated: bool}
     */
    public function history(string $projectRoot, int $sinceDays, int $maxCommits, int $timeoutMs): array;
}
