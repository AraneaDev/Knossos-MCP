<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Scan\ProjectScanService;

final class ScanCommand implements CliCommand
{
    public function supports(string $command): bool
    {
        return $command === 'scan';
    }

    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $root = $positionals[0] ?? throw new InvalidArgumentException('Usage: knossos scan <path> [--name=NAME] [--db=PATH] [--json]');
        $result = (new ProjectScanService($context->database(), $context->installationRoot(), [$root]))->scan(
            $root,
            $context->options->single($options, 'name'),
            isset($options['max-files']) ? $context->options->integer($options, 'max-files', 100_000, 1, 100_000) : null,
            isset($options['max-file-bytes']) ? $context->options->integer($options, 'max-file-bytes', 2_000_000, 1, 100_000_000) : null,
            isset($options['boundary']) ? $context->options->boundaries($options['boundary']) : null,
            $context->options->single($options, 'mode'),
            $context->cancellationToken(),
            isset($options['snapshot-retention']) ? $context->options->integer($options, 'snapshot-retention', 5, 0, 20) : null,
            isset($options['worker-timeout-ms']) ? $context->options->integer($options, 'worker-timeout-ms', 30_000, 1_000, 120_000) : null,
        );
        $context->output($result->jsonSerialize(), isset($options['json']), $result->summary . "\nProject: " . $result->projectId . "\nSnapshot: " . $result->snapshotId);
        return 0;
    }
}
