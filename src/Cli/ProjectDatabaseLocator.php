<?php

declare(strict_types=1);

namespace Knossos\Cli;

use Knossos\Runtime\RuntimeFactory;
use Knossos\Store\SqliteConnection;
use Throwable;

/**
 * Decides which graph a path-addressed brief command reads.
 *
 * `--db` first, `KNOSSOS_DATA_DIR` second, the installation's graph in
 * `~/.knossos` third when it exists, then the nearest
 * `.knossos/knossos.sqlite` at or above the target. Shared by
 * `session-brief`, `turn-brief`, `rescan`, `dashboard`, `component-detail` and
 * `file-detail` so they cannot answer one directory out of different databases.
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
     * the runtime already knows how to join it), then the installation's
     * graph in `~/.knossos` (where `tools/install` points the server and the
     * hooks) when it holds the target's project or nothing nearer exists,
     * and otherwise the graph nearest the target. A project scanned into its
     * own `.knossos` is never hidden behind a home graph that never saw it.
     *
     * `$target` is a path, or a project id: an id (a bare word not on disk)
     * has no place there, so the walk for it starts at the working directory.
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
        // A bare word that is not on disk is an id; anything with a slash is a path, there or not.
        $isId = !str_contains($target, '/') && !file_exists($target);
        $nearest = $this->nearestDatabase($isId ? (getcwd() ?: $target) : $target);
        $home = RuntimeFactory::homeDatabasePath();
        if ($home !== null && (!is_file($nearest) || self::holds($home, $target))) {
            return $home;
        }

        return $nearest;
    }

    /**
     * Whether the graph at `$database` knows the project `$target` names.
     * Read only, and false for a file that is no graph at all.
     */
    private static function holds(string $database, string $target): bool
    {
        try {
            return (new ProjectReference(SqliteConnection::open($database), $database))->find($target) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The `.knossos/knossos.sqlite` at the target, or the nearest one above it.
     *
     * The walk mirrors {@see \Knossos\Scan\ProjectPathResolver}, which already
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
