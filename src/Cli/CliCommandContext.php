<?php

declare(strict_types=1);

namespace Knossos\Cli;

use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scan\CancellationToken;
use PDO;

/**
 * Shared, lazily-built dependencies for one CLI invocation.
 *
 * The database and maintenance service are created on first use so commands that
 * need neither — `version`, `help` — never open the graph, and so a command that
 * uses both shares one connection rather than opening two.
 */
final class CliCommandContext
{
    private ?PDO $pdo = null;
    private ?DatabaseMaintenanceService $maintenance = null;

    public function __construct(
        public readonly CliOptionParser $options,
        public readonly CliInputLoader $input,
        private readonly RuntimeFactory $runtime,
        private readonly ?string $databasePath,
    ) {}

    /** The graph connection, opened and migrated on first call. */
    public function database(): PDO
    {
        return $this->pdo ??= $this->runtime->database($this->databasePath);
    }

    /** Maintenance operations bound to this invocation's database path. */
    public function maintenance(): DatabaseMaintenanceService
    {
        return $this->maintenance ??= new DatabaseMaintenanceService(
            $this->database(),
            $this->databasePath ?? $this->runtime->defaultDatabasePath(),
        );
    }

    /** Where Knossos itself is installed, which is where the packaged scanner workers live. */
    public function installationRoot(): string
    {
        return $this->runtime->installationRoot();
    }

    /** The effective database path: `--db` when given, otherwise the runtime default. */
    public function databasePath(): string
    {
        return $this->databasePath ?? $this->runtime->defaultDatabasePath();
    }

    /**
     * Whether `--db` named the database, rather than it being derived.
     *
     * Asked by commands whose output makes a claim about where a file lives.
     * A derived path is wherever the shell happened to be, which is a fact
     * worth disclosing; a named one is what the caller asked for and needs no
     * qualification.
     */
    public function databasePathWasGiven(): bool
    {
        return $this->databasePath !== null;
    }

    /**
     * A token wired to interrupt signals, so Ctrl-C stops a scan cleanly.
     *
     * Degrades to an un-signalled token where pcntl is unavailable rather than
     * refusing to run.
     *
     * @param bool $handleTermination also trap SIGTERM, for long-running commands a
     *        supervisor may stop
     */
    public function cancellationToken(bool $handleTermination = false): CancellationToken
    {
        $cancellation = new CancellationToken();
        if (function_exists('pcntl_async_signals') && defined('SIGINT')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static fn() => $cancellation->cancel());
            if ($handleTermination && defined('SIGTERM')) {
                pcntl_signal(SIGTERM, static fn() => $cancellation->cancel());
            }
        }
        return $cancellation;
    }

    /**
     * Print a result as JSON or text, per the --json flag.
     *
     * @param array<string, mixed> $structured
     */
    public function output(array $structured, bool $json, string $text): void
    {
        echo ($json ? json_encode($structured, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : $text) . PHP_EOL;
    }
}
