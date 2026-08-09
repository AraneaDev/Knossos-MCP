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
    fwrite($pipes[0], implode("\0", $paths) . "\0");
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]);
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
