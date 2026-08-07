<?php

declare(strict_types=1);

namespace Knossos\Git;

use RuntimeException;
use Throwable;

/**
 * Runs bounded, timeout-controlled read-only Git subprocesses.
 *
 * Git is the one subprocess family that reads state the scanned repository
 * controls: `.git/config` can name commands Git executes while refreshing the
 * index (`core.fsmonitor`), generating a diff (`diff.external`), or paging
 * (`core.pager`). Both `diff` and `ls-files --others` refresh the index, so
 * every invocation forces those settings off and runs under an explicit,
 * minimal environment — the same treatment WorkerProcessSupervisor gives the
 * scanner workers, for the same reason.
 */
final readonly class GitProcessRunner implements GitProcessRunnerInterface
{
    /**
     * Config overrides prepended to every command, as `-c key=value` pairs.
     *
     * Public so tests can assert the set is complete without reconstructing it,
     * and so a future call site cannot quietly build a command that skips them.
     *
     * @var list<string>
     */
    public const FORCED_CONFIG = [
        'core.fsmonitor=false',
        'core.hooksPath=/dev/nonexistent',
        'diff.external=',
        'protocol.version=2',
    ];

    /**
     * The child's complete environment. Nothing is inherited: a Git subprocess
     * over an untrusted tree has no business seeing the server's bearer token,
     * data directory, or database credentials. HOME is deliberately a path that
     * does not exist so no user config is found even if GIT_CONFIG_GLOBAL were
     * ignored by an older Git.
     *
     * @var array<string, string>
     */
    public const ENVIRONMENT = [
        'HOME' => '/dev/nonexistent',
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_ASKPASS' => '',
        'GIT_OPTIONAL_LOCKS' => '0',
    ];

    public function __construct(private int $maxOutputBytes = 2_000_000, private int $maxErrorBytes = 65_536) {}

    /**
     * Run a git command under a deadline with bounded, non-blocking reads.
     *
     * @param non-empty-list<string> $command
     */
    public function run(array $command, int $timeoutMs, string $operation): string
    {
        $pipes = [];
        $process = proc_open(
            self::harden($command),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            sys_get_temp_dir(),
            self::environment(),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Git.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $observedExit = -1;
        $deadline = hrtime(true) + ($timeoutMs * 1_000_000);
        try {
            while (true) {
                $status = proc_get_status($process);
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                if (strlen($stdout) > $this->maxOutputBytes || strlen($stderr) > $this->maxErrorBytes) {
                    throw new RuntimeException(sprintf('Git %s output exceeded its configured byte limit.', $operation));
                }
                if (!$status['running']) {
                    $observedExit = $status['exitcode'];
                    break;
                }
                if (hrtime(true) > $deadline) {
                    throw new RuntimeException(sprintf('Git %s timed out.', $operation));
                }
                $read = [$pipes[1], $pipes[2]];
                $write = $except = [];
                @stream_select($read, $write, $except, 0, 100_000);
            }
        } catch (Throwable $error) {
            proc_terminate($process, 9);
            throw $error;
        } finally {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
        }
        $closedExit = proc_close($process);
        $exit = $observedExit >= 0 ? $observedExit : $closedExit;
        if ($exit !== 0) {
            throw new RuntimeException(sprintf('Git %s unavailable: %s', $operation, substr(trim($stderr), 0, 500)));
        }
        return $stdout;
    }

    /**
     * Insert the forced config overrides directly after the binary, where Git
     * requires its top-level options, leaving the caller's own options and
     * subcommand untouched. {@see \Knossos\Runtime\DoctorService} reuses this
     * runner's deadline/select loop for non-Git version probes (`node
     * --version`, `python3 --version`); those binaries do not understand `-c`,
     * so the overrides are scoped to commands whose binary is actually git.
     *
     * @param non-empty-list<string> $command
     * @return non-empty-list<string>
     */
    private static function harden(array $command): array
    {
        if (basename($command[0]) !== 'git') {
            return $command;
        }
        $hardened = [$command[0]];
        foreach (self::FORCED_CONFIG as $setting) {
            $hardened[] = '-c';
            $hardened[] = $setting;
        }

        return [...$hardened, ...array_slice($command, 1)];
    }

    /**
     * The child environment: the constant above plus PATH, which Git needs to
     * find its own helper binaries.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $path = getenv('PATH');

        return self::ENVIRONMENT + ['PATH' => is_string($path) && $path !== '' ? $path : '/usr/bin:/bin'];
    }
}
