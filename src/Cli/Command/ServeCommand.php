<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Mcp\StdioServer;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;

final class ServeCommand implements CliCommand
{
    public function supports(string $command): bool
    {
        return $command === 'serve';
    }

    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $allowedRoots = $options['allow-root'] ?? [];
        if ($allowedRoots === []) {
            $configured = getenv('KNOSSOS_ALLOWED_ROOTS');
            if (is_string($configured) && $configured !== '') {
                $allowedRoots = array_values(array_filter(explode(PATH_SEPARATOR, $configured)));
            }
        }
        if ($allowedRoots === []) {
            throw new InvalidArgumentException('serve requires at least one --allow-root=PATH or KNOSSOS_ALLOWED_ROOTS.');
        }
        $enricher = new \Knossos\Mcp\ResultEnricher(
            new \Knossos\Query\StalenessProbe($context->database()),
            new \Knossos\Mcp\NextStepPlanner(),
        );
        $tools = new ToolService(
            new ProjectScanService($context->database(), $context->installationRoot(), $allowedRoots),
            new ArchitectureQueryService(
                $context->database(),
                gitHistory: new \Knossos\Git\ProcessGitHistoryProvider(),
                gitWorkingTree: new \Knossos\Git\ProcessGitWorkingTreeProvider(),
            ),
            $context->maintenance(),
            $enricher,
        );
        return (new StdioServer($tools))->run(STDIN, STDOUT, STDERR);
    }
}
