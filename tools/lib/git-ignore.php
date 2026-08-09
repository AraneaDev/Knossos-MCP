<?php

declare(strict_types=1);

/**
 * Shared git-ignore filtering for the repository gates.
 *
 * The gates under tools/ walk the filesystem, which is not the same set of files
 * as "the repository". On a fresh CI checkout the difference is invisible —
 * ignored files simply are not there — so a gate that conflates the two passes in
 * CI and fails on a developer's machine, naming a file no reviewer can open. Both
 * tools/documentation-check.php and tools/repository-check.php hit this, so the
 * answer to "does the repository carry this file" lives in one place.
 *
 * Untracked-but-not-ignored files are deliberately still checked: a file added in
 * the working tree and not yet committed is on its way in, and skipping it would
 * let the very thing these gates exist to catch land.
 */

/**
 * Which of `$paths` git ignores, as a set keyed by path.
 *
 * Returns an EMPTY set when git cannot answer — no binary, no repository (an
 * archive download, or the quality container, which carries the source without a
 * .git directory), or a non-zero exit that is not the documented "nothing
 * matched". Failing open keeps the check running everywhere it ran before:
 * losing the filter costs a false failure on a local scratch file, while failing
 * closed would skip every file and silently pass.
 *
 * `git check-ignore` exits 0 when at least one path matched, 1 when none did,
 * and >1 on a real error — so 1 is success with an empty answer, not a fault.
 *
 * Paths are passed and returned verbatim over a NUL-delimited stream, so a path
 * containing a newline or a quote round-trips intact and the caller can match on
 * the same string it sent.
 *
 * @param list<string> $paths
 * @return array<string, true>
 */
function gitIgnoredPaths(string $root, array $paths): array
{
    if ($paths === []) {
        return [];
    }
    $process = @proc_open(
        ['git', '-C', $root, 'check-ignore', '--stdin', '-z'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        return [];
    }
    $output = gitIgnoreExchange($pipes[0], $pipes[1], implode("\0", $paths) . "\0");
    fclose($pipes[1]);
    $exit = proc_close($process);
    if ($exit > 1) {
        return [];
    }
    $ignored = [];
    foreach (explode("\0", $output) as $path) {
        if ($path !== '') {
            $ignored[str_replace('\\', '/', $path)] = true;
        }
    }

    return $ignored;
}

/**
 * Feed `$input` to a child's stdin while draining its stdout, and return what it
 * wrote.
 *
 * Writing the whole request first and only then reading the reply deadlocks: a
 * pipe holds 64 KiB on Linux, so once the request exceeds that AND the reply
 * does too, the parent blocks in fwrite() waiting for the child to read, while
 * the child blocks writing a reply nobody is reading. Neither side can move and
 * there is no timeout — the gate hangs until CI kills the job.
 *
 * That is not hypothetical for this caller. `git check-ignore` echoes back every
 * path that matched, so a repository with enough ignored paths makes the reply as
 * large as the request: 6,000 paths (252 KB) hangs forever, and the walk in
 * tools/repository-check.php already feeds it every file in the tree.
 *
 * Both ends are non-blocking and selected on together, so whichever side has room
 * moves first. Writing stops on a hard error rather than spinning — a child that
 * exited early breaks the pipe, and the caller reads its exit status to decide
 * what that means.
 *
 * @param resource $stdin
 * @param resource $stdout
 */
function gitIgnoreExchange(mixed $stdin, mixed $stdout, string $input): string
{
    stream_set_blocking($stdin, false);
    stream_set_blocking($stdout, false);
    $output = '';
    $sent = 0;
    $sending = true;
    while (true) {
        $read = [$stdout];
        $write = $sending ? [$stdin] : [];
        $except = null;
        if (@stream_select($read, $write, $except, null) === false) {
            break;
        }
        if ($write !== []) {
            $bytes = @fwrite($stdin, substr($input, $sent, 65536));
            // false is a broken pipe; 0 is a full one, which select will report
            // again once it drains. Only the first ends the conversation.
            if ($bytes === false) {
                $sending = false;
                fclose($stdin);
            } else {
                $sent += $bytes;
                if ($sent >= strlen($input)) {
                    $sending = false;
                    fclose($stdin);
                }
            }
        }
        if ($read !== []) {
            $chunk = fread($stdout, 65536);
            if ($chunk === false || ($chunk === '' && feof($stdout))) {
                break;
            }
            $output .= $chunk;
        }
    }
    if ($sending) {
        fclose($stdin);
    }

    return $output;
}
