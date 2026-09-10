<?php

declare(strict_types=1);

namespace Knossos\Query;

use PDO;

/**
 * Resolves a filesystem path to the project whose root contains it.
 *
 * Path-addressed rather than id-addressed because the caller that matters is a
 * session-start hook, which knows a working directory and nothing else. The
 * parent walk exists because a session can start in any subdirectory of a
 * repository, and an exact-match-only lookup would report those as unscanned.
 */
final readonly class ProjectPathResolver
{
    /** Guards against a pathological path burning the hook's time budget on ancestors. */
    private const MAX_ANCESTORS = 64;

    public function __construct(private PDO $pdo) {}

    /**
     * A path as given when it is already absolute, resolved against the working
     * directory when it is not.
     *
     * Lexical, not filesystem-backed: this runs precisely when the path does
     * not exist, so there is nothing to canonicalise.
     */
    private static function absolute(string $path): string
    {
        if ($path === '' || $path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }
        $cwd = getcwd();

        return $cwd === false ? $path : rtrim($cwd, '/') . '/' . $path;
    }

    /**
     * The project owning this path, or null when no scanned project contains it.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $path): ?array
    {
        // Normalise when the path exists, fall back to it as given when it does not.
        // Bailing out on a false realpath would report an existing-but-unreadable path
        // as unscanned, and would fail against stored roots that are not present on
        // this filesystem.
        // Falling back to the path AS GIVEN keeps an existing-but-unreadable
        // path resolvable, and matches stored roots absent from this
        // filesystem. A relative fallback has to be made absolute all the
        // same: the ancestor walk below would otherwise climb to "." and stop,
        // never meeting a project root, which is always absolute.
        $current = realpath($path) ?: self::absolute($path);
        $statement = $this->pdo->prepare(
            'SELECT id, name, root_realpath, active_scan_id FROM projects WHERE root_realpath = :root',
        );
        for ($depth = 0; $depth < self::MAX_ANCESTORS; $depth++) {
            $statement->execute(['root' => $current]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return $row;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }
            $current = $parent;
        }
        return null;
    }
}
