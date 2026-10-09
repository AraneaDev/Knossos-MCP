<?php

declare(strict_types=1);

namespace Knossos\Git;

use Knossos\Scanner\Protocol\RelativePath;
use RuntimeException;
use Throwable;

/**
 * Reads history by running `git`, under a deadline and with bounded output.
 *
 * Shells out rather than linking a Git library: the binary is already required, and
 * its output is a stable contract. The deadline matters because a huge or
 * pathological history would otherwise hang a query.
 *
 * Paths are relative to the project root, and only commits that touch the
 * project count, also for a project in a subdirectory of its repository: git
 * runs there (`-C`), `--relative` strips the directory and the `.` pathspec
 * keeps sibling packages' commits out of the max_commits budget. The log is
 * read NUL-separated ({@see GitLogRecords}), so a path git would quote keeps
 * its real name.
 */
final readonly class ProcessGitHistoryProvider implements GitHistoryProvider
{
    /** What every commit header in the log starts with; the fields follow it. */
    private const MARKER = "KNOSSOS_COMMIT\x1f";

    private GitProcessRunnerInterface $runner;

    public function __construct(int $maxOutputBytes = 2_000_000, int $maxErrorBytes = 65_536, ?GitProcessRunnerInterface $runner = null)
    {
        $this->runner = $runner ?? new GitProcessRunner($maxOutputBytes, $maxErrorBytes);
    }

    /** {@inheritDoc} */
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
            '--format=KNOSSOS_COMMIT%x1f%H%x1f%aI%x1f%at%x1f%ae', '-z', '--name-only', '--no-renames', '--relative', '--', '.',
        ], $timeoutMs, 'history');
        return $this->parse($output, $maxCommits);
    }

    /**
     * Parse `git log -z` output into commit records.
     *
     * Paths come back exactly as git wrote them, so `"`, a backslash and a
     * leading or trailing space survive. Each still has to pass
     * {@see RelativePath::assertValid()}, which refuses a backslash and
     * control characters such as tab and newline: such a path is skipped
     * whole, never split into fragments credited to other files. Discovery
     * skips those names too, so the graph never holds them.
     *
     * @return array{files: array<string, array{commit_count: int, authors: list<string>, last_changed_at: string}>, commits_examined: int, truncated: bool}
     */
    private function parse(string $output, int $maxCommits): array
    {
        $commits = [];
        foreach (GitLogRecords::parse($output, self::MARKER) as $record) {
            $fields = $record['fields'];
            if (count($fields) !== 4) {
                // A malformed header drops the paths that follow it: they
                // belong to the commit it failed to describe, never to the
                // commit before it.
                continue;
            }
            $paths = [];
            foreach ($record['paths'] as $path) {
                try {
                    RelativePath::assertValid($path, 'Git path');
                } catch (Throwable) {
                    continue;
                }
                $paths[$path] = true;
            }
            $commits[] = [
                'hash' => $fields[0],
                'changed_at' => $fields[1],
                // %aI carries the author's local UTC offset, so comparing
                // those strings orders 10:00+02:00 (08:00Z) above
                // 09:00+00:00 (09:00Z). Order on the epoch instead and
                // present the ISO string.
                'changed_epoch' => (int) $fields[2],
                'author' => $fields[3],
                'paths' => $paths,
            ];
        }
        $truncated = count($commits) > $maxCommits;
        $commits = array_slice($commits, 0, $maxCommits);
        $files = [];
        foreach ($commits as $commit) {
            foreach (array_keys($commit['paths']) as $path) {
                $files[$path] ??= ['commit_count' => 0, 'authors' => [], 'last_changed_at' => '', 'last_changed_epoch' => 0];
                ++$files[$path]['commit_count'];
                $files[$path]['authors'][$commit['author']] = true;
                if ($commit['changed_epoch'] > $files[$path]['last_changed_epoch']) {
                    $files[$path]['last_changed_epoch'] = $commit['changed_epoch'];
                    $files[$path]['last_changed_at'] = $commit['changed_at'];
                }
            }
        }
        foreach ($files as &$file) {
            $file['authors'] = array_keys($file['authors']);
            sort($file['authors'], SORT_STRING);
            // Internal ordering key; the public shape carries the ISO string only.
            unset($file['last_changed_epoch']);
        }
        unset($file);
        ksort($files, SORT_STRING);
        return ['files' => $files, 'commits_examined' => count($commits), 'truncated' => $truncated];
    }
}
