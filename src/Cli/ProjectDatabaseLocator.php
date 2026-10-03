<?php

declare(strict_types=1);

namespace Knossos\Cli;

/**
 * Decides which graph a path-addressed brief command reads.
 *
 * `--db` first, `KNOSSOS_DATA_DIR` second, then the nearest
 * `.knossos/knossos.sqlite` at or above the target. Shared by
 * `session-brief`, `turn-brief`, `rescan`, `dashboard` and `component-detail` so
 * they cannot answer one directory out of different databases.
 */
final readonly class ProjectDatabaseLocator
{
    /** Ancestors walked looking for an existing database, matching ProjectPathResolver's own bound. */
    private const MAX_ANCESTORS = 64;

    /**
     * The database path for $target; never creates anything.
     *
     * {@see \Knossos\Runtime\RuntimeFactory::defaultDatabasePath()} falls back
     * to `<cwd>/.knossos/knossos.sqlite`, which is right for every command a
     * person types and wrong for these: the hook supplies the project
     * directory as an argument while the process inherits whatever working
     * directory the session started in. When those differ, the brief answers a
     * question about one directory out of another directory's database, and
     * reports a fully scanned project as `NOT SCANNED`. Deriving from the
     * argument makes the two agree by construction. Scoped to the path-addressed
     * brief commands, because every other command addresses a project by id and
     * is meant to follow the working directory.
     *
     * Precedence is unchanged where it was ever explicit: `--db` first,
     * `KNOSSOS_DATA_DIR` second (a container installation depends on it, and
     * the runtime already knows how to join it), and only then the target
     * path.
     *
     * @param array<string, list<string>> $options
     */
    public function locate(string $target, array $options, CliCommandContext $context): string
    {
        $dataDirectory = getenv('KNOSSOS_DATA_DIR');
        if ($context->options->single($options, 'db') !== null
            || (is_string($dataDirectory) && $dataDirectory !== '')) {
            return $context->databasePath();
        }
        return $this->nearestDatabase($target);
    }

    /**
     * The `.knossos/knossos.sqlite` at the target, or the nearest one above it.
     *
     * The walk mirrors {@see \Knossos\Query\ProjectPathResolver}, which already
     * walks parents to resolve a subdirectory to its project: a session started
     * in `src/` of a scanned repository must reach the repository's own graph,
     * and a database path that stopped at the target would find nothing there
     * and report the project unscanned. Only `is_file()` is asked along the
     * way, so the walk itself writes nothing and creates nothing.
     *
     * Falls back to the target's own path when no ancestor has one. That path
     * does not exist either, which is the point: the caller renders `unscanned`
     * from it and still opens nothing.
     */
    private function nearestDatabase(string $target): string
    {
        $current = realpath($target) ?: $target;
        // Computed once and carried into the loop as its first candidate. The
        // loop used to recompute the same value from the same $current on its
        // first pass, which read as though the two could differ.
        $candidate = $this->databaseIn($current);
        $path = $candidate;
        for ($depth = 0; $depth < self::MAX_ANCESTORS; $depth++) {
            if (is_file($path)) {
                return $path;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
            $path = $this->databaseIn($current);
        }
        return $candidate;
    }

    /** The conventional data-directory path under one directory, without doubling a trailing slash at the filesystem root. */
    private function databaseIn(string $directory): string
    {
        return rtrim($directory, '/') . '/.knossos/knossos.sqlite';
    }
}
