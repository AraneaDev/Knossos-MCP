<?php

declare(strict_types=1);

namespace Knossos\Git;

use Knossos\Scanner\Protocol\RelativePath;
use RuntimeException;
use Throwable;

/**
 * Reads the working-tree diff by running `git`, under the same deadline and output caps.
 *
 * Every path is relative to the project root and inside it, also for a project
 * in a subdirectory of its repository: git runs there (`-C`), `--relative`
 * strips the directory from what it prints and the `.` pathspec drops sibling
 * packages. A rename from outside the project into it reads as an add, because
 * `--relative` filters the other side of the pair out.
 *
 * On a branch with no commit yet `HEAD` names nothing, so the index is
 * compared with nothing (every staged file is an add) and the worktree with
 * the index, instead of failing.
 */
final readonly class ProcessGitWorkingTreeProvider implements GitWorkingTreeProvider
{
    private GitProcessRunnerInterface $runner;

    public function __construct(int $maxOutputBytes = 2_000_000, int $maxErrorBytes = 65_536, ?GitProcessRunnerInterface $runner = null)
    {
        $this->runner = $runner ?? new GitProcessRunner($maxOutputBytes, $maxErrorBytes);
    }

    /** {@inheritDoc} */
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
        $git = ['git', '--no-optional-locks', '--no-pager', '-C', $root];
        $diff = [...$git, 'diff', '--name-status', '-z', '--no-ext-diff', '--find-renames', '--relative'];
        $paths = [];
        $renames = [];
        if ($baseRef !== null) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/@{}~^:+-]{0,199}$/D', $baseRef) !== 1) {
                throw new RuntimeException('base_ref contains unsupported characters.');
            }
            $revision = trim($this->runner->run([...$git, 'rev-parse', '--verify', $baseRef . '^{commit}'], $timeoutMs, 'working-tree query'));
            if (preg_match('/^[a-f0-9]{40,64}$/D', $revision) !== 1) {
                throw new RuntimeException('base_ref did not resolve to a commit.');
            }
            $this->collect($this->runner->run([...$diff, $revision, '--', '.'], $timeoutMs, 'working-tree query'), $paths, $renames);
        } elseif ($this->born($git, $timeoutMs)) {
            $this->collect($this->runner->run([...$diff, 'HEAD', '--', '.'], $timeoutMs, 'working-tree query'), $paths, $renames);
        } else {
            $this->collect($this->runner->run([...$diff, '--cached', '--', '.'], $timeoutMs, 'working-tree query'), $paths, $renames);
            $this->collect($this->runner->run([...$diff, '--', '.'], $timeoutMs, 'working-tree query'), $paths, $renames);
        }
        if ($baseRef === null) {
            foreach (explode("\0", $this->runner->run([
                ...$git, 'ls-files', '--others', '--exclude-standard', '-z', '--', '.',
            ], $timeoutMs, 'working-tree query')) as $path) {
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

    /**
     * Whether the checked-out branch has a commit: false on a branch with no
     * commit yet, where `rev-parse --verify -q HEAD` fails or prints nothing.
     *
     * @param non-empty-list<string> $git the git prefix that runs in the project root
     */
    private function born(array $git, int $timeoutMs): bool
    {
        try {
            return trim($this->runner->run([...$git, 'rev-parse', '--verify', '-q', 'HEAD'], $timeoutMs, 'working-tree query')) !== '';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Adds the valid paths of one `diff --name-status -z` output to `$paths`,
     * and each rename whose two sides are both valid to `$renames`.
     *
     * @param array<string, true> $paths
     * @param list<array{from: string, to: string}> $renames
     */
    private function collect(string $output, array &$paths, array &$renames): void
    {
        // The NUL that ends the last entry leaves one empty token, which fails validation like any empty path.
        $tokens = explode("\0", $output);
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
    }
}
