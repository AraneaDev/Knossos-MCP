<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Query\ArchitectureQueryService;
use Throwable;

/**
 * The session-start orientation brief, as a CLI command.
 *
 * Its own class rather than another arm of QueryCommand for two reasons that
 * both cut against sharing: it is addressed by filesystem path instead of by
 * project id, and it must never throw. Every other query command signals a bad
 * invocation by throwing, which is right for a person at a terminal and wrong
 * for a hook that runs before a session starts.
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
            $path = $positionals[0] ?? getcwd();
            $brief = (new ArchitectureQueryService($context->database()))->sessionBrief((string) $path);
            $context->output(['brief' => $brief], $context->options->flag($options, 'json'), $brief);
        } catch (Throwable) {
            return 0;
        }
        return 0;
    }
}
