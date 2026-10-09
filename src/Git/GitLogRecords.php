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
     * Splits `git log -z --format=%x00<MARKER><fields> --name-only` output
     * into commits: each a header (the fields after the marker, split on
     * \x1f) and its paths, exactly as git wrote them.
     *
     * The format starts with a NUL, so every header follows an empty token;
     * a path is never empty and never holds a NUL, so a file named like a
     * header is still a path and cannot forge a commit. With -z git ends the
     * header with NUL, then writes a newline, then each path NUL-terminated;
     * so the first path after a header carries one leading "\n" that is not
     * part of it.
     *
     * @param non-empty-string $marker what every header starts with, after its NUL; the fields follow it
     * @return list<array{fields: list<string>, paths: list<string>}>
     */
    public static function parse(string $output, string $marker): array
    {
        $records = [];
        $current = null;
        $first = false;
        $previous = null;
        foreach (explode("\0", $output) as $token) {
            $afterNul = $previous === '';
            $previous = $token;
            if ($afterNul && str_starts_with($token, $marker)) {
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
