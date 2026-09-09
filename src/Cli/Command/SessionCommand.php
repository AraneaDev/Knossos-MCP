<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\SessionBriefRenderer;
use Knossos\Query\SessionBriefService;
use Knossos\Store\SqliteConnection;
use Throwable;

/**
 * The session-start orientation brief, as a CLI command.
 *
 * Its own class rather than another arm of QueryCommand for two reasons that
 * both cut against sharing: it is addressed by filesystem path instead of by
 * project id, and it must never throw. Every other query command signals a bad
 * invocation by throwing, which is right for a person at a terminal and wrong
 * for a hook that runs before a session starts.
 *
 * It is also the one command that must not open the database the way every
 * other one does. {@see CliCommandContext::database()} creates the data
 * directory and runs every migration, which is exactly right for a command
 * that is about to write and exactly wrong for the session-start path: a
 * `session-brief` in a directory nobody ever scanned used to leave a migrated
 * SQLite file behind, from a hook nobody asked to run. So this command locates
 * the database itself, checks that it is already there, and opens it only
 * then.
 */
final class SessionCommand implements CliCommand
{
    /** Ancestors walked looking for an existing database, matching ProjectPathResolver's own bound. */
    private const MAX_ANCESTORS = 64;

    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'session-brief';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['db', 'json'];
    }

    /**
     * {@inheritDoc}
     *
     * Always exit 0, always silent on failure. A broken brief that breaks a
     * session start is worse than no brief at all, so every error path here
     * degrades to producing nothing.
     */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        try {
            $path = (string) ($positionals[0] ?? getcwd());
            $databasePath = $this->databasePath($path, $options, $context);
            // The existence check is the whole read-only guarantee. Opening a
            // path that is not there creates it; migrating it writes to it.
            // Neither may happen on a path that runs before a session starts,
            // so an absent database is answered from nothing at all.
            $brief = is_file($databasePath)
                ? (new ArchitectureQueryService(SqliteConnection::open($databasePath)))->sessionBrief($path, $databasePath)
                : (new SessionBriefRenderer())->render(SessionBriefService::unscanned($path, $databasePath));
            $context->output(['brief' => $brief], $context->options->flag($options, 'json'), $brief);
        } catch (Throwable) {
            return 0;
        }
        return 0;
    }

    /**
     * Where this invocation's graph lives, derived from the target path rather
     * than from the process's working directory.
     *
     * {@see \Knossos\Runtime\RuntimeFactory::defaultDatabasePath()} falls back
     * to `<cwd>/.knossos/knossos.sqlite`, which is right for every command a
     * person types and wrong for this one: the hook supplies the project
     * directory as an argument while the process inherits whatever working
     * directory the session started in. When those differ, the brief answers a
     * question about one directory out of another directory's database, and
     * reports a fully scanned project as `NOT SCANNED`. Deriving from the
     * argument makes the two agree by construction. Scoped to this command,
     * because every other command addresses a project by id and is meant to
     * follow the working directory.
     *
     * Precedence is unchanged where it was ever explicit: `--db` first,
     * `KNOSSOS_DATA_DIR` second (a container installation depends on it, and
     * the runtime already knows how to join it), and only then the target
     * path.
     *
     * @param array<string, list<string>> $options
     */
    private function databasePath(string $target, array $options, CliCommandContext $context): string
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
        $candidate = $this->databaseIn($current);
        for ($depth = 0; $depth < self::MAX_ANCESTORS; $depth++) {
            $path = $this->databaseIn($current);
            if (is_file($path)) {
                return $path;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }
        return $candidate;
    }

    /** The conventional data-directory path under one directory, without doubling a trailing slash at the filesystem root. */
    private function databaseIn(string $directory): string
    {
        return rtrim($directory, '/') . '/.knossos/knossos.sqlite';
    }
}
