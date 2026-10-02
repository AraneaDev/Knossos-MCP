<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\ProjectDatabaseLocator;
use Knossos\Query\DashboardService;
use Knossos\Query\TurnBriefService;
use Knossos\Runtime\RuntimeFactory;
use Throwable;

/**
 * `turn-brief` and `dashboard`: the two reads the Claude Code mod makes.
 *
 * Addressed by path like `session-brief`, through the same database
 * resolution. Both always exit 0: the caller is a hook that must never
 * break a session, so failure is a status in the JSON, not an exit code.
 * Neither creates a database; only `turn-brief` writes, by scanning a
 * project that already exists in an allowed root.
 */
final class BriefCommand implements CliCommand
{
    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'turn-brief' || $command === 'dashboard';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return $command === 'turn-brief'
            ? ['db', 'json', 'files', 'policies', 'no-policies']
            : ['db', 'json', 'fan-in-threshold'];
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
            $path = (string) ($positionals[0] ?? getcwd());
            $databasePath = (new ProjectDatabaseLocator())->locate($path, $options, $context);
            $result = is_file($databasePath)
                ? $this->answer($command, $path, $databasePath, $options, $context)
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
     * Opens the existing database (migrating it) and runs the requested read.
     *
     * Only reached after the `is_file` guard, so the migration never creates a
     * database; it only brings a stale schema up to date before a scan writes.
     *
     * @param array<string, list<string>> $options
     * @return array<string, mixed>
     */
    private function answer(string $command, string $path, string $databasePath, array $options, CliCommandContext $context): array
    {
        $runtime = new RuntimeFactory($context->installationRoot());
        $pdo = $runtime->database($databasePath);
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
