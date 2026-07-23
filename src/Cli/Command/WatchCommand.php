<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Scan\ProjectScanService;
use Knossos\Watch\WatchService;

final class WatchCommand implements CliCommand
{
    public function supports(string $command): bool
    {
        return $command === 'watch';
    }

    public function allowedOptions(string $command): array
    {
        return ['db', 'json', 'poll-ms', 'debounce-ms', 'max-queue'];
    }

    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $root = $positionals[0] ?? throw new InvalidArgumentException('Usage: knossos watch <path> [options]');
        $scanner = new ProjectScanService($context->database(), $context->installationRoot(), [$root]);
        $observer = static function (array $event): void {
            fwrite(STDERR, json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        };
        $result = (new WatchService($scanner, [$root]))->run(
            $root,
            $context->options->integer($options, 'poll-ms', 500, 50, 60_000),
            $context->options->integer($options, 'debounce-ms', 300, 0, 60_000),
            $context->options->integer($options, 'max-queue', 1000, 1, 10_000),
            $context->cancellationToken(true),
            $observer,
        );
        $context->output($result->jsonSerialize(), $context->options->flag($options, 'json'), $result->summary);
        return 0;
    }
}
