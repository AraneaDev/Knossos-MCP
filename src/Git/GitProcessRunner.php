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
 * index (`core.fsmonitor`), generating a diff (`diff.external`), or resolving
 * a `.gitattributes` filter (`filter.<name>.clean`/`.process`/`.smudge`,
 * `diff.<name>.textconv`). Both `diff` and `ls-files --others` refresh the
 * index, and `diff` runs clean/textconv filters on the paths it touches, so
 * every invocation forces the fixed hooks off, enumerates and neutralises any
 * repository-specific filter/diff drivers, and runs under an explicit,
 * minimal environment — the same treatment WorkerProcessSupervisor gives the
 * scanner workers, for the same reason.
 *
 * Hardening applies only to actual `git` invocations: {@see
 * \Knossos\Runtime\DoctorService} reuses this runner's deadline/select loop
 * for non-Git version probes (`node --version`, `python3 --version`), which
 * do not understand `-c` and must construct with `hardenGitConfig: false`.
 */
final readonly class GitProcessRunner implements GitProcessRunnerInterface
{
    /**
     * Config overrides prepended to every hardened command, as `-c key=value`
     * pairs. Fixed because they name a hook rather than a repository-specific
     * driver; per-repository filter and diff-driver names are enumerated at
     * run time by {@see self::driverOverrides()} instead, since a fixed list
     * cannot anticipate a repository's own `.gitattributes`.
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
     * ignored by an older Git. GIT_ASKPASS points at the same nonexistent path
     * rather than an empty string: PHP's proc_open drops env entries whose
     * value is empty, so an empty string would not reach the child at all.
     *
     * @var array<string, string>
     */
    public const ENVIRONMENT = [
        'HOME' => '/dev/nonexistent',
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_ASKPASS' => '/dev/nonexistent',
        'GIT_OPTIONAL_LOCKS' => '0',
    ];

    /**
     * @param bool $hardenGitConfig Whether to force the config overrides above
     *   and drop the inherited environment. The default is safe for every
     *   actual `git` invocation; callers that reuse this runner to probe an
     *   unrelated binary (see the class docblock) must pass `false` explicitly
     *   rather than rely on the binary's name not matching `git`.
     */
    public function __construct(
        private int $maxOutputBytes = 2_000_000,
        private int $maxErrorBytes = 65_536,
        private bool $hardenGitConfig = true,
    ) {}

    /**
     * Run a git command under a deadline with bounded, non-blocking reads.
     *
     * @param non-empty-list<string> $command
     */
    public function run(array $command, int $timeoutMs, string $operation): string
    {
        return $this->execute($this->harden($command, $timeoutMs), $timeoutMs, $operation);
    }

    /**
     * Insert the forced config overrides, and this repository's own
     * filter/diff-driver overrides, directly after the binary, where Git
     * requires its top-level options, leaving the caller's own options and
     * subcommand untouched.
     *
     * Scoped to actual git invocations by both the explicit `hardenGitConfig`
     * flag and, as a secondary check, the binary's basename — every call site
     * in this codebase passes a literal `'git'`, so the basename check cannot
     * be defeated by repository-controlled input; it exists only to fail
     * closed if a future call site forgets the flag.
     *
     * @param non-empty-list<string> $command
     * @return non-empty-list<string>
     */
    private function harden(array $command, int $timeoutMs): array
    {
        if (!$this->hardenGitConfig || basename($command[0]) !== 'git') {
            return $command;
        }
        $hardened = [$command[0]];
        foreach ([...self::FORCED_CONFIG, ...$this->driverOverrides($command, $timeoutMs)] as $setting) {
            $hardened[] = '-c';
            $hardened[] = $setting;
        }

        return [...$hardened, ...array_slice($command, 1)];
    }

    /**
     * Enumerate this repository's filter and diff-driver names and build `-c`
     * overrides that neutralise them.
     *
     * A `.gitattributes` file inside the scanned tree can route any path
     * through a `filter.<name>.clean` (or `.process`/`.smudge`) command
     * defined in that same repository's own `.git/config`; `diff.<name>.textconv`
     * is the equivalent hook for `git diff`. Both run during the read-only
     * commands this class exists to run, and neither name is known in advance
     * — unlike {@see self::FORCED_CONFIG}, which is fixed — so this runs
     * `git config --list --local` first. That command refreshes nothing and
     * invokes no filter, so it only needs the fixed overrides, not this method,
     * applied to it.
     *
     * Returns no overrides, rather than failing, when `$command` carries no
     * `-C <root>`: such a command (e.g. `git --version`) does not target a
     * repository tree and so has no filter/diff drivers to neutralise. When a
     * root *is* present, any failure enumerating its config propagates instead
     * of being swallowed, so a broken enumeration is a loud error rather than
     * a silent hardening gap.
     *
     * @param non-empty-list<string> $command
     * @return list<string>
     */
    private function driverOverrides(array $command, int $timeoutMs): array
    {
        $root = self::repositoryRoot($command);
        if ($root === null) {
            return [];
        }
        $configList = $this->execute(
            [$command[0], '--no-optional-locks', '-C', $root, 'config', '--list', '--local'],
            $timeoutMs,
            'driver enumeration',
        );

        return self::parseDriverOverrides($configList);
    }

    /**
     * The `-C <root>` argument of a command, or null when it has none.
     *
     * @param non-empty-list<string> $command
     */
    private static function repositoryRoot(array $command): ?string
    {
        $index = array_search('-C', $command, true);

        return $index === false || !isset($command[$index + 1]) ? null : $command[$index + 1];
    }

    /**
     * Parse `git config --list --local` output into `-c` overrides that blank
     * every `filter.<name>.clean`/`.process`/`.smudge` and `diff.<name>.textconv`
     * entry the repository defines.
     *
     * @return list<string>
     */
    private static function parseDriverOverrides(string $configList): array
    {
        $filters = [];
        $diffDrivers = [];
        foreach (preg_split('/\R/', $configList) ?: [] as $line) {
            $separator = strpos($line, '=');
            $key = $separator === false ? $line : substr($line, 0, $separator);
            if (preg_match('/^filter\.(.+)\.(?:clean|process|smudge)$/', $key, $matches) === 1) {
                $filters[$matches[1]] = true;
            } elseif (preg_match('/^diff\.(.+)\.textconv$/', $key, $matches) === 1) {
                $diffDrivers[$matches[1]] = true;
            }
        }
        $overrides = [];
        foreach (array_keys($filters) as $name) {
            $overrides[] = sprintf('filter.%s.clean=', $name);
            $overrides[] = sprintf('filter.%s.process=', $name);
            $overrides[] = sprintf('filter.%s.smudge=', $name);
        }
        foreach (array_keys($diffDrivers) as $name) {
            $overrides[] = sprintf('diff.%s.textconv=', $name);
        }

        return $overrides;
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

    /**
     * Run an already-hardened command under a deadline with bounded,
     * non-blocking reads.
     *
     * @param non-empty-list<string> $command
     */
    private function execute(array $command, int $timeoutMs, string $operation): string
    {
        $pipes = [];
        $process = proc_open(
            $command,
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
}
