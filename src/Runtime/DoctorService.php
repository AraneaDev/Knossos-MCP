<?php

declare(strict_types=1);

namespace Knossos\Runtime;

use Knossos\Git\GitProcessRunner;
use Knossos\Scan\LanguageDescriptor;
use Knossos\Scanner\Worker\ProcessScannerClient;
use PDO;
use Throwable;

/**
 * Checks that this installation can actually scan.
 *
 * Verifies the runtimes, required extensions, database integrity and migrations,
 * data-directory writability, and starts each scanner worker to confirm it
 * answers on the expected protocol. Every check is reported rather than thrown,
 * so one failure does not hide the others — the usual reason to run this is that
 * something already went wrong and the cause is not obvious.
 */
final readonly class DoctorService
{
    /**
     * The lowest version of each runtime this release supports.
     *
     * Each is a floor with no ceiling — see {@see RuntimeVersionRequirement} for
     * why a newer major is accepted rather than reported as unsupported.
     */
    private const FLOORS = [
        'php' => ['PHP', '/^(\d+\.\d+)\./', '8.3'],
        'node' => ['Node', '/^v(\d+)\./', '22'],
        'python' => ['Python', '/^Python (\d+\.\d+)\./', '3.11'],
    ];

    public function __construct(private PDO $pdo, private string $installationRoot, private string $databasePath) {}

    /** The version floor for one runtime, by the key it is registered under in {@see self::FLOORS}. */
    private static function requirement(string $runtime): RuntimeVersionRequirement
    {
        [$name, $pattern, $minimum] = self::FLOORS[$runtime];

        return new RuntimeVersionRequirement($name, $pattern, $minimum);
    }

    /**
     * Run every check, reporting each rather than stopping at the first failure.
     *
     * @return array{ok: bool, checks: list<array{name: string, status: string, detail: string}>}
     */
    public function run(): array
    {
        $checks = [];
        $this->check($checks, 'php.version', static fn(): string => self::requirement('php')->verify(PHP_VERSION));
        foreach (['json', 'pdo', 'pdo_sqlite'] as $extension) {
            $this->check($checks, 'php.extension.' . $extension, static fn(): string => extension_loaded($extension) ? 'loaded' : throw new \RuntimeException('missing'));
        }
        $this->check($checks, 'node.version', fn(): string => self::requirement('node')->verify($this->command(['node', '--version'], hardenGitConfig: false)));
        $this->check($checks, 'git.version', fn(): string => $this->command(['git', '--version']));
        $this->check($checks, 'python.version', fn(): string => self::requirement('python')->verify($this->command(['python3', '--version'], hardenGitConfig: false)));
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
        // Sourced from LanguageDescriptor rather than repeated here, so scan and
        // doctor cannot disagree about a worker's command or its availability.
        foreach (LanguageDescriptor::defaults($this->installationRoot) as $descriptor) {
            $this->worker($checks, $descriptor);
        }

        return ['ok' => count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'error')) === 0, 'checks' => $checks];
    }

    /**
     * Record one check's outcome, converting a throw into a reported error.
     *
     * @param list<array{name: string, status: string, detail: string}> $checks
     */
    private function check(array &$checks, string $name, callable $operation): void
    {
        try {
            $checks[] = ['name' => $name, 'status' => 'ok', 'detail' => trim((string) $operation())];
        } catch (Throwable $error) {
            $checks[] = ['name' => $name, 'status' => 'error', 'detail' => $error->getMessage()];
        }
    }

    /**
     * Start one language worker and confirm its identity and protocol version.
     *
     * An optional worker that is not installed reports `skipped`, not `error`:
     * a language whose toolchain the operator never installed is a capability
     * they do not have, not a fault in the installation they do. Reporting it
     * as `skipped` rather than omitting the check entirely keeps that
     * capability visible in `doctor` output, so an operator can tell "not
     * installed" apart from "not a thing". `run()` counts only `error` checks
     * when deciding overall health, and the CLI renderer prints whatever
     * status it is given, so neither needed a change for the new status value.
     *
     * The command and expected id both come from the descriptor, so this
     * cannot drift from what a scan actually launches. The id convention is
     * `knossos.<key>`, which every packaged worker follows.
     *
     * @param list<array{name: string, status: string, detail: string}> $checks
     */
    private function worker(array &$checks, LanguageDescriptor $descriptor): void
    {
        $name = 'worker.' . $descriptor->key;
        if (!$descriptor->isInstalled()) {
            $checks[] = ['name' => $name, 'status' => 'skipped', 'detail' => 'not installed: ' . $descriptor->command[0]];
            return;
        }
        $command = $descriptor->command;
        $expectedId = 'knossos.' . $descriptor->key;
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
     * `$hardenGitConfig` must be false for any binary other than `git` itself:
     * the forced `-c key=value` overrides are Git syntax that other binaries
     * (`node`, `python3`) do not understand.
     *
     * @param non-empty-list<string> $command
     */
    private function command(array $command, bool $hardenGitConfig = true): string
    {
        return trim((new GitProcessRunner(hardenGitConfig: $hardenGitConfig))->run($command, 5000, 'command probe'));
    }
}
