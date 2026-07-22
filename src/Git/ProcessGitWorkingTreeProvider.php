<?php

declare(strict_types=1);

namespace Knossos\Git;

use Knossos\Scanner\Protocol\RelativePath;
use RuntimeException;
use Throwable;

final readonly class ProcessGitWorkingTreeProvider implements GitWorkingTreeProvider
{
    private GitProcessRunnerInterface $runner;

    public function __construct(int $maxOutputBytes = 2_000_000, int $maxErrorBytes = 65_536, ?GitProcessRunnerInterface $runner = null)
    {
        $this->runner = $runner ?? new GitProcessRunner($maxOutputBytes, $maxErrorBytes);
    }

    public function changes(string $projectRoot, ?string $baseRef, int $maxFiles, int $timeoutMs): array
    {
        if ($maxFiles < 1 || $maxFiles > 1000) {
            throw new RuntimeException('max_files must be between 1 and 1000.');
        }
        if ($timeoutMs < 1 || $timeoutMs > 5000) {
            throw new RuntimeException('timeout_ms must be between 1 and 5000.');
        }
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Git project root is not a readable directory.');
        }
        $revision = 'HEAD';
        if ($baseRef !== null) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/@{}~^:+-]{0,199}$/', $baseRef) !== 1) {
                throw new RuntimeException('base_ref contains unsupported characters.');
            }
            $revision = trim($this->runner->run(['git', '--no-optional-locks', '--no-pager', '-C', $root, 'rev-parse', '--verify', $baseRef . '^{commit}'], $timeoutMs, 'working-tree query'));
            if (preg_match('/^[a-f0-9]{40,64}$/', $revision) !== 1) {
                throw new RuntimeException('base_ref did not resolve to a commit.');
            }
        }
        $output = $this->runner->run([
            'git', '--no-optional-locks', '--no-pager', '-C', $root, 'diff', '--name-status', '-z',
            '--no-ext-diff', '--find-renames', $revision, '--',
        ], $timeoutMs, 'working-tree query');
        $tokens = explode("\0", rtrim($output, "\0"));
        $paths = [];
        $renames = [];
        for ($index = 0; $index < count($tokens);) {
            $status = $tokens[$index++] ?? '';
            $from = $tokens[$index++] ?? '';
            $to = str_starts_with($status, 'R') || str_starts_with($status, 'C') ? ($tokens[$index++] ?? '') : null;
            foreach ($to === null ? [$from] : [$from, $to] as $path) {
                try {
                    RelativePath::assertValid($path, 'Git changed path');
                    $paths[$path] = true;
                } catch (Throwable) {
                }
            }
            if ($to !== null && isset($paths[$from], $paths[$to])) {
                $renames[] = ['from' => $from, 'to' => $to];
            }
        }
        if ($baseRef === null) {
            foreach (explode("\0", rtrim($this->runner->run([
                'git', '--no-optional-locks', '--no-pager', '-C', $root, 'ls-files', '--others', '--exclude-standard', '-z', '--',
            ], $timeoutMs, 'working-tree query'), "\0")) as $path) {
                if ($path === '') {
                    continue;
                }
                try {
                    RelativePath::assertValid($path, 'Git untracked path');
                    $paths[$path] = true;
                } catch (Throwable) {
                }
            }
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);
        $truncated = count($paths) > $maxFiles;
        return ['paths' => array_slice($paths, 0, $maxFiles), 'renames' => $renames, 'truncated' => $truncated];
    }

}
