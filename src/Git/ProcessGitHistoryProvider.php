<?php

declare(strict_types=1);

namespace Knossos\Git;

use Knossos\Scanner\Protocol\RelativePath;
use RuntimeException;
use Throwable;

final readonly class ProcessGitHistoryProvider implements GitHistoryProvider
{
    private GitProcessRunnerInterface $runner;

    public function __construct(int $maxOutputBytes = 2_000_000, int $maxErrorBytes = 65_536, ?GitProcessRunnerInterface $runner = null)
    {
        $this->runner = $runner ?? new GitProcessRunner($maxOutputBytes, $maxErrorBytes);
    }

    public function history(string $projectRoot, int $sinceDays, int $maxCommits, int $timeoutMs): array
    {
        if ($sinceDays < 1 || $sinceDays > 3650) {
            throw new RuntimeException('since_days must be between 1 and 3650.');
        }
        if ($maxCommits < 1 || $maxCommits > 5000) {
            throw new RuntimeException('max_commits must be between 1 and 5000.');
        }
        if ($timeoutMs < 1 || $timeoutMs > 5000) {
            throw new RuntimeException('timeout_ms must be between 1 and 5000.');
        }
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Git project root is not a readable directory.');
        }
        $output = $this->runner->run([
            'git', '-c', 'core.quotePath=false', '--no-optional-locks', '--no-pager', '-C', $root, 'log',
            '--since=' . $sinceDays . ' days ago', '--max-count=' . ($maxCommits + 1),
            '--format=KNOSSOS_COMMIT%x1f%H%x1f%aI%x1f%ae', '--name-only', '--no-renames', '--',
        ], $timeoutMs, 'history');
        return $this->parse($output, $maxCommits);
    }

    /** @return array{files: array<string, array{commit_count: int, authors: list<string>, last_changed_at: string}>, commits_examined: int, truncated: bool} */
    private function parse(string $output, int $maxCommits): array
    {
        $commits = [];
        $current = null;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (str_starts_with($line, 'KNOSSOS_COMMIT' . "\x1f")) {
                $parts = explode("\x1f", $line, 4);
                if (count($parts) !== 4) {
                    continue;
                }
                $commits[] = ['hash' => $parts[1], 'changed_at' => $parts[2], 'author' => $parts[3], 'paths' => []];
                $current = array_key_last($commits);
                continue;
            }
            $path = trim($line);
            if ($current === null || $path === '') {
                continue;
            }
            try {
                RelativePath::assertValid($path, 'Git path');
            } catch (Throwable) {
                continue;
            }
            $commits[$current]['paths'][$path] = true;
        }
        $truncated = count($commits) > $maxCommits;
        $commits = array_slice($commits, 0, $maxCommits);
        $files = [];
        foreach ($commits as $commit) {
            foreach (array_keys($commit['paths']) as $path) {
                $files[$path] ??= ['commit_count' => 0, 'authors' => [], 'last_changed_at' => ''];
                ++$files[$path]['commit_count'];
                $files[$path]['authors'][$commit['author']] = true;
                if ($commit['changed_at'] > $files[$path]['last_changed_at']) {
                    $files[$path]['last_changed_at'] = $commit['changed_at'];
                }
            }
        }
        foreach ($files as &$file) {
            $file['authors'] = array_keys($file['authors']);
            sort($file['authors'], SORT_STRING);
        }
        unset($file);
        ksort($files, SORT_STRING);
        return ['files' => $files, 'commits_examined' => count($commits), 'truncated' => $truncated];
    }
}
