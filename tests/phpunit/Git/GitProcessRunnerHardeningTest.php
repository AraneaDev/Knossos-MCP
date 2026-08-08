<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Git;

use Knossos\Git\GitProcessRunner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The Git subprocess boundary: a scanned repository's own .git/config must not
 * be able to turn a read-only query into command execution.
 */
#[Group('git')]
final class GitProcessRunnerHardeningTest extends TestCase
{
    /** Every forced override is present, so no call site can omit one. */
    public function testForcedConfigDisablesRepositoryControlledCommandHooks(): void
    {
        self::assertContains('core.fsmonitor=false', GitProcessRunner::FORCED_CONFIG);
        self::assertContains('core.hooksPath=/dev/nonexistent', GitProcessRunner::FORCED_CONFIG);
        self::assertContains('diff.external=', GitProcessRunner::FORCED_CONFIG);
    }

    /** The child environment is an explicit allow-list, never the parent's. */
    public function testEnvironmentSuppressesSystemAndGlobalConfig(): void
    {
        self::assertSame('1', GitProcessRunner::ENVIRONMENT['GIT_CONFIG_NOSYSTEM']);
        self::assertSame('/dev/null', GitProcessRunner::ENVIRONMENT['GIT_CONFIG_GLOBAL']);
        self::assertSame('0', GitProcessRunner::ENVIRONMENT['GIT_TERMINAL_PROMPT']);
        self::assertArrayNotHasKey('KNOSSOS_HTTP_BEARER_TOKEN', GitProcessRunner::ENVIRONMENT);
    }

    /**
     * The two tests above only assert the constants contain the right
     * strings; they would still pass if `harden()` never applied them or if
     * `proc_open` never received the restricted environment. This is the
     * always-running behavioural gate: it needs only `/bin/sh`, not `git`, so
     * it is not subject to the gitless-CI skip below. A fake `git` script
     * echoes its own argv and environment back, proving both the injected
     * `-c` overrides and the environment allow-list actually reach the child,
     * and that a parent-only variable does not leak into it.
     */
    public function testHardenedArgvAndEnvironmentReachTheChildProcess(): void
    {
        $dir = sys_get_temp_dir() . '/knossos-git-hardening-argv-' . bin2hex(random_bytes(8));
        mkdir($dir, 0o700, true);
        $fakeGit = $dir . '/git';
        file_put_contents(
            $fakeGit,
            "#!/bin/sh\n"
            . "printf 'ARGV'\n"
            . "for a in \"\$@\"; do printf '\\037%s' \"\$a\"; done\n"
            . "printf '\\n'\n"
            . "env\n",
        );
        chmod($fakeGit, 0o700);
        $secretName = 'KNOSSOS_TEST_PARENT_ONLY_' . bin2hex(random_bytes(4));
        putenv($secretName . '=leaked');
        try {
            $output = (new GitProcessRunner())->run([$fakeGit, 'diff'], 5000, 'argv probe');

            $lines = explode("\n", $output, 2);
            $argv = explode("\037", $lines[0]);
            self::assertSame(
                ['ARGV', '-c', 'core.fsmonitor=false', '-c', 'core.hooksPath=/dev/nonexistent', '-c', 'diff.external=', '-c', 'protocol.version=2', 'diff'],
                $argv,
                'The forced config overrides must precede the caller\'s subcommand.',
            );

            $env = $lines[1] ?? '';
            self::assertStringContainsString('HOME=/dev/nonexistent', $env);
            self::assertStringContainsString('GIT_CONFIG_NOSYSTEM=1', $env);
            self::assertStringContainsString('GIT_CONFIG_GLOBAL=/dev/null', $env);
            self::assertStringContainsString('GIT_TERMINAL_PROMPT=0', $env);
            self::assertStringContainsString('GIT_ASKPASS=/dev/nonexistent', $env);
            self::assertStringContainsString('GIT_OPTIONAL_LOCKS=0', $env);
            self::assertStringContainsString('PATH=', $env);
            self::assertStringNotContainsString($secretName, $env, 'A parent-only variable must not reach the child.');
        } finally {
            putenv($secretName);
            self::runQuiet(['rm', '-rf', $dir]);
        }
    }

    /**
     * A repository that plants core.fsmonitor must not execute it. Skipped
     * where git is unavailable (the quality container is gitless).
     */
    public function testPlantedFsmonitorIsNotExecuted(): void
    {
        $git = self::locateGit();
        if ($git === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $root = sys_get_temp_dir() . '/knossos-git-hardening-' . bin2hex(random_bytes(8));
        $canary = $root . '.canary';
        mkdir($root, 0o700, true);
        try {
            self::runQuiet([$git, 'init', '-q', $root]);
            file_put_contents($root . '/a.txt', "hi\n");
            self::runQuiet([$git, '-C', $root, 'add', 'a.txt']);
            self::runQuiet([$git, '-C', $root, '-c', 'user.email=a@b', '-c', 'user.name=a', 'commit', '-qm', 'init']);
            self::runQuiet([$git, '-C', $root, 'config', 'core.fsmonitor', sprintf('sh -c "echo PWNED > %s; false"', $canary)]);
            file_put_contents($root . '/a.txt', "changed\n");

            (new GitProcessRunner())->run(self::diffCommand($git, $root), 5000, 'hardening test');

            self::assertFileDoesNotExist($canary, 'core.fsmonitor was executed by a read-only Git query.');
        } finally {
            self::runQuiet(['rm', '-rf', $root]);
            @unlink($canary);
        }
    }

    /**
     * A repository that routes a path through a `.gitattributes` clean filter
     * must not execute that filter's command either — `filter.<name>.clean`
     * runs on `git diff` the same way `core.fsmonitor` runs on an index
     * refresh, but it is repository-specific rather than a fixed hook, so it
     * is neutralised by enumerating the repository's own config rather than by
     * a fixed override. Skipped where git is unavailable.
     */
    public function testPlantedCleanFilterIsNotExecuted(): void
    {
        $git = self::locateGit();
        if ($git === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $root = sys_get_temp_dir() . '/knossos-git-hardening-filter-' . bin2hex(random_bytes(8));
        $canary = $root . '.canary';
        mkdir($root, 0o700, true);
        try {
            self::runQuiet([$git, 'init', '-q', $root]);
            file_put_contents($root . '/.gitattributes', "a.txt filter=pwn\n");
            file_put_contents($root . '/a.txt', "hi\n");
            self::runQuiet([$git, '-C', $root, 'add', '.gitattributes', 'a.txt']);
            self::runQuiet([$git, '-C', $root, '-c', 'user.email=a@b', '-c', 'user.name=a', 'commit', '-qm', 'init']);
            self::runQuiet([$git, '-C', $root, 'config', 'filter.pwn.clean', sprintf('sh -c "echo PWNED > %s; cat"', $canary)]);
            file_put_contents($root . '/a.txt', "changed\n");

            (new GitProcessRunner())->run(self::diffCommand($git, $root), 5000, 'hardening test');

            self::assertFileDoesNotExist($canary, 'filter.pwn.clean was executed by a read-only Git query.');
        } finally {
            self::runQuiet(['rm', '-rf', $root]);
            @unlink($canary);
        }
    }

    /**
     * A driver name containing `=` cannot be expressed as a `-c` override —
     * `-c filter.a=b.clean=` is itself parsed by Git as key `filter.a`, value
     * `b.clean=`, which does not target the driver at all. The only safe
     * response is to refuse the command outright rather than run it
     * un-neutralised, so this asserts a throw and an untouched canary, not a
     * successful diff: there is no `-c` override this method could append
     * that would let the read proceed safely. Skipped where git is
     * unavailable.
     */
    public function testDriverNameContainingEqualsFailsClosedInsteadOfExecuting(): void
    {
        $git = self::locateGit();
        if ($git === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $root = sys_get_temp_dir() . '/knossos-git-hardening-eqname-' . bin2hex(random_bytes(8));
        $canary = $root . '.canary';
        mkdir($root, 0o700, true);
        try {
            self::runQuiet([$git, 'init', '-q', $root]);
            file_put_contents($root . '/.gitattributes', "a.txt filter=a=b\n");
            file_put_contents($root . '/a.txt', "hi\n");
            self::runQuiet([$git, '-C', $root, 'add', '.gitattributes', 'a.txt']);
            self::runQuiet([$git, '-C', $root, '-c', 'user.email=a@b', '-c', 'user.name=a', 'commit', '-qm', 'init']);
            self::runQuiet([$git, '-C', $root, 'config', 'filter.a=b.clean', sprintf('sh -c "echo PWNED > %s; cat"', $canary)]);
            file_put_contents($root . '/a.txt', "changed\n");

            $threw = false;
            try {
                (new GitProcessRunner())->run(self::diffCommand($git, $root), 5000, 'hardening test');
            } catch (\RuntimeException) {
                $threw = true;
            }

            self::assertTrue($threw, 'A driver name containing "=" must fail closed rather than run un-neutralised.');
            self::assertFileDoesNotExist($canary, "filter.'a=b'.clean was executed by a read-only Git query.");
        } finally {
            self::runQuiet(['rm', '-rf', $root]);
            @unlink($canary);
        }
    }

    /**
     * `git config --list --local` (round 1's enumeration command) does not
     * expand `include.path`, so a filter defined only in an included file was
     * invisible to it while `git diff` itself still followed the include and
     * ran the filter. `--includes` closes that gap. Skipped where git is
     * unavailable.
     */
    public function testIncludedFilterIsNotExecuted(): void
    {
        $git = self::locateGit();
        if ($git === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $root = sys_get_temp_dir() . '/knossos-git-hardening-include-' . bin2hex(random_bytes(8));
        $canary = $root . '.canary';
        mkdir($root, 0o700, true);
        try {
            self::runQuiet([$git, 'init', '-q', $root]);
            file_put_contents($root . '/.gitattributes', "a.txt filter=pwn\n");
            file_put_contents($root . '/a.txt', "hi\n");
            self::runQuiet([$git, '-C', $root, 'add', '.gitattributes', 'a.txt']);
            self::runQuiet([$git, '-C', $root, '-c', 'user.email=a@b', '-c', 'user.name=a', 'commit', '-qm', 'init']);
            file_put_contents(
                $root . '/.git/extra',
                "[filter \"pwn\"]\n\tclean = sh -c \"echo PWNED > " . $canary . "; cat\"\n",
            );
            self::runQuiet([$git, '-C', $root, 'config', 'include.path', 'extra']);
            file_put_contents($root . '/a.txt', "changed\n");

            $output = (new GitProcessRunner())->run(self::diffCommand($git, $root), 5000, 'hardening test');

            self::assertFileDoesNotExist($canary, 'The included filter.pwn.clean was executed by a read-only Git query.');
            self::assertSame("M\0a.txt\0", $output, 'Neutralising the included filter must not change the diff result.');
        } finally {
            self::runQuiet(['rm', '-rf', $root]);
            @unlink($canary);
        }
    }

    /**
     * With `extensions.worktreeConfig=true`, per-worktree settings live in
     * `.git/config.worktree`, outside plain `--local` scope. Round 1's
     * enumeration (`--local`) missed a filter defined there while `git diff`
     * itself still resolved it. Dropping `--local` (system/global are already
     * suppressed via {@see GitProcessRunner::ENVIRONMENT}) closes that gap.
     * Skipped where git is unavailable.
     */
    public function testWorktreeConfiguredFilterIsNotExecuted(): void
    {
        $git = self::locateGit();
        if ($git === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $root = sys_get_temp_dir() . '/knossos-git-hardening-worktree-' . bin2hex(random_bytes(8));
        $canary = $root . '.canary';
        mkdir($root, 0o700, true);
        try {
            self::runQuiet([$git, 'init', '-q', $root]);
            file_put_contents($root . '/.gitattributes', "a.txt filter=pwn\n");
            file_put_contents($root . '/a.txt', "hi\n");
            self::runQuiet([$git, '-C', $root, 'add', '.gitattributes', 'a.txt']);
            self::runQuiet([$git, '-C', $root, '-c', 'user.email=a@b', '-c', 'user.name=a', 'commit', '-qm', 'init']);
            self::runQuiet([$git, '-C', $root, 'config', 'extensions.worktreeConfig', 'true']);
            self::runQuiet([$git, '-C', $root, 'config', '--worktree', 'filter.pwn.clean', sprintf('sh -c "echo PWNED > %s; cat"', $canary)]);
            file_put_contents($root . '/a.txt', "changed\n");

            $output = (new GitProcessRunner())->run(self::diffCommand($git, $root), 5000, 'hardening test');

            self::assertFileDoesNotExist($canary, 'The worktree-scoped filter.pwn.clean was executed by a read-only Git query.');
            self::assertSame("M\0a.txt\0", $output, 'Neutralising the worktree-scoped filter must not change the diff result.');
        } finally {
            self::runQuiet(['rm', '-rf', $root]);
            @unlink($canary);
        }
    }

    /**
     * Git-LFS's own `git lfs install --local` writes `required = true` next
     * to `clean`/`smudge` in `.git/config`. Blanking `clean`/`process`/`smudge`
     * without also forcing `required=false` turns this from a neutralised
     * filter into a fatal `clean filter '<name>' failed`, breaking every
     * Git-backed tool on an ordinary Git-LFS repository — the same failure a
     * hostile repository could otherwise force deliberately. This plants the
     * same shape (a canary-writing `clean` stands in for LFS's harmless
     * `cat`, so the test also proves the filter itself never runs) and
     * asserts the diff still succeeds with the correct output. Skipped where
     * git is unavailable.
     */
    public function testRequiredFilterDoesNotFailTheDiff(): void
    {
        $git = self::locateGit();
        if ($git === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $root = sys_get_temp_dir() . '/knossos-git-hardening-lfs-' . bin2hex(random_bytes(8));
        $canary = $root . '.canary';
        mkdir($root, 0o700, true);
        try {
            self::runQuiet([$git, 'init', '-q', $root]);
            file_put_contents($root . '/.gitattributes', "a.txt filter=lfs\n");
            file_put_contents($root . '/a.txt', "hi\n");
            self::runQuiet([$git, '-C', $root, 'add', '.gitattributes', 'a.txt']);
            self::runQuiet([$git, '-C', $root, '-c', 'user.email=a@b', '-c', 'user.name=a', 'commit', '-qm', 'init']);
            self::runQuiet([$git, '-C', $root, 'config', 'filter.lfs.clean', sprintf('sh -c "echo PWNED > %s; cat"', $canary)]);
            self::runQuiet([$git, '-C', $root, 'config', 'filter.lfs.smudge', 'cat']);
            self::runQuiet([$git, '-C', $root, 'config', 'filter.lfs.required', 'true']);
            file_put_contents($root . '/a.txt', "changed\n");

            $output = (new GitProcessRunner())->run(self::diffCommand($git, $root), 5000, 'hardening test');

            self::assertFileDoesNotExist($canary, 'filter.lfs.clean was executed by a read-only Git query.');
            self::assertSame("M\0a.txt\0", $output, 'A required=true filter must not turn the diff into a fatal error.');
        } finally {
            self::runQuiet(['rm', '-rf', $root]);
            @unlink($canary);
        }
    }

    /**
     * The cap is enforced while building overrides, not by actually spawning
     * a repository with thousands of filter drivers: this constructs the
     * NUL-separated key list `parseDriverOverrides()` would see directly, via
     * reflection on the private method, and asserts it refuses rather than
     * building an unbounded `-c` list. No git binary is needed, so this test
     * never skips.
     */
    public function testDriverCountAboveTheCapFailsClosedWithoutSpawning(): void
    {
        $keys = '';
        for ($i = 0; $i <= GitProcessRunner::MAX_DRIVER_NAMES; ++$i) {
            $keys .= sprintf("filter.driver%d.clean\0", $i);
        }
        $method = new \ReflectionMethod(GitProcessRunner::class, 'parseDriverOverrides');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeding the ' . GitProcessRunner::MAX_DRIVER_NAMES . '/');
        $method->invoke(null, $keys);
    }

    /**
     * A byte budget, not only a count: MAX_DRIVER_NAMES bounds the wrong
     * quantity on its own, because a driver name has no length limit. A
     * hundred 2 KB names is a hundredth of the permitted count and still
     * builds roughly 800 KB of `-c` arguments — well past ARG_MAX, so
     * `proc_open()` fails with the raw warning the cap exists to avoid.
     */
    public function testDriverNamesTooLargeForOneCommandLineFailClosedWithinTheCount(): void
    {
        $keys = '';
        for ($i = 0; $i < 100; ++$i) {
            $keys .= sprintf("filter.%s%d.clean\0", str_repeat('n', 2_000), $i);
        }
        $method = new \ReflectionMethod(GitProcessRunner::class, 'parseDriverOverrides');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeding the ' . GitProcessRunner::MAX_DRIVER_OVERRIDE_BYTES . ' this runner will place on one command line/');
        $method->invoke(null, $keys);
    }

    /**
     * A spawn that fails says why.
     *
     * `proc_open()` is called under '@' because its warning would corrupt the
     * MCP stdout stream, and the reason was then dropped on the floor: every
     * cause — an argv past ARG_MAX, a missing binary, an exhausted process
     * table — arrived as the same bare 'Unable to start Git.' A single
     * over-long argument reproduces it without needing git, or a repository,
     * or a real fork.
     */
    public function testAFailedSpawnCarriesTheReasonIntoTheException(): void
    {
        $method = new \ReflectionMethod(GitProcessRunner::class, 'execute');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Unable to start Git\\. .*[Aa]rgument list too long/');
        $method->invoke(new GitProcessRunner(), ['/bin/true', str_repeat('x', 200_000)], 1_000, 'spawn failure test');
    }

    /**
     * The exact argv shape `ProcessGitWorkingTreeProvider::changes()` runs for
     * a working-tree diff, shared by the exploit tests above so each plants
     * its own hostile config and runs the identical query the production code
     * runs.
     *
     * @return non-empty-list<string>
     */
    private static function diffCommand(string $git, string $root): array
    {
        return [$git, '--no-optional-locks', '--no-pager', '-C', $root, 'diff', '--name-status', '-z', '--no-ext-diff', '--find-renames', 'HEAD', '--'];
    }

    /** The git binary, or null when the host has none. */
    private static function locateGit(): ?string
    {
        $path = trim((string) @shell_exec('command -v git 2>/dev/null'));

        return $path === '' ? null : $path;
    }

    /**
     * Run a setup command, discarding its output.
     *
     * @param non-empty-list<string> $command
     */
    private static function runQuiet(array $command): void
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return;
        }
        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }
        proc_close($process);
    }
}
