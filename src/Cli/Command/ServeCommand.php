<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Discovery\AllowedRoots;
use Knossos\Mcp\McpServerAssembly;

final class ServeCommand implements CliCommand
{
    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'serve';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['db', 'allow-root'];
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $allowedRoots = self::resolveRoots($options, $context->databasePath());

        return (new McpServerAssembly(
            $context->database(),
            $context->installationRoot(),
            $context->databasePath(),
            $allowedRoots,
            $context->maintenance(),
        ))->stdioServer()->run(STDIN, STDOUT, STDERR);
    }

    /**
     * Flags, environment, and the roots file, unioned.
     *
     * The file is the portable source: it is re-read per request, so a project
     * added to it becomes scannable without re-registering this server.
     *
     * @param array<string, list<string>> $options
     */
    private static function resolveRoots(array $options, string $databasePath): AllowedRoots
    {
        $staticRoots = $options['allow-root'] ?? [];
        if ($staticRoots === []) {
            $configured = getenv('KNOSSOS_ALLOWED_ROOTS');
            if (is_string($configured) && $configured !== '') {
                $staticRoots = array_values(array_filter(explode(PATH_SEPARATOR, $configured)));
            }
        }
        $rootsFile = AllowedRoots::defaultConfigPath($databasePath);
        $allowedRoots = new AllowedRoots(array_values($staticRoots), $rootsFile);
        if ($allowedRoots->current() === []) {
            throw new InvalidArgumentException(sprintf(
                'serve needs at least one allowed root. Pass --allow-root=PATH, set KNOSSOS_ALLOWED_ROOTS, or create %s containing {"roots": ["/absolute/path"]}.',
                $rootsFile,
            ));
        }

        return $allowedRoots;
    }
}
