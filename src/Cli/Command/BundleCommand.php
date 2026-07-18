<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Bundle\GraphBundleService;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;

final class BundleCommand implements CliCommand
{
    public function supports(string $command): bool
    {
        return in_array($command, ['export-bundle', 'import-bundle'], true);
    }

    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        return $command === 'export-bundle'
            ? $this->export($positionals, $options, $context)
            : $this->import($positionals, $options, $context);
    }

    /** @param list<string> $positionals @param array<string, list<string>> $options */
    private function export(array $positionals, array $options, CliCommandContext $context): int
    {
        $projectId = $positionals[0] ?? throw new InvalidArgumentException('Usage: knossos export-bundle <project-id> --output=FILE');
        $output = $context->options->single($options, 'output') ?? throw new InvalidArgumentException('--output=FILE is required.');
        if (file_exists($output)) {
            throw new InvalidArgumentException('Bundle output already exists: ' . $output);
        }
        $bundle = (new GraphBundleService($context->database()))->export(
            $projectId,
            $context->options->single($options, 'redaction') ?? 'none',
        );
        $handle = fopen($output, 'xb');
        if (!is_resource($handle)) {
            throw new InvalidArgumentException('Unable to create bundle output: ' . $output);
        }
        $complete = false;
        try {
            if (fwrite($handle, $bundle) !== strlen($bundle)) {
                throw new InvalidArgumentException('Unable to write complete bundle output.');
            }
            $complete = true;
        } finally {
            fclose($handle);
            if (!$complete) {
                @unlink($output);
            }
        }
        $context->output(['project_id' => $projectId, 'output' => $output, 'bytes' => strlen($bundle)], isset($options['json']), sprintf('Exported %d-byte graph bundle.', strlen($bundle)));
        return 0;
    }

    /** @param list<string> $positionals @param array<string, list<string>> $options */
    private function import(array $positionals, array $options, CliCommandContext $context): int
    {
        $input = $positionals[0] ?? throw new InvalidArgumentException('Usage: knossos import-bundle <file> [--name=NAME]');
        $result = (new GraphBundleService($context->database()))->import(
            $context->input->bundle($input),
            $context->options->single($options, 'name'),
        );
        $context->output($result->jsonSerialize(), isset($options['json']), $result->summary . "\nProject: " . $result->projectId);
        return 0;
    }
}
