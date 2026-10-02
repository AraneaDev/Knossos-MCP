<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\ProjectDatabaseLocator;
use Knossos\Query\ComponentDetailService;
use Knossos\Query\DashboardService;
use Knossos\Query\TurnBriefService;
use Knossos\Runtime\RuntimeFactory;
use Throwable;

/**
 * `turn-brief`, `dashboard` and `component-detail`: the reads the Claude Code
 * mod makes.
 *
 * Addressed by path like `session-brief`, through the same database
 * resolution. All always exit 0: the caller is a hook that must never
 * break a session, so failure is a status in the JSON, not an exit code.
 * None creates a database; only `turn-brief` writes, by scanning a
 * project that already exists in an allowed root.
 */
final class BriefCommand implements CliCommand
{
    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return in_array($command, ['turn-brief', 'dashboard', 'component-detail'], true);
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return match ($command) {
            'turn-brief' => ['db', 'json', 'files', 'policies', 'no-policies'],
            'component-detail' => ['db', 'json'],
            default => ['db', 'json', 'fan-in-threshold'],
        };
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
            [$path, $name] = $this->target($command, $positionals);
            $databasePath = (new ProjectDatabaseLocator())->locate($path, $options, $context);
            $result = is_file($databasePath)
                ? $this->answer($command, $path, $name, $databasePath, $options, $context)
                : ['status' => 'unscanned', 'path' => realpath($path) ?: $path];
            $context->output($result, $json, (string) $result['status']);
        } catch (Throwable) {
            if ($json) {
                $context->output(['status' => 'error'], true, '');
            }
        }
        return 0;
    }

    /**
     * The path a command reads and, for `component-detail`, the component name:
     * `component-detail [path] <name>`, so a single positional is the name.
     *
     * @param list<string> $positionals
     * @return array{0: string, 1: string}
     */
    private function target(string $command, array $positionals): array
    {
        if ($command !== 'component-detail') {
            return [(string) ($positionals[0] ?? getcwd()), ''];
        }
        $name = $positionals[count($positionals) - 1] ?? throw new InvalidArgumentException('A component name is required.');
        return [count($positionals) > 1 ? $positionals[0] : (string) getcwd(), $name];
    }

    /**
     * Opens the existing database (migrating it) and runs the requested read.
     *
     * Only reached after the `is_file` guard, so the migration never creates a
     * database; it only brings a stale schema up to date before a scan writes.
     *
     * @param array<string, list<string>> $options
     * @return array<string, mixed>
     */
    private function answer(string $command, string $path, string $name, string $databasePath, array $options, CliCommandContext $context): array
    {
        $runtime = new RuntimeFactory($context->installationRoot());
        $pdo = $runtime->database($databasePath);
        if ($command === 'component-detail') {
            return (new ComponentDetailService($pdo))->detail($path, $name);
        }
        if ($command === 'dashboard') {
            $threshold = $context->options->integer($options, 'fan-in-threshold', 20, 1, 100_000);
            return (new DashboardService($pdo))->dashboard($path, $threshold);
        }
        $policiesFile = $context->options->single($options, 'policies');
        return (new TurnBriefService($pdo, $databasePath, $context->installationRoot()))->brief(
            $path,
            $options['files'] ?? [],
            $policiesFile === null ? null : $context->input->policies($policiesFile),
            !$context->options->flag($options, 'no-policies'),
        );
    }
}
