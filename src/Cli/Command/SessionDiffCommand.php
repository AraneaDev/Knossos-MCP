<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliOptionParser;
use Knossos\Query\SessionDiffService;
use Throwable;

/**
 * `session-head` and `session-diff`: the Claude Code mod's view of how a file
 * changed since its session began. `session-head [path]` names the commit
 * the session starts at; `session-diff [path] --rev=SHA --file=PATH` reads
 * one file's change since it ({@see SessionDiffService}).
 *
 * Neither reads or creates a database: they ask git alone. Both always exit
 * 0, checking their own options rather than leaving them to the router, as
 * the mod's other commands do: failure is a status in the JSON.
 */
final class SessionDiffCommand implements CliCommand
{
    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'session-head' || $command === 'session-diff';
    }

    /**
     * {@inheritDoc}
     *
     * Any: the router's rejection would exit non-zero, so run() checks them itself.
     */
    public function allowedOptions(string $command): array
    {
        return [CliOptionParser::ANY];
    }

    /**
     * {@inheritDoc}
     *
     * Always exit 0. With `--json` a failure prints `{"status":"error"}`;
     * without it nothing is printed.
     */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $json = $context->options->flag($options, 'json');
        try {
            $context->options->validate($options, $command === 'session-head' ? ['json'] : ['json', 'rev', 'file']);
            if (count($positionals) > 1) {
                throw new InvalidArgumentException('Too many arguments.');
            }
            $path = (string) ($positionals[0] ?? getcwd());
            $service = new SessionDiffService();
            $result = $command === 'session-head'
                ? $service->head($path)
                : $service->diff(
                    $path,
                    $context->options->single($options, 'rev') ?? throw new InvalidArgumentException('--rev is required.'),
                    $context->options->single($options, 'file') ?? throw new InvalidArgumentException('--file is required.'),
                );
            $context->output($result, $json, (string) $result['status']);
        } catch (Throwable) {
            if ($json) {
                $context->output(['status' => 'error'], true, '');
            }
        }
        return 0;
    }
}
