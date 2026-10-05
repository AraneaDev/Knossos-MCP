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
    /**
     * {@inheritDoc}
     *
     * Always exit 0. With `--json` a failure prints `{"status":"error"}`;
     * without it nothing is printed.
     */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $json = $context->options->flag($options, 'json');
        $result = $this->answer($command, $positionals, $options, $context);
        if ($json || $result['status'] !== 'error') {
            $context->output($result, $json, (string) $result['status']);
        }
        return 0;
    }

    /**
     * The command's answer, or `{"status":"error"}` for anything it refuses or that fails.
     *
     * @param list<string> $positionals
     * @param array<string, list<string>> $options
     * @return array<string, mixed>
     */
    private function answer(string $command, array $positionals, array $options, CliCommandContext $context): array
    {
        try {
            $context->options->validate($options, $command === 'session-head' ? ['json'] : ['json', 'rev', 'file']);
            if (count($positionals) > 1) {
                throw new InvalidArgumentException('Too many arguments.');
            }
            $path = (string) ($positionals[0] ?? getcwd());
            $service = new SessionDiffService();
            if ($command === 'session-head') {
                return $service->head($path);
            }
            $rev = $context->options->single($options, 'rev') ?? throw new InvalidArgumentException('--rev is required.');
            $file = $context->options->single($options, 'file') ?? throw new InvalidArgumentException('--file is required.');
            return $service->diff($path, $rev, $file);
        } catch (Throwable) {
            return ['status' => 'error'];
        }
    }

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
}
