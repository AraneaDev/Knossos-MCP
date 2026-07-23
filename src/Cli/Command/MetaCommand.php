<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliHelpRenderer;

final readonly class MetaCommand implements CliCommand
{
    public function __construct(private CliHelpRenderer $help, private string $version) {}

    public function supports(string $command): bool
    {
        return in_array($command, ['version', '--version', 'help', '--help', '-h'], true);
    }

    public function allowedOptions(string $command): array
    {
        return ['json'];
    }

    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        if (in_array($command, ['help', '--help', '-h'], true)) {
            $this->help->render();
            return 0;
        }
        $context->output(['name' => 'knossos', 'version' => $this->version], $context->options->flag($options, 'json'), sprintf('Knossos %s', $this->version));
        return 0;
    }
}
