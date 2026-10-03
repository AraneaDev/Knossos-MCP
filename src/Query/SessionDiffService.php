<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Git\GitHeadResolver;
use Knossos\Git\GitProcessRunner;
use Knossos\Git\GitProcessRunnerInterface;
use Throwable;

/**
 * What one file's change since a session began looks like: the unified diff
 * between the commit the session started at and the file on disk now, so it
 * covers the commits made during the session and the working tree alike.
 *
 * `head()` names that commit when the session starts; `diff()` reads one
 * file against it. An added file is shown whole as added (an untracked one
 * read from disk), a deleted one whole as removed, and a file git moved
 * (`git mv`) as the rename it is. Only the hunks are returned, cut to
 * {@see self::MAX_LINES} lines and {@see self::MAX_BYTES} bytes with
 * `truncated` set; a binary file says so instead, and a diff git would not
 * print within its bounds is `unreadable`. Every git call runs through
 * the hardened {@see GitProcessRunner}: the repository's own drivers and
 * hooks never run, and each call is bounded in time and output.
 */
final readonly class SessionDiffService
{
    /** Diff lines returned at most; past them `truncated` is set. */
    public const MAX_LINES = 2_000;

    /** Diff bytes returned at most; past them `truncated` is set. */
    public const MAX_BYTES = 200_000;

    /** Each git call's time limit: the wrapper bounds the whole command at 15 s. */
    private const TIMEOUT_MS = 4_000;

    /** Bytes of an untracked file read to show it as added: room for the lines kept, and a little more to tell a cut. */
    private const READ_BYTES = self::MAX_BYTES + 1;

    private GitProcessRunnerInterface $runner;

    /** Defaults to a real, hardened git runner; a test double replaces it. */
    public function __construct(?GitProcessRunnerInterface $runner = null)
    {
        $this->runner = $runner ?? new GitProcessRunner();
    }

    /**
     * The commit `$path` is at now: what a session records when it starts.
     *
     * @return array<string, mixed>
     */
    public function head(string $path): array
    {
        $absolute = realpath($path) ?: $path;
        $rev = (new GitHeadResolver($this->runner))->resolve($absolute);
        return $rev === null ? ['status' => 'no-git', 'path' => $absolute, 'rev' => null] : ['status' => 'ok', 'path' => $absolute, 'rev' => $rev];
    }

    /**
     * How `$file` (relative to `$path`) changed since commit `$rev`.
     *
     * @return array<string, mixed>
     */
    public function diff(string $path, string $rev, string $file): array
    {
        $dir = realpath($path) ?: $path;
        $envelope = ['path' => $dir, 'rev' => $rev, 'file' => $file, 'kind' => null, 'from' => null, 'to' => null, 'binary' => false, 'diff' => '', 'lines' => 0, 'truncated' => false, 'unreadable' => false];
        if (!self::isPlainPath($file)) {
            return ['status' => 'error'] + $envelope;
        }
        if (!is_dir($dir) || !$this->succeeds($dir, ['rev-parse', '--show-toplevel'])) {
            return ['status' => 'no-git'] + $envelope;
        }
        if (preg_match('/^[0-9a-f]{7,64}$/', $rev) !== 1 || !$this->succeeds($dir, ['cat-file', '-e', $rev . '^{commit}'])) {
            return ['status' => 'unknown-rev'] + $envelope;
        }
        $before = $this->succeeds($dir, ['cat-file', '-e', $rev . ':./' . $file]);
        $now = is_file($dir . '/' . $file);
        $moved = $before === $now ? null : $this->moved($dir, $rev, $file, $before);
        if ($moved !== null) {
            [$from, $to] = $moved;
            return ['status' => 'ok', 'kind' => 'renamed', 'from' => $from, 'to' => $to] + $this->hunks($dir, ['diff', '--no-ext-diff', '--no-textconv', '-M', $rev, '--', $from, $to]) + $envelope;
        }
        if (!$before && !$now) {
            return ['status' => 'ok', 'kind' => 'absent'] + $envelope;
        }
        $kind = $before ? ($now ? 'changed' : 'deleted') : 'added';
        if ($kind === 'added' && !$this->succeeds($dir, ['ls-files', '--error-unmatch', '--', $file])) {
            return ['status' => 'ok', 'kind' => 'added'] + self::untracked($dir . '/' . $file) + $envelope;
        }
        $hunks = $this->hunks($dir, ['diff', '--no-ext-diff', '--no-textconv', $rev, '--', $file]);
        return ['status' => 'ok', 'kind' => $kind === 'changed' && $hunks['diff'] === '' && !$hunks['binary'] ? 'unchanged' : $kind] + $hunks + $envelope;
    }

    /** Whether `$file` is a plain relative path inside the directory: no absolute path, option or step out. */
    private static function isPlainPath(string $file): bool
    {
        if ($file === '' || $file[0] === '/' || $file[0] === '-' || str_contains($file, "\0")) {
            return false;
        }
        return !in_array('..', explode('/', $file), true);
    }

    /**
     * Whether a git command succeeds in `$dir`.
     *
     * @param list<string> $args
     */
    private function succeeds(string $dir, array $args): bool
    {
        try {
            $this->git($dir, $args);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A git command's output in `$dir`; throws when it fails, times out or says too much.
     *
     * @param list<string> $args
     */
    private function git(string $dir, array $args): string
    {
        return $this->runner->run(['git', '-c', 'core.quotePath=false', '--no-optional-locks', '--no-pager', '-C', $dir, ...$args], self::TIMEOUT_MS, 'session diff');
    }

    /**
     * The other side of a rename git sees between `$rev` and the working
     * tree, as [from, to]: for a file there now and not then, where it came
     * from; for one there then and not now, where it went. Null when it was
     * not moved, or the tree's changes are too many to tell.
     *
     * @return array{0: string, 1: string}|null
     */
    private function moved(string $dir, string $rev, string $file, bool $wasThere): ?array
    {
        try {
            $fields = explode("\0", $this->git($dir, ['diff', '--no-ext-diff', '-M', '--name-status', '-z', '--relative', $rev]));
        } catch (Throwable) {
            return null;
        }
        for ($i = 0; $i < count($fields) - 2; ++$i) {
            if (!str_starts_with($fields[$i], 'R')) {
                continue;
            }
            [$from, $to] = [$fields[$i + 1], $fields[$i + 2]];
            if ($wasThere ? $from === $file : $to === $file) {
                return [$from, $to];
            }
            $i += 2;
        }
        return null;
    }

    /**
     * The hunks of a `git diff`, its headers left out, bounded.
     *
     * @param list<string> $args
     * @return array{diff: string, lines: int, truncated: bool, binary: bool, unreadable?: true}
     */
    private function hunks(string $dir, array $args): array
    {
        try {
            $out = $this->git($dir, $args);
        } catch (Throwable) {
            // More than git may print here, or git failed: said, with nothing shown.
            return ['diff' => '', 'lines' => 0, 'truncated' => false, 'binary' => false, 'unreadable' => true];
        }
        $start = str_starts_with($out, '@@') ? 0 : strpos($out, "\n@@");
        if ($start === false) {
            return ['diff' => '', 'lines' => 0, 'truncated' => false, 'binary' => preg_match('/^Binary files .* differ$/m', $out) === 1];
        }
        return self::bounded(substr($out, $start === 0 ? 0 : $start + 1)) + ['binary' => false];
    }

    /**
     * An untracked file shown whole as added: one hunk of every line.
     *
     * @return array{diff: string, lines: int, truncated: bool, binary: bool}
     */
    private static function untracked(string $file): array
    {
        $text = @file_get_contents($file, false, null, 0, self::READ_BYTES);
        if (!is_string($text) || $text === '') {
            return ['diff' => '', 'lines' => 0, 'truncated' => false, 'binary' => false];
        }
        if (str_contains(substr($text, 0, 8_000), "\0")) {
            return ['diff' => '', 'lines' => 0, 'truncated' => false, 'binary' => true];
        }
        $lines = explode("\n", rtrim($text, "\n"));
        $hunk = sprintf("@@ -0,0 +1,%d @@\n", count($lines)) . implode("\n", array_map(static fn(string $l): string => '+' . $l, $lines)) . "\n";
        $bounded = self::bounded($hunk);
        return ['truncated' => $bounded['truncated'] || strlen($text) >= self::READ_BYTES] + $bounded + ['binary' => false];
    }

    /**
     * `$diff` cut to {@see self::MAX_LINES} lines and {@see self::MAX_BYTES}
     * bytes at a line's end, with how many lines it had.
     *
     * @return array{diff: string, lines: int, truncated: bool}
     */
    private static function bounded(string $diff): array
    {
        $lines = explode("\n", rtrim($diff, "\n"));
        $kept = [];
        $bytes = 0;
        foreach ($lines as $line) {
            $bytes += strlen($line) + 1;
            if (count($kept) >= self::MAX_LINES || $bytes > self::MAX_BYTES) {
                break;
            }
            $kept[] = $line;
        }
        return ['diff' => $kept === [] ? '' : implode("\n", $kept) . "\n", 'lines' => count($lines), 'truncated' => count($kept) < count($lines)];
    }
}
