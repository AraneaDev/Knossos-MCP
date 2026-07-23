<?php

declare(strict_types=1);

namespace Knossos\Runtime;

use Knossos\Git\GitProcessRunner;
use Knossos\Scanner\Worker\ProcessScannerClient;
use PDO;
use Throwable;

final readonly class DoctorService
{
    public function __construct(private PDO $pdo, private string $installationRoot, private string $databasePath) {}

    /** @return array{ok: bool, checks: list<array{name: string, status: string, detail: string}>} */
    public function run(): array
    {
        $checks = [];
        $this->check($checks, 'php.version', static function (): string {
            if (preg_match('/^(\d+)\.(\d+)\./', PHP_VERSION, $matches) !== 1 || (int) $matches[1] !== 8 || (int) $matches[2] < 3 || (int) $matches[2] > 4) {
                throw new \RuntimeException('PHP 8.3 or 8.4 is required.');
            }
            return PHP_VERSION;
        });
        foreach (['json', 'pdo', 'pdo_sqlite'] as $extension) {
            $this->check($checks, 'php.extension.' . $extension, static fn(): string => extension_loaded($extension) ? 'loaded' : throw new \RuntimeException('missing'));
        }
        $this->check($checks, 'node.version', function (): string {
            $version = $this->command(['node', '--version']);
            if (!preg_match('/^v(\d+)\./', $version, $matches) || (int) $matches[1] < 22 || (int) $matches[1] > 24) {
                throw new \RuntimeException(sprintf('%s is unsupported; Node 22–24 is required.', $version));
            }
            return $version;
        });
        $this->check($checks, 'git.version', fn(): string => $this->command(['git', '--version']));
        $this->check($checks, 'python.version', function (): string {
            $version = $this->command(['python3', '--version']);
            if (!preg_match('/^Python 3\.(\d+)\./', $version, $matches) || (int) $matches[1] < 11 || (int) $matches[1] > 13) {
                throw new \RuntimeException(sprintf('%s is unsupported; Python 3.11–3.13 is required.', $version));
            }
            return $version;
        });
        $this->check($checks, 'sqlite.integrity', function (): string {
            $result = (string) $this->pdo->query('PRAGMA quick_check')->fetchColumn();
            if ($result !== 'ok') {
                throw new \RuntimeException($result);
            }
            return $result;
        });
        $this->check($checks, 'sqlite.foreign_keys', fn(): string => (string) $this->pdo->query('PRAGMA foreign_keys')->fetchColumn() === '1' ? 'enabled' : throw new \RuntimeException('disabled'));
        $this->check($checks, 'sqlite.migrations', function (): string {
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
            if ($count < 6) {
                throw new \RuntimeException(sprintf('Only %d migrations are applied.', $count));
            }
            return sprintf('%d applied', $count);
        });
        if ($this->databasePath !== ':memory:') {
            $this->check($checks, 'data.writable', fn(): string => is_writable(dirname($this->databasePath)) ? dirname($this->databasePath) : throw new \RuntimeException('Data directory is not writable.'));
        }
        $this->worker($checks, 'worker.php', [PHP_BINARY, '-d', 'memory_limit=512M', $this->installationRoot . '/workers/php/bin/worker'], 'knossos.php');
        $this->worker($checks, 'worker.typescript', ['node', '--max-old-space-size=512', $this->installationRoot . '/workers/typescript/bin/worker.js'], 'knossos.typescript');
        $this->worker($checks, 'worker.python', ['python3', '-I', '-B', $this->installationRoot . '/workers/python/bin/worker.py'], 'knossos.python');

        return ['ok' => count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'error')) === 0, 'checks' => $checks];
    }

    /** @param list<array{name: string, status: string, detail: string}> $checks */
    private function check(array &$checks, string $name, callable $operation): void
    {
        try {
            $checks[] = ['name' => $name, 'status' => 'ok', 'detail' => trim((string) $operation())];
        } catch (Throwable $error) {
            $checks[] = ['name' => $name, 'status' => 'error', 'detail' => $error->getMessage()];
        }
    }

    /** @param list<array{name: string, status: string, detail: string}> $checks @param non-empty-list<string> $command */
    private function worker(array &$checks, string $name, array $command, string $expectedId): void
    {
        $this->check($checks, $name, static function () use ($command, $expectedId): string {
            $client = new ProcessScannerClient($command);
            try {
                $manifest = $client->initialize();
                if ($manifest->id !== $expectedId) {
                    throw new \RuntimeException(sprintf('Unexpected worker ID: %s', $manifest->id));
                }
                return $manifest->id . '@' . $manifest->version . ' protocol ' . $manifest->protocolVersion;
            } finally {
                $client->shutdown();
            }
        });
    }

    /**
     * Run a short-lived version probe under a deadline with non-blocking,
     * bounded reads. Reuses {@see GitProcessRunner}'s select/deadline loop so a
     * chatty or hung probe (e.g. one that floods stderr while stdout stays open)
     * cannot deadlock `doctor` the way sequential blocking pipe reads would.
     *
     * @param non-empty-list<string> $command
     */
    private function command(array $command): string
    {
        return trim((new GitProcessRunner())->run($command, 5000, 'command probe'));
    }
}
