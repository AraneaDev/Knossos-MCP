<?php

declare(strict_types=1);

namespace Knossos;

use Knossos\Cli\CliCommandRouter;
use Knossos\Cli\CliErrorRenderer;
use Knossos\Cli\CliHelpRenderer;
use Knossos\Cli\CliOptionParser;
use Throwable;

/**
 * CLI entry point: parses argv, routes to a command, renders errors.
 *
 * Every failure leaves through here as a stable diagnostic code and a non-zero
 * exit, because the CLI is consumed by CI as well as by people.
 */
final class Application
{
    public const VERSION = '0.10.2'; // x-release-please-version

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

    /**
     * Parse argv, route to a command, and turn any failure into a diagnostic code and exit status.
     *
     * @param list<string> $arguments
     */
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
