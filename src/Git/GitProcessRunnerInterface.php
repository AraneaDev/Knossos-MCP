<?php

declare(strict_types=1);

namespace Knossos\Git;

/** Read-only, bounded Git subprocess execution. */
interface GitProcessRunnerInterface
{
    /**
     * Run a bounded, timeout-controlled Git command.
     *
     * @param non-empty-list<string> $command
     * @return string Process stdout on success.
     */
    public function run(array $command, int $timeoutMs, string $operation): string;
}
