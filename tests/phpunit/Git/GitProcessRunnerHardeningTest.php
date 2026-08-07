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
     * A repository that plants core.fsmonitor must not execute it. Skipped
     * where git is unavailable (the quality container is gitless); the argv
     * assertions above are the always-running gate.
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
