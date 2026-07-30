<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Runtime\DoctorService;

/** Database upkeep and the destructive removals, each previewing unless `--execute` is given. */
final class MaintenanceCommand implements CliCommand
{
    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return in_array($command, ['doctor', 'remove-project', 'cleanup-stale-scans', 'maintain-database'], true);
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return match ($command) {
            'doctor' => ['db', 'json'],
            'remove-project' => ['db', 'json', 'execute'],
            'cleanup-stale-scans' => ['db', 'json', 'older-than-hours', 'execute'],
            default => ['db', 'json', 'execute', 'backup-name'],
        };
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        return match ($command) {
            'doctor' => $this->doctor($options, $context),
            'remove-project' => $this->removeProject($positionals, $options, $context),
            'cleanup-stale-scans' => $this->cleanup($positionals, $options, $context),
            default => $this->maintain($positionals, $options, $context),
        };
    }

    /**
     * Run the runtime health checks and render them.
     *
     * @param array<string, list<string>> $options
     */
    private function doctor(array $options, CliCommandContext $context): int
    {
        $report = (new DoctorService($context->database(), $context->installationRoot(), $context->databasePath()))->run();
        $text = ($report['ok'] ? 'Knossos doctor: healthy' : 'Knossos doctor: problems found') . "\n";
        foreach ($report['checks'] as $check) {
            $text .= sprintf("[%s] %s: %s\n", strtoupper($check['status']), $check['name'], $check['detail']);
        }
        $context->output($report, $context->options->flag($options, 'json'), rtrim($text));
        return $report['ok'] ? 0 : 1;
    }

    /**
     * Delete a project and its graph, previewing unless --execute.
     *
     * @param list<string> $positionals @param array<string, list<string>> $options
     */
    private function removeProject(array $positionals, array $options, CliCommandContext $context): int
    {
        $projectId = $positionals[0] ?? throw new InvalidArgumentException('Usage: knossos remove-project <project-id> [--execute] [--json]');
        $result = $context->maintenance()->removeProject($projectId, $context->options->flag($options, 'execute'));
        $context->output($result->jsonSerialize(), $context->options->flag($options, 'json'), $result->summary);
        return 0;
    }

    /**
     * Drop stale scan records, previewing unless --execute.
     *
     * @param list<string> $positionals @param array<string, list<string>> $options
     */
    private function cleanup(array $positionals, array $options, CliCommandContext $context): int
    {
        $projectId = $positionals[0] ?? throw new InvalidArgumentException('Usage: knossos cleanup-stale-scans <project-id> [--older-than-hours=N] [--execute]');
        $result = $context->maintenance()->cleanupStaleScans(
            $projectId,
            $context->options->integer($options, 'older-than-hours', 24, 1, 8760),
            $context->options->flag($options, 'execute'),
        );
        $context->output($result->jsonSerialize(), $context->options->flag($options, 'json'), $result->summary);
        return 0;
    }

    /**
     * Run one database maintenance action.
     *
     * @param list<string> $positionals @param array<string, list<string>> $options
     */
    private function maintain(array $positionals, array $options, CliCommandContext $context): int
    {
        $action = $positionals[0] ?? throw new InvalidArgumentException('Usage: knossos maintain-database <integrity|checkpoint|optimize|backup> [options]');
        $result = $context->maintenance()->maintain(
            $action,
            $context->options->flag($options, 'execute'),
            $context->options->single($options, 'backup-name'),
        );
        $context->output($result->jsonSerialize(), $context->options->flag($options, 'json'), $result->summary);
        return ($result->data['ok'] ?? true) ? 0 : 1;
    }
}
