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
 * repository-specific filter/diff drivers — following `include`/`includeIf`
 * directives and per-worktree config, since either can define a driver a
 * narrower query would miss — and runs under an explicit, minimal environment
 * — the same treatment WorkerProcessSupervisor gives the scanner workers, for
 * the same reason.
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
     * Upper bound, in milliseconds, on the driver-enumeration probe that
     * {@see self::driverOverrides()} runs before the caller's real command.
     * Bounded independently of the caller's own timeout so a hostile or slow
     * repository cannot inflate the probe to consume the whole budget by
     * itself; {@see self::run()} additionally deducts the probe's actual
     * elapsed time from the real command's deadline, so a single `run()` call
     * still completes close to its caller-supplied `$timeoutMs` rather than
     * up to double it.
     */
    private const MAX_ENUMERATION_TIMEOUT_MS = 2_000;

    /**
     * Upper bound on the number of distinct filter/diff-driver names {@see
     * self::parseDriverOverrides()} will neutralise in one call. A real
     * repository defines a handful (Git-LFS, git-crypt, and the like); this
     * exists only to fail closed on a repository that defines an unreasonable
     * number of them, rather than build an argv list long enough to make
     * `proc_open()` itself fail. Public for the same reason {@see
     * self::FORCED_CONFIG} is: so a test can assert the cap without
     * reconstructing it.
     */
    public const MAX_DRIVER_NAMES = 1_000;

    /**
     * Upper bound, in bytes, on the `-c` overrides {@see
     * self::parseDriverOverrides()} will place on one command line.
     *
     * The count above bounds the wrong quantity on its own: a driver name is
     * unbounded in length, so 900 drivers named with 2 KB each stay under
     * MAX_DRIVER_NAMES and still build an argv far past `ARG_MAX` — the exact
     * `proc_open()` failure the count exists to prevent, reached by a route it
     * cannot see. Nothing legitimate comes close: 128 KiB is Linux's
     * per-argument `MAX_ARG_STRLEN` and a small fraction of the 2 MB total,
     * while a real repository's whole override list is a few hundred bytes.
     * Public for the same reason MAX_DRIVER_NAMES is.
     */
    public const MAX_DRIVER_OVERRIDE_BYTES = 131_072;

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
     * Hardening (including the driver-enumeration probe it runs) happens
     * first and is timed; whatever it consumed is deducted from the deadline
     * given to the real command, so the two subprocesses together still
     * respect `$timeoutMs` rather than each getting the full budget.
     *
     * @param non-empty-list<string> $command
     */
    public function run(array $command, int $timeoutMs, string $operation): string
    {
        $start = hrtime(true);
        $hardened = $this->harden($command, $timeoutMs);
        $elapsedMs = (int) ((hrtime(true) - $start) / 1_000_000);

        return $this->execute($hardened, max(1, $timeoutMs - $elapsedMs), $operation);
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
     * defined anywhere that repository's config resolves from; `diff.<name>.textconv`
     * is the equivalent hook for `git diff`. "Anywhere it resolves from" is
     * wider than the repository's own `.git/config`: an `include.path` (or
     * `includeIf`) directive can pull in another file that defines the driver,
     * and `extensions.worktreeConfig=true` moves per-worktree settings into
     * `.git/config.worktree`, outside plain `--local` scope. Both are
     * ordinary Git features a repository can enable on itself, so the
     * enumeration query has to see everything `git diff` itself would resolve:
     * `--includes` follows include directives, and omitting `--local` (system
     * and global are already suppressed via {@see self::ENVIRONMENT}) leaves
     * worktree config in view. `--name-only -z` returns NUL-separated keys
     * with no values, so a value containing `=` or a NUL byte cannot be
     * mistaken for another key.
     *
     * This enumeration query itself refreshes no index and invokes no filter,
     * so unlike the caller's real command it does not need {@see
     * self::FORCED_CONFIG} applied to it — it runs with only the restricted
     * {@see self::ENVIRONMENT}, under its own bounded sub-timeout (see {@see
     * self::MAX_ENUMERATION_TIMEOUT_MS}).
     *
     * Returns no overrides, rather than failing, when `$command` carries no
     * `-C <root>`: such a command (e.g. `git --version`) does not target a
     * repository tree and so has no filter/diff drivers to neutralise. When a
     * root *is* present, any failure enumerating its config — including a
     * driver name this method cannot safely neutralise, see {@see
     * self::assertNameIsOverridable()} — propagates instead of being
     * swallowed, so a gap is a loud error rather than a silent one.
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
        $keys = $this->execute(
            [$command[0], '--no-optional-locks', '-C', $root, 'config', '--list', '--includes', '--name-only', '-z'],
            min($timeoutMs, self::MAX_ENUMERATION_TIMEOUT_MS),
            'driver enumeration',
        );

        return self::parseDriverOverrides($keys);
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
     * Parse NUL-separated `git config --list --name-only -z` keys into `-c`
     * overrides that blank every `filter.<name>.clean`/`.process`/`.smudge`
     * and `diff.<name>.textconv` entry the repository defines, plus
     * `filter.<name>.required=false`.
     *
     * That last one is not itself a hook: `filter.<name>.required=true` is
     * how a repository tells Git to treat a missing or failing filter as
     * fatal rather than a pass-through. Git-LFS's own `git lfs install --local`
     * writes exactly that (`clean = cat`, `smudge = cat`, `required = true`)
     * into `.git/config`, so blanking `clean`/`process`/`smudge` without also
     * forcing `required=false` turns an ordinary Git-LFS repository into a
     * hard `fatal: clean filter '<name>' failed` on every one of these tools
     * — the same failure a hostile repository could otherwise force
     * deliberately by setting `required = true` on its own filter.
     *
     * @return list<string>
     */
    private static function parseDriverOverrides(string $keys): array
    {
        $filters = [];
        $diffDrivers = [];
        foreach (explode("\0", $keys) as $key) {
            if ($key === '') {
                continue;
            }
            if (preg_match('/^filter\.(.+)\.(?:clean|process|smudge)$/', $key, $matches) === 1) {
                $filters[$matches[1]] = true;
            } elseif (preg_match('/^diff\.(.+)\.textconv$/', $key, $matches) === 1) {
                $diffDrivers[$matches[1]] = true;
            }
        }
        $driverCount = count($filters) + count($diffDrivers);
        if ($driverCount > self::MAX_DRIVER_NAMES) {
            throw new RuntimeException(sprintf(
                'Repository defines %d filter/diff drivers, exceeding the %d this runner will neutralise in one call; refusing to run rather than build an unbounded command line.',
                $driverCount,
                self::MAX_DRIVER_NAMES,
            ));
        }
        $overrides = [];
        foreach (array_keys($filters) as $name) {
            self::assertNameIsOverridable($name, 'filter');
            $overrides[] = sprintf('filter.%s.clean=', $name);
            $overrides[] = sprintf('filter.%s.process=', $name);
            $overrides[] = sprintf('filter.%s.smudge=', $name);
            $overrides[] = sprintf('filter.%s.required=false', $name);
        }
        foreach (array_keys($diffDrivers) as $name) {
            self::assertNameIsOverridable($name, 'diff driver');
            $overrides[] = sprintf('diff.%s.textconv=', $name);
        }
        self::assertOverridesFitOneCommandLine($overrides);

        return $overrides;
    }

    /**
     * Refuse an override list too large to hand to `proc_open()`, whatever the
     * number of drivers that produced it.
     *
     * Measured after building because the strings are what actually land in
     * argv, and holding a few megabytes of them in PHP memory is harmless —
     * it is the `execve()` that fails, loudly and unattributably, with a raw
     * warning on a process whose stdout is an MCP protocol stream. Each
     * override costs its own bytes plus the `-c` that precedes it and the two
     * NUL terminators the kernel counts.
     *
     * @param list<string> $overrides
     */
    private static function assertOverridesFitOneCommandLine(array $overrides): void
    {
        $bytes = 0;
        foreach ($overrides as $override) {
            $bytes += strlen($override) + 4;
        }
        if ($bytes > self::MAX_DRIVER_OVERRIDE_BYTES) {
            throw new RuntimeException(sprintf(
                'Repository filter/diff driver overrides occupy %d bytes, exceeding the %d this runner will place on one command line; refusing to run rather than build an unbounded command line.',
                $bytes,
                self::MAX_DRIVER_OVERRIDE_BYTES,
            ));
        }
    }

    /**
     * Refuse a driver name that a `-c key=value` override cannot target.
     *
     * Git's `-c` parser splits its argument on the *first* `=`, the same way
     * a config file line does. A driver named e.g. `a=b` would need an
     * override of `filter.a=b.clean=`, which Git itself reads back as key
     * `filter.a` with value `b.clean=` — not the driver at all. No override
     * can express this name, so there is nothing safe to append; the caller's
     * command must not run un-neutralised, hence a throw rather than a
     * silently skipped override.
     */
    private static function assertNameIsOverridable(string $name, string $kind): void
    {
        if (str_contains($name, '=')) {
            throw new RuntimeException(sprintf(
                "Repository %s '%s' cannot be safely neutralised: '-c' overrides split on the first '=', so a name containing '=' is not expressible as one. Refusing to run.",
                $kind,
                $name,
            ));
        }
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
     * `proc_open()` is called with `@`: an argv long enough (e.g. a huge
     * `-c` list) can make the underlying `posix_spawn()` fail with a raw PHP
     * Warning, and this process's stdout is an MCP protocol stream where
     * {@see \Knossos\Mcp\StdioServer} — one stray write corrupts it — so no
     * warning may reach it. The `is_resource()` check immediately below
     * already turns that failure into a clean exception; `@` only silences
     * the warning that would otherwise print before it.
     *
     * @param non-empty-list<string> $command
     */
    private function execute(array $command, int $timeoutMs, string $operation): string
    {
        $pipes = [];
        // Cleared first so error_get_last() below reports this spawn's failure
        // and not some unrelated warning from earlier in the request.
        error_clear_last();
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            sys_get_temp_dir(),
            self::environment(),
        );
        if (!is_resource($process)) {
            // '@' suppresses the warning's output, not its recording: the
            // reason ('Argument list too long', 'No such file or directory')
            // is the only clue to why the spawn failed, and dropping it left
            // callers with a bare sentence and nothing to act on. It travels
            // in the exception message, which surfaces on stderr and in
            // diagnostics — never on this process's stdout MCP stream.
            $reason = error_get_last()['message'] ?? null;
            throw new RuntimeException('Unable to start Git.' . ($reason === null ? '' : ' ' . $reason));
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
