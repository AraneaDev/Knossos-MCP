<?php

declare(strict_types=1);

namespace Knossos\Git;

/**
 * Reads `git log -z --name-only` output, whose paths git writes byte for byte.
 *
 * Without `-z` git C-quotes a path holding `"`, `\`, a tab or a newline, and
 * a line-based reader cannot tell a newline inside a path from the one that
 * ends it. With `-z` every header and every path is NUL-terminated, so the
 * output splits on NUL alone and nothing needs unquoting or trimming.
 */
final class GitLogRecords
{
    /**
     * Splits `git log -z --format=<MARKER><fields> --name-only` output into
     * commits: each a header (the fields after the marker, split on \x1f) and
     * its paths, exactly as git wrote them.
     *
     * With -z git ends the header with NUL, then writes a newline, then each
     * path NUL-terminated; the next header follows the last path's NUL. So the
     * first path after a header carries one leading "\n" that is not part of it.
     *
     * @param non-empty-string $marker what every header starts with; the fields follow it
     * @return list<array{fields: list<string>, paths: list<string>}>
     */
    public static function parse(string $output, string $marker): array
    {
        $records = [];
        $current = null;
        $first = false;
        foreach (explode("\0", $output) as $token) {
            if (str_starts_with($token, $marker)) {
                $records[] = ['fields' => explode("\x1f", substr($token, strlen($marker))), 'paths' => []];
                $current = array_key_last($records);
                $first = true;
                continue;
            }
            if ($first && str_starts_with($token, "\n")) {
                $token = substr($token, 1);
            }
            $first = false;
            if ($current === null || $token === '') {
                continue;
            }
            $records[$current]['paths'][] = $token;
        }

        return $records;
    }
}
