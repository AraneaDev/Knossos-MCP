<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\ProjectDatabaseLocator;
use Knossos\Cli\ProjectReference;
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
            $databasePath = (new ProjectDatabaseLocator())->locate($path, $options, $context);
            if (is_file($databasePath) && !file_exists($path)) {
                // A project id in place of the path: brief that project's root.
                $path = (new ProjectReference(SqliteConnection::open($databasePath), $databasePath))->byId($path)['root'] ?? $path;
            }
            // The existence check is the whole read-only guarantee. Opening a
            // path that is not there creates it; migrating it writes to it.
            // Neither may happen on a path that runs before a session starts,
            // so an absent database is answered from nothing at all.
            $brief = is_file($databasePath)
                ? (new ArchitectureQueryService(SqliteConnection::open($databasePath)))->sessionBrief($path, $databasePath)
                : (new SessionBriefRenderer())->render(SessionBriefService::unscanned($path, $databasePath));
            $context->output(['brief' => $brief], $context->options->flag($options, 'json'), $brief);
        } catch (Throwable) {
            // Swallowed on purpose: whatever happens, the session still starts.
        }
        return CliCommand::EXIT_OK;
    }
}
