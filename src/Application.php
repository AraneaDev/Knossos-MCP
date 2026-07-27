<?php

declare(strict_types=1);

namespace Knossos;

use Knossos\Cli\CliCommandRouter;
use Knossos\Cli\CliErrorRenderer;
use Knossos\Cli\CliHelpRenderer;
use Knossos\Cli\CliOptionParser;
use Throwable;

final class Application
{
    public const VERSION = '0.7.0'; // x-release-please-version

    /** @var resource|null */
    private $errorStream;

    /**
     * @param resource|null $errorStream Destination for the failure diagnostic;
     *                                   null uses the process STDERR. Tests pass
     *                                   an in-memory stream so the suite never
     *                                   writes to the real STDERR — see
     *                                   CliErrorRenderer for why that matters to
     *                                   mutation testing.
     */
    public function __construct($errorStream = null)
    {
        $this->errorStream = $errorStream;
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $command = array_shift($arguments) ?? 'help';
        $parser = new CliOptionParser();
        try {
            [$positionals, $options] = $parser->parse($arguments);
            return (new CliCommandRouter(dirname(__DIR__), $parser, new CliHelpRenderer(), self::VERSION))
                ->route($command, $positionals, $options);
        } catch (Throwable $error) {
            return (new CliErrorRenderer($this->errorStream))->render($error);
        }
    }
}
