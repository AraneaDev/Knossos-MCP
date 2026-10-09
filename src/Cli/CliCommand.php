<?php

declare(strict_types=1);

namespace Knossos\Cli;

/**
 * Contract for one CLI command.
 *
 * Declaring accepted options separately from running is what lets the router
 * reject an unknown flag before the command executes, rather than silently
 * ignoring it.
 */
interface CliCommand
{
    /** The command did what it was asked and its answer is complete. */
    public const EXIT_OK = 0;

    /** A check or gate ran to the end and failed (quality-gate, check-architecture, doctor, maintain-database). */
    public const EXIT_GATE_FAILED = 1;

    /** The command could not run: bad arguments, a missing project, a storage or runtime failure. */
    public const EXIT_ERROR = 2;

    /** The command completed, but its list is incomplete: a bound or time limit cut it (dead-code, diagnostics). */
    public const EXIT_INCOMPLETE = 3;

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
