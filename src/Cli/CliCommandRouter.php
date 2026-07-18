<?php

declare(strict_types=1);

namespace Knossos\Cli;

use InvalidArgumentException;
use Knossos\Cli\Command\BundleCommand;
use Knossos\Cli\Command\MaintenanceCommand;
use Knossos\Cli\Command\MetaCommand;
use Knossos\Cli\Command\QueryCommand;
use Knossos\Cli\Command\ScanCommand;
use Knossos\Cli\Command\ServeCommand;
use Knossos\Cli\Command\WatchCommand;
use Knossos\Runtime\RuntimeFactory;

final class CliCommandRouter
{
    /** @var list<CliCommand> */
    private array $commands;

    public function __construct(
        private readonly string $installationRoot,
        private readonly CliOptionParser $options,
        CliHelpRenderer $help,
        string $version,
    ) {
        $this->commands = [
            new MetaCommand($help, $version),
            new ScanCommand(),
            new WatchCommand(),
            new BundleCommand(),
            new QueryCommand(),
            new MaintenanceCommand(),
            new ServeCommand(),
        ];
    }

    /** @param list<string> $positionals @param array<string, list<string>> $options */
    public function route(string $command, array $positionals, array $options): int
    {
        $meta = in_array($command, ['version', '--version', 'help', '--help', '-h'], true);
        $context = new CliCommandContext(
            $this->options,
            new CliInputLoader(),
            new RuntimeFactory($this->installationRoot),
            $meta ? null : $this->options->single($options, 'db'),
        );
        if (!$meta) {
            $context->database();
        }
        foreach ($this->commands as $handler) {
            if ($handler->supports($command)) {
                return $handler->run($command, $positionals, $options, $context);
            }
        }
        throw new InvalidArgumentException(sprintf('Unknown command: %s', $command));
    }
}
