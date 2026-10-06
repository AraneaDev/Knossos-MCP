<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Git;

use Knossos\Git\GitProcessRunner;
use Knossos\Git\ProcessGitHistoryProvider;
use Knossos\Tests\Phpunit\Support\Processes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The Git subprocess boundary: a scanned repository's own .git/config must not
 * be able to turn a read-only query into command execution.
 */
#[Group('git')]
final class GitProcessRunnerHardeningTest extends TestCase
{
    use Processes;

    /** Every forced override is present, so no call site can omit one. */
    public function testForcedConfigDisablesRepositoryControlledCommandHooks(): void
    {
        self::assertContains('core.fsmonitor=false', GitProcessRunner::FORCED_CONFIG);
        self::assertContains('core.hooksPath=/dev/nonexistent', GitProcessRunner::FORCED_CONFIG);
        self::assertContains('diff.external=', GitProcessRunner::FORCED_CONFIG);
        self::assertContains('log.showSignature=false', GitProcessRunner::FORCED_CONFIG);
        self::assertContains('core.sshCommand=/dev/nonexistent', GitProcessRunner::FORCED_CONFIG);
        foreach (['gpg.program', 'gpg.openpgp.program', 'gpg.ssh.program', 'gpg.x509.program'] as $key) {
            self::assertContains($key . '=/dev/nonexistent', GitProcessRunner::FORCED_CONFIG);
        }
    }

    /** The child environment is an explicit allow-list, never the parent's. */
    public function testEnvironmentSuppressesSystemAndGlobalConfig(): void
    {
        self::assertSame('1', GitProcessRunner::ENVIRONMENT['GIT_CONFIG_NOSYSTEM']);
        self::assertSame('/dev/null', GitProcessRunner::ENVIRONMENT['GIT_CONFIG_GLOBAL']);
        self::assertSame('0', GitProcessRunner::ENVIRONMENT['GIT_TERMINAL_PROMPT']);
        self::assertSame('1', GitProcessRunner::ENVIRONMENT['GIT_NO_LAZY_FETCH']);
        self::assertSame(':', GitProcessRunner::ENVIRONMENT['GIT_ALLOW_PROTOCOL']);
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
                [
                    'ARGV',
                    '-c', 'core.fsmonitor=false',
                    '-c', 'core.hooksPath=/dev/nonexistent',
                    '-c', 'diff.external=',
                    '-c', 'protocol.version=2',
                    '-c', 'log.showSignature=false',
                    '-c', 'gpg.program=/dev/nonexistent',
                    '-c', 'gpg.openpgp.program=/dev/nonexistent',
                    '-c', 'gpg.ssh.program=/dev/nonexistent',
                    '-c', 'gpg.x509.program=/dev/nonexistent',
                    '-c', 'core.sshCommand=/dev/nonexistent',
                    'diff',
                ],
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
            self::assertStringContainsString('GIT_NO_LAZY_FETCH=1', $env);
            self::assertContains('GIT_ALLOW_PROTOCOL=:', explode("\n", $env));
            self::assertStringContainsString('PATH=', $env);
            self::assertStringNotContainsString($secretName, $env, 'A parent-only variable must not reach the child.');
        } finally {
            putenv($secretName);
            self::runQuiet(['rm', '-rf', $dir]);
        }
    }

    /**
     * A containerised run names the mounted project as safe: the project is
     * the host user's, the container's user is another, and git refuses a
     * repository another user owns. The name reaches git as a `-c` setting,
     * the one place git honours it besides the system and global files this
     * runner never reads; nothing else does, and an unset one adds nothing.
     */
    public function testANamedSafeDirectoryReachesGitAsAConfigSetting(): void
    {
        $dir = sys_get_temp_dir() . '/knossos-git-hardening-safe-' . bin2hex(random_bytes(8));
        mkdir($dir, 0o700, true);
        $fakeGit = $dir . '/git';
        file_put_contents($fakeGit, "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\037' \"\$a\"; done\n");
        chmod($fakeGit, 0o700);
        try {
            putenv('KNOSSOS_GIT_SAFE_DIRECTORY=/work/project');
            $argv = explode("\037", rtrim((new GitProcessRunner())->run([$fakeGit, 'status'], 5000, 'safe probe'), "\037"));
            self::assertSame(['-c', 'safe.directory=/work/project'], array_slice($argv, -3, 2));
            // Only an absolute path is a directory to trust.
            putenv('KNOSSOS_GIT_SAFE_DIRECTORY=*');
            self::assertNotContains('safe.directory=*', explode("\037", (new GitProcessRunner())->run([$fakeGit, 'status'], 5000, 'safe probe')));
            // Nor is a path with a line break after it: one absolute path means nothing follows it.
            putenv("KNOSSOS_GIT_SAFE_DIRECTORY=/work/project\n");
            self::assertSame([], array_values(array_filter(
                explode("\037", (new GitProcessRunner())->run([$fakeGit, 'status'], 5000, 'safe probe')),
                static fn(string $arg): bool => str_starts_with($arg, 'safe.directory='),
            )));
        } finally {
            putenv('KNOSSOS_GIT_SAFE_DIRECTORY');
            self::runQuiet(['rm', '-rf', $dir]);
        }
    }

    /** A repository another user owns is read once it is named safe, and refused otherwise. */
    public function testARepositoryAnotherUserOwnsIsReadOnlyWhenNamedSafe(): void
    {
        $git = self::locateGit();
        if ($git === null || !function_exists('posix_getuid') || posix_getuid() !== 0) {
            self::markTestSkipped('Needs git and root, to hand the repository to another user.');
        }
        $root = sys_get_temp_dir() . '/knossos-git-hardening-owner-' . bin2hex(random_bytes(8));
        mkdir($root, 0o755, true);
        try {
            self::runQuiet([$git, 'init', '--quiet', $root]);
            self::runQuiet([$git, '-C', $root, '-c', 'user.name=t', '-c', 'user.email=t@example.test', 'commit', '--quiet', '--allow-empty', '-m', 'first']);
            self::runQuiet(['chown', '-R', '65534:65534', $root]);
            $head = [$git, '-C', $root, 'rev-parse', 'HEAD'];
            try {
                (new GitProcessRunner())->run($head, 5000, 'owner probe');
                self::fail('git read a repository another user owns without being told it is safe.');
            } catch (\RuntimeException) {
                // Expected: dubious ownership.
            }
            putenv('KNOSSOS_GIT_SAFE_DIRECTORY=' . $root);
            self::assertSame(40, strlen(trim((new GitProcessRunner())->run($head, 5000, 'owner probe'))));
        } finally {
            putenv('KNOSSOS_GIT_SAFE_DIRECTORY');
            self::runQuiet(['rm', '-rf', $root]);
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
     * `log.showSignature` makes `git log` and `git show` verify each signed
     * commit by running `gpg.program`, and both keys come from the
     * repository's own config. A history query over such a repository must
     * not run that program. Skipped where git is unavailable.
     */
    public function testPlantedGpgProgramIsNotExecutedByHistory(): void
    {
        $git = self::locateGit();
        if ($git === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $root = sys_get_temp_dir() . '/knossos-git-hardening-gpg-' . bin2hex(random_bytes(8));
        $canary = $root . '.canary';
        $program = $root . '.gpg';
        $object = $root . '.commit';
        mkdir($root, 0o700, true);
        try {
            self::runQuiet([$git, 'init', '-q', $root]);
            file_put_contents($root . '/a.txt', "hi\n");
            self::runQuiet([$git, '-C', $root, 'add', 'a.txt']);
            self::runQuiet([$git, '-C', $root, '-c', 'user.email=a@b', '-c', 'user.name=a', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'init']);
            $tree = trim((string) shell_exec(escapeshellarg($git) . ' -C ' . escapeshellarg($root) . ' rev-parse "HEAD^{tree}"'));
            $now = time();
            // A commit carrying a signature header: what makes git call gpg.program.
            file_put_contents($object, sprintf(
                "tree %s\nauthor a <a@b> %d +0000\ncommitter a <a@b> %d +0000\n"
                . "gpgsig -----BEGIN PGP SIGNATURE-----\n \n AAAA\n -----END PGP SIGNATURE-----\n\nsigned\n",
                $tree,
                $now,
                $now,
            ));
            $commit = trim((string) shell_exec(escapeshellarg($git) . ' -C ' . escapeshellarg($root) . ' hash-object -t commit -w ' . escapeshellarg($object)));
            self::runQuiet([$git, '-C', $root, 'update-ref', 'HEAD', $commit]);
            file_put_contents($program, sprintf("#!/bin/sh\ntouch %s\nexit 1\n", escapeshellarg($canary)));
            chmod($program, 0o700);
            self::runQuiet([$git, '-C', $root, 'config', 'log.showSignature', 'true']);
            self::runQuiet([$git, '-C', $root, 'config', 'gpg.program', $program]);

            (new ProcessGitHistoryProvider())->history($root, 3650, 10, 5000);

            self::assertFileDoesNotExist($canary, 'gpg.program was executed by a read-only history query.');
        } finally {
            self::runQuiet(['rm', '-rf', $root]);
            @unlink($canary);
            @unlink($program);
            @unlink($object);
        }
    }

    /**
     * A partial clone fetches a missing blob from its promisor remote the
     * moment a command reads it, and that fetch runs the transport program
     * the repository's own config names: `core.sshCommand` for an ssh URL.
     * A repository-level `protocol.ssh.allow=always` would beat a `-c
     * protocol.allow=never`, so the fetch has to be refused by environment.
     * Reading the blob through the runner must not run that program.
     * Skipped where git is unavailable.
     */
    public function testLazyFetchOverSshDoesNotRunTheRepositorySshCommand(): void
    {
        $git = self::locateGit();
        if ($git === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $fixture = self::partialCloneFixture($git, 'ssh');
        try {
            try {
                (new GitProcessRunner())->run([$git, '-C', $fixture['root'], 'show', 'HEAD:b.txt'], 5000, 'lazy fetch probe');
            } catch (\RuntimeException) {
                // The blob is missing and must stay missing; only the canary matters.
            }

            self::assertFileDoesNotExist($fixture['canary'], 'core.sshCommand was executed by a lazy fetch.');
        } finally {
            self::removePartialCloneFixture($fixture);
        }
    }

    /**
     * The same lazy fetch reached through the session diff, which probes the
     * blob with `cat-file -e` and then diffs it. Skipped where git is
     * unavailable.
     */
    public function testSessionDiffOverAPartialCloneDoesNotRunTheRepositorySshCommand(): void
    {
        $git = self::locateGit();
        if ($git === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $fixture = self::partialCloneFixture($git, 'ssh', 'a.txt');
        try {
            file_put_contents($fixture['root'] . '/a.txt', "changed\n");

            (new \Knossos\Query\SessionDiffService())->diff($fixture['root'], $fixture['commit'], 'a.txt');

            self::assertFileDoesNotExist($fixture['canary'], 'core.sshCommand was executed by a session diff.');
        } finally {
            self::removePartialCloneFixture($fixture);
        }
    }

    /**
     * A promisor remote reached by local path or `file://` URL runs
     * `remote.<name>.uploadpack` instead of an ssh program, so blocking ssh
     * alone leaves this variant open. Skipped where git is unavailable.
     */
    #[DataProvider('localRemoteVariants')]
    public function testLazyFetchOverALocalRemoteDoesNotRunTheRepositoryUploadPack(string $variant): void
    {
        $git = self::locateGit();
        if ($git === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $fixture = self::partialCloneFixture($git, $variant);
        try {
            try {
                (new GitProcessRunner())->run([$git, '-C', $fixture['root'], 'show', 'HEAD:b.txt'], 5000, 'lazy fetch probe');
            } catch (\RuntimeException) {
                // The blob is missing and must stay missing; only the canary matters.
            }

            self::assertFileDoesNotExist($fixture['canary'], 'remote.origin.uploadpack was executed by a lazy fetch over a ' . $variant . ' remote.');
        } finally {
            self::removePartialCloneFixture($fixture);
        }
    }

    /**
     * A URL of the form `none::<address>` asks Git for a remote helper named
     * `git-remote-none`, looked up on PATH. The transport allow-list must
     * refuse every transport, including one with that name, so a helper of
     * that name on the inherited PATH must not run. The allow-list is probed
     * directly rather than through a lazy fetch, so the lazy-fetch switch
     * cannot hide it. Skipped where git is unavailable.
     */
    public function testTheTransportAllowListRefusesAHelperNamedNone(): void
    {
        $git = self::locateGit();
        if ($git === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $bin = sys_get_temp_dir() . '/knossos-git-hardening-helper-' . bin2hex(random_bytes(8));
        $canary = $bin . '.canary';
        mkdir($bin, 0o700, true);
        file_put_contents($bin . '/git-remote-none', sprintf("#!/bin/sh\ntouch %s\nexit 1\n", escapeshellarg($canary)));
        chmod($bin . '/git-remote-none', 0o700);
        $previous = getenv('PATH');
        putenv('PATH=' . $bin . ':' . (is_string($previous) && $previous !== '' ? $previous : '/usr/bin:/bin'));
        try {
            try {
                (new GitProcessRunner())->run([$git, 'ls-remote', 'none::x'], 5000, 'allow-list probe');
            } catch (\RuntimeException) {
                // The transport is refused, so the command fails; only the canary matters.
            }

            self::assertFileDoesNotExist($canary, 'git-remote-none was executed although every transport is refused.');
        } finally {
            putenv($previous === false ? 'PATH' : 'PATH=' . $previous);
            self::runQuiet(['rm', '-rf', $bin]);
            @unlink($canary);
        }
    }

    /** @return array<string, array{string}> */
    public static function localRemoteVariants(): array
    {
        return ['plain path' => ['path'], 'file:// URL' => ['file']];
    }

    /**
     * A committed repository dressed as a partial clone: one blob deleted from
     * the object store, `origin` marked as its promisor, and the transport
     * program for `$transport` pointed at a script that creates the canary.
     *
     * @return array{root: string, canary: string, program: string, remote: string, commit: string}
     */
    private static function partialCloneFixture(string $git, string $transport, string $missing = 'b.txt'): array
    {
        $root = sys_get_temp_dir() . '/knossos-git-hardening-promisor-' . bin2hex(random_bytes(8));
        $fixture = ['root' => $root, 'canary' => $root . '.canary', 'program' => $root . '.transport', 'remote' => $root . '.remote', 'commit' => ''];
        mkdir($root, 0o700, true);
        mkdir($fixture['remote'], 0o700, true);
        self::runQuiet([$git, 'init', '-q', $root]);
        file_put_contents($root . '/a.txt', "a\n");
        file_put_contents($root . '/b.txt', "b\n");
        self::runQuiet([$git, '-C', $root, 'add', 'a.txt', 'b.txt']);
        self::runQuiet([$git, '-C', $root, '-c', 'user.email=a@b', '-c', 'user.name=a', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'init']);
        $fixture['commit'] = trim((string) shell_exec(escapeshellarg($git) . ' -C ' . escapeshellarg($root) . ' rev-parse HEAD'));
        $blob = trim((string) shell_exec(escapeshellarg($git) . ' -C ' . escapeshellarg($root) . ' rev-parse ' . escapeshellarg('HEAD:' . $missing)));
        @unlink($root . '/.git/objects/' . substr($blob, 0, 2) . '/' . substr($blob, 2));
        file_put_contents($fixture['program'], sprintf("#!/bin/sh\ntouch %s\nexit 1\n", escapeshellarg($fixture['canary'])));
        chmod($fixture['program'], 0o700);
        $config = [
            'extensions.partialClone' => 'origin',
            'remote.origin.promisor' => 'true',
        ];
        if ($transport === 'ssh') {
            $config += [
                'remote.origin.url' => 'ssh://promisor.invalid/repo',
                'core.sshCommand' => $fixture['program'],
                'protocol.ssh.allow' => 'always',
            ];
        } else {
            $config += [
                'remote.origin.url' => ($transport === 'file' ? 'file://' : '') . $fixture['remote'],
                'remote.origin.uploadpack' => $fixture['program'],
                'protocol.file.allow' => 'always',
            ];
        }
        foreach ($config as $key => $value) {
            self::runQuiet([$git, '-C', $root, 'config', $key, $value]);
        }

        return $fixture;
    }

    /** @param array{root: string, canary: string, program: string, remote: string, commit: string} $fixture */
    private static function removePartialCloneFixture(array $fixture): void
    {
        self::runQuiet(['rm', '-rf', $fixture['root'], $fixture['remote']]);
        @unlink($fixture['canary']);
        @unlink($fixture['program']);
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
     * table — arrived as the same bare 'Unable to start Git.' An oversized
     * argv reproduces it without needing git, or a repository, or a real fork.
     *
     * The argv overruns the *total* limit rather than the per-argument one,
     * because only the total is a limit every supported platform has. Linux's
     * per-argument `MAX_ARG_STRLEN` was 128 KiB for years and is 6 MiB on
     * current kernels, so a single 200,000-byte argument stopped failing where
     * this test most often runs; macOS has no separate per-argument cap at
     * all. The totals are bounded much lower and from above: macOS fixes
     * `ARG_MAX` at 1 MiB, and Linux caps its stack-derived limit at 6 MiB. 256
     * arguments of 64 KiB is 16 MiB — clear of both, with every individual
     * argument small enough that no per-argument rule is what rejects it.
     */
    public function testAFailedSpawnCarriesTheReasonIntoTheException(): void
    {
        $method = new \ReflectionMethod(GitProcessRunner::class, 'execute');
        $command = ['/bin/true', ...array_fill(0, 256, str_repeat('x', 65_536))];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Unable to start Git\\. .*[Aa]rgument list too long/');
        $method->invoke(new GitProcessRunner(), $command, 1_000, 'spawn failure test');
    }

    /**
     * A hardening request for a binary that is not `git` is a contradiction,
     * not a silent no-op.
     *
     * `harden()` used to return the command unchanged in that case, so a
     * future call site passing a wrapper name (`git-wrapper`, a vendored
     * `git2`) with the default `hardenGitConfig: true` ran with no hardening
     * at all and nothing anywhere said so. Only `hardenGitConfig: false` — the
     * caller stating that this is not a Git command — turns hardening off.
     */
    public function testHardeningAWrapperBinaryIsRefusedRatherThanSilentlySkipped(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/is not git/');
        (new GitProcessRunner())->run(['/usr/bin/git-wrapper', 'diff'], 1_000, 'wrapper probe');
    }

    /**
     * `-C` is read from the leading option region only, and only once.
     *
     * The root this resolves is the repository whose filter and diff drivers
     * get enumerated and neutralised. A whole-argv search read the first `-C`
     * anywhere, so a pathspec or an option value that happened to be the two
     * characters `-C` — repository-controlled data in a `git diff` — decided
     * which config was enumerated. Repeated `-C` options are refused rather
     * than resolved, because Git composes them relative to one another, so the
     * directory this method returns would not be the one the caller's command
     * actually runs in.
     */
    public function testTheRepositoryRootIsReadFromTheLeadingOptionRegionOnly(): void
    {
        $method = new \ReflectionMethod(GitProcessRunner::class, 'repositoryRoot');

        // The production history argv: `-c <value>` must not end the walk.
        self::assertSame('/repo', $method->invoke(null, [
            'git', '-c', 'core.quotePath=false', '--no-optional-locks', '--no-pager', '-C', '/repo', 'log', '--',
        ]));
        // A pathspec that spells `-C` belongs to the subcommand, not to Git.
        self::assertNull($method->invoke(null, ['git', '--no-pager', 'diff', '--', '-C', '/attacker/tree']));
        self::assertSame('/repo', $method->invoke(null, ['git', '-C', '/repo', 'diff', '--', '-C', '/attacker/tree']));
        // Composed roots resolve to something this method cannot report.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/only one -C option/');
        $method->invoke(null, ['git', '-C', '/repo', '-C', 'nested', 'diff']);
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

    /**
     * Hardening's cost is DEDUCTED from the caller's deadline, not added to it.
     *
     * `run()` times `harden()` — which spawns a `git config --list` to
     * enumerate this repository's filter and diff drivers — and hands the
     * remainder to the real command, so the two subprocesses together still
     * respect the one `$timeoutMs` the caller asked for. Nothing pinned that:
     * mutating the subtraction to an addition left every test green while
     * giving the pair up to twice the requested budget, which is precisely the
     * behaviour the method's docblock promises it prevents.
     *
     * Asserted on the arithmetic rather than by racing two real subprocesses,
     * because a timing test for this is both slow and flaky.
     */
    public function testHardeningTimeIsDeductedFromTheCommandDeadline(): void
    {
        $remaining = new \ReflectionMethod(GitProcessRunner::class, 'remainingBudget');

        self::assertSame(600, $remaining->invoke(null, 1_000, 400), 'Hardening time must be subtracted, not added.');
        self::assertSame(1_000, $remaining->invoke(null, 1_000, 0), 'A free hardening step must leave the budget whole.');
    }

    /**
     * An overrun hardening step floors the deadline at 1ms, never 0 or below.
     *
     * `execute()` reads process status before it reads the deadline, so one
     * millisecond still lets an already-finished child be reaped; a floor of
     * zero reads as "no deadline" to anyone skimming the call, and a negative
     * remainder would be worse. The boundary is the interesting case here —
     * both the exactly-spent and the overspent budget land on the floor.
     */
    public function testAnOverrunHardeningStepFloorsTheDeadlineAtOneMillisecond(): void
    {
        $remaining = new \ReflectionMethod(GitProcessRunner::class, 'remainingBudget');

        self::assertSame(1, $remaining->invoke(null, 500, 500), 'A fully spent budget floors at 1.');
        self::assertSame(1, $remaining->invoke(null, 500, 900), 'An overspent budget floors at 1, never negative.');
        self::assertSame(2, $remaining->invoke(null, 500, 498), 'Just above the floor is returned unchanged.');
    }

    /**
     * The refusal names the binary the caller actually passed.
     *
     * The message exists to tell a future call site which of its arguments was
     * wrong, so reading the wrong element of the command would make it point at
     * a subcommand — or, on a one-element command, at nothing at all.
     */
    public function testTheWrapperRefusalNamesTheOffendingBinary(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#requested for /usr/bin/git-wrapper, which is not git#');
        (new GitProcessRunner())->run(['/usr/bin/git-wrapper', 'diff'], 1_000, 'wrapper probe');
    }
}
