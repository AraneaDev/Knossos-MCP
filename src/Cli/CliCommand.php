<?php

declare(strict_types=1);

namespace Knossos\Cli;

interface CliCommand
{
    /** Reports whether this handler owns the requested CLI command name. */
    public function supports(string $command): bool;

    /**
     * Returns the option names this handler accepts for the given command, so
     * the router can reject typos and unknown flags before dispatching.
     *
     * @return list<string>
     */
    public function allowedOptions(string $command): array;

    /**
     * Executes a supported CLI command using parsed positional arguments and options.
     *
     * @param list<string> $positionals
     * @param array<string, list<string>> $options
     */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int;
}
