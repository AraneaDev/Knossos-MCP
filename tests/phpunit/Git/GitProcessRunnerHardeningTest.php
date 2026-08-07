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

            (new GitProcessRunner())->run(
                [$git, '--no-optional-locks', '--no-pager', '-C', $root, 'diff', '--name-status', '-z', '--no-ext-diff', '--find-renames', 'HEAD', '--'],
                5000,
                'hardening test',
            );

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

            (new GitProcessRunner())->run(
                [$git, '--no-optional-locks', '--no-pager', '-C', $root, 'diff', '--name-status', '-z', '--no-ext-diff', '--find-renames', 'HEAD', '--'],
                5000,
                'hardening test',
            );

            self::assertFileDoesNotExist($canary, 'filter.pwn.clean was executed by a read-only Git query.');
        } finally {
            self::runQuiet(['rm', '-rf', $root]);
            @unlink($canary);
        }
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
