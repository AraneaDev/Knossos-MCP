<?php

declare(strict_types=1);

namespace Knossos\Cli;

use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scan\CancellationToken;
use PDO;

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

    public function database(): PDO
    {
        return $this->pdo ??= $this->runtime->database($this->databasePath);
    }

    public function maintenance(): DatabaseMaintenanceService
    {
        return $this->maintenance ??= new DatabaseMaintenanceService(
            $this->database(),
            $this->databasePath ?? $this->runtime->defaultDatabasePath(),
        );
    }

    public function installationRoot(): string
    {
        return $this->runtime->installationRoot();
    }

    public function databasePath(): string
    {
        return $this->databasePath ?? $this->runtime->defaultDatabasePath();
    }

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

    /** @param array<string, mixed> $structured */
    public function output(array $structured, bool $json, string $text): void
    {
        echo ($json ? json_encode($structured, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : $text) . PHP_EOL;
    }
}
