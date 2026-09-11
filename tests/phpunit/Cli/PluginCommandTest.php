<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\PluginCommand;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class PluginCommandTest extends KnossosTestCase
{
    private function context(): CliCommandContext
    {
        return $this->contextFor(self::repositoryRoot());
    }

    /** The same context against a stand-in installation root. */
    private function contextFor(string $root): CliCommandContext
    {
        return new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory($root),
            ':memory:',
        );
    }

    /** A temporary directory name, in the convention the emit tests use. */
    private function temporaryPath(string $prefix): string
    {
        return sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    }

    #[Group('cli')]
    public function testPreviewsTheLocalMarketplaceCommandsWithoutRunningThem(): void
    {
        ob_start();
        $status = (new PluginCommand())->run('install-agent-plugin', [], [], $this->context());
        $output = (string) ob_get_clean();

        assertSame(0, $status);
        assertSame(true, str_contains($output, 'claude plugin marketplace add'));
        assertSame(true, str_contains($output, 'claude plugin install knossos@knossos'));
        // Preview by default: the installation root, not a public marketplace name.
        assertSame(true, str_contains($output, self::repositoryRoot()));
        assertSame(true, str_contains($output, '--execute'));
    }

    #[Group('cli')]
    public function testPreviewWritesNothingIntoAnInstallationRootWithoutADescriptor(): void
    {
        // The descriptor is generated at install time now, so a preview has a
        // reason to write one. It must not: a preview that touched the disk
        // would leave a repository dirty just for being asked what it would do.
        $root = $this->temporaryPath('knossos-plugin-root');
        mkdir($root, 0o755, true);

        ob_start();
        $status = (new PluginCommand())->run('install-agent-plugin', [], [], $this->contextFor($root));
        $output = (string) ob_get_clean();

        assertSame(0, $status);
        assertSame(true, str_contains($output, 'Preview only.'));
        assertSame(false, file_exists($root . '/.claude-plugin/marketplace.json'));
        assertSame(false, file_exists($root . '/.claude-plugin'));
        // Nothing whatsoever, not merely no descriptor.
        assertSame([], array_values(array_diff(scandir($root) ?: [], ['.', '..'])));

        exec('rm -rf ' . escapeshellarg($root));
    }

    #[Group('cli')]
    public function testExecuteGeneratesTheMarketplaceDescriptorTheInstallNeeds(): void
    {
        // The descriptor is generated rather than committed, so nothing on
        // disk carries it until an install runs. It is written before the
        // first `claude` command, which is what makes that command resolve.
        $root = $this->sourceRoot();

        $this->runWithStubbedClaude($root, ['execute' => ['true']]);

        $descriptor = $root . '/.plugin/.claude-plugin/marketplace.json';
        assertSame(true, is_file($descriptor));
        $decoded = json_decode((string) file_get_contents($descriptor), true, 8, JSON_THROW_ON_ERROR);
        // The same top-level keys the committed descriptor carried, so a
        // generated one is still a marketplace file Claude Code can read.
        assertSame(['name', 'owner', 'description', 'plugins'], array_keys($decoded));
        assertSame('knossos', $decoded['name']);
        assertSame(1, count($decoded['plugins']));
        assertSame('knossos', $decoded['plugins'][0]['name']);
        // The plugin is the directory the descriptor sits in, which is why the
        // install hands `marketplace add` that directory and not the checkout.
        assertSame('./', $decoded['plugins'][0]['source']);

        exec('rm -rf ' . escapeshellarg($root));
    }

    #[Group('cli')]
    public function testPreviewUnderJsonEmitsTheCommandsAsData(): void
    {
        // --json was accepted and ignored, so a script asking this command what
        // it would run got a paragraph. The commands are the whole content of a
        // preview, so they are the list: one element each, not one string a
        // caller would have to split back apart.
        ob_start();
        $status = (new PluginCommand())->run('install-agent-plugin', [], ['json' => ['true']], $this->context());
        $output = (string) ob_get_clean();

        assertSame(0, $status);
        $decoded = json_decode(trim($output), true, 8, JSON_THROW_ON_ERROR);
        assertSame(true, is_array($decoded));
        assertSame('user', $decoded['scope']);
        assertSame(false, $decoded['executed']);
        assertSame(true, $decoded['preview']);
        assertSame(2, count($decoded['commands']));
        assertSame(true, str_contains($decoded['commands'][0], 'claude plugin marketplace add'));
        assertSame(true, str_contains($decoded['commands'][1], 'claude plugin install knossos@knossos'));
        // Nothing but the object: prose alongside it is what a parser chokes on.
        assertSame(false, str_contains($output, 'Preview only.'));
    }

    #[Group('cli')]
    public function testContainerEmitUnderJsonNamesTheDirectoryAndItsFiles(): void
    {
        // The other half of the same gap. What a caller does next with an
        // emitted plugin is copy, mount or checksum it, so the directory and
        // the files now in it are what the object has to carry.
        $out = sys_get_temp_dir() . '/knossos-plugin-' . bin2hex(random_bytes(4));

        ob_start();
        $status = (new PluginCommand())->run(
            'install-agent-plugin',
            [],
            ['out' => [$out], 'data' => ['/srv/knossos-data'], 'json' => ['true']],
            $this->context(),
        );
        $output = (string) ob_get_clean();

        assertSame(0, $status);
        $decoded = json_decode(trim($output), true, 8, JSON_THROW_ON_ERROR);
        assertSame($out, $decoded['out']);
        assertSame(false, $decoded['existed']);
        assertSame('/srv/knossos-data', $decoded['data']);
        assertSame(true, in_array('hooks/scripts/session-brief.sh', $decoded['files'], true));
        assertSame(true, in_array('skills/knossos/SKILL.md', $decoded['files'], true));
        // Every listed file is really there, so the list cannot drift from what
        // emit() writes without this failing.
        foreach ($decoded['files'] as $relative) {
            assertSame(true, is_file($out . '/' . $relative));
        }
        assertSame(false, str_contains($output, 'Wrote a container plugin'));

        exec('rm -rf ' . escapeshellarg($out));
    }

    #[Group('cli')]
    public function testContainerEmitRequiresTheHostDataPath(): void
    {
        // A process inside the container cannot discover the host path of its own
        // volume, so this must be an error rather than a guess that produces a
        // plugin whose hook silently finds no database.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--data');

        (new PluginCommand())->run(
            'install-agent-plugin',
            [],
            ['out' => [sys_get_temp_dir() . '/knossos-plugin-test']],
            $this->context(),
        );
    }

    #[Group('cli')]
    public function testContainerEmitWritesASelfContainedPluginDirectory(): void
    {
        $out = sys_get_temp_dir() . '/knossos-plugin-' . bin2hex(random_bytes(4));

        ob_start();
        $status = (new PluginCommand())->run(
            'install-agent-plugin',
            [],
            ['out' => [$out], 'data' => ['/srv/knossos-data'], 'image' => ['knossos-mcp:dev']],
            $this->context(),
        );
        ob_get_clean();

        assertSame(0, $status);
        assertSame(true, is_file($out . '/.claude-plugin/plugin.json'));
        assertSame(true, is_file($out . '/.claude-plugin/marketplace.json'));
        assertSame(true, is_file($out . '/hooks/hooks.json'));
        assertSame(true, is_file($out . '/skills/knossos/SKILL.md'));

        $hook = (string) file_get_contents($out . '/hooks/scripts/session-brief.sh');
        assertSame(true, str_contains($hook, 'docker run'));
        assertSame(true, str_contains($hook, 'knossos-mcp:dev'));
        assertSame(true, str_contains($hook, '/srv/knossos-data'));
        assertSame(false, str_contains($hook, '__KNOSSOS_'));  // every token substituted
        // A regression dropping the chmod() call would leave the hook non-executable.
        assertSame('0755', substr(sprintf('%o', fileperms($out . '/hooks/scripts/session-brief.sh')), -4));

        exec('rm -rf ' . escapeshellarg($out));
    }

    #[Group('cli')]
    public function testContainerEmitGeneratesTheDescriptorWhenTheSourceHasNone(): void
    {
        // A stand-in installation root holding every file the emit reads,
        // except the descriptor, which is no longer committed and so is
        // legitimately missing from a fresh checkout. Simulated rather than
        // taken from the real root, which this test must not disturb.
        $root = $this->temporaryPath('knossos-plugin-source');
        foreach ([
            '/.claude-plugin/plugin.json',
            '/hooks/hooks.json',
            '/hooks/scripts/session-brief-container.sh',
            '/skills/knossos/SKILL.md',
        ] as $relative) {
            mkdir(dirname($root . $relative), 0o755, true);
            copy(self::repositoryRoot() . $relative, $root . $relative);
        }
        assertSame(false, file_exists($root . '/.claude-plugin/marketplace.json'));

        $out = $this->temporaryPath('knossos-plugin');
        ob_start();
        $status = (new PluginCommand())->run(
            'install-agent-plugin',
            [],
            ['out' => [$out], 'data' => ['/srv/knossos-data']],
            $this->contextFor($root),
        );
        ob_get_clean();

        assertSame(0, $status);
        // The emitted directory is complete even though its source was not.
        $descriptor = $out . '/.claude-plugin/marketplace.json';
        assertSame(true, is_file($descriptor));
        assertSame(true, is_file($out . '/.claude-plugin/plugin.json'));
        assertSame(true, is_file($out . '/hooks/scripts/session-brief.sh'));
        $decoded = json_decode((string) file_get_contents($descriptor), true, 8, JSON_THROW_ON_ERROR);
        assertSame(['name', 'owner', 'description', 'plugins'], array_keys($decoded));
        assertSame('knossos', $decoded['plugins'][0]['name']);
        assertSame('./', $decoded['plugins'][0]['source']);

        exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($out));
    }

    #[Group('cli')]
    public function testFailedEmitLeavesNoPartialDirectoryBehind(): void
    {
        // A path that already exists as a plain FILE where emit() needs a
        // directory fails deterministically on the first mkdir(), with no
        // root privileges required to trigger it.
        $out = sys_get_temp_dir() . '/knossos-plugin-blocked-' . bin2hex(random_bytes(4));
        file_put_contents($out, 'not a directory');
        $before = filemtime($out);

        try {
            (new PluginCommand())->run(
                'install-agent-plugin',
                [],
                ['out' => [$out], 'data' => ['/srv/knossos-data']],
                $this->context(),
            );
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // Expected: the blocking file leaves emit() unable to create its
            // first directory.
        }

        // The filesystem is exactly as it was before the call: still the
        // original file, unchanged, with no plugin scaffolding beside it.
        assertSame(true, is_file($out));
        assertSame(false, is_dir($out));
        assertSame('not a directory', (string) file_get_contents($out));
        assertSame($before, filemtime($out));

        unlink($out);
    }

    #[Group('cli')]
    public function testFailedEmitRemovesOnlyTheDirectoriesItCreated(): void
    {
        // Pre-create the target so that .claude-plugin already exists (and is
        // therefore NOT tracked as created), but plugin.json is a DIRECTORY
        // where emit() expects to copy() a file. mkdir() of hooks, hooks/scripts
        // and skills/knossos all succeed and ARE tracked; the first copy() then
        // fails on the pre-existing plugin.json directory. This exercises the
        // rollback branch that removes a non-empty set of tracked directories,
        // which the file-blocks-mkdir test above cannot reach.
        $out = sys_get_temp_dir() . '/knossos-plugin-partial-' . bin2hex(random_bytes(4));
        mkdir($out . '/.claude-plugin/plugin.json', 0o755, true);

        try {
            (new PluginCommand())->run(
                'install-agent-plugin',
                [],
                ['out' => [$out], 'data' => ['/srv/knossos-data']],
                $this->context(),
            );
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // Expected: copy() cannot write a file over an existing directory.
        }

        // Every directory the command itself created is gone.
        assertSame(false, is_dir($out . '/hooks'));
        assertSame(false, is_dir($out . '/skills'));
        // The one thing that was already there before the call survives untouched:
        // cleanup removed only what the command made, not the whole target.
        assertSame(true, is_dir($out . '/.claude-plugin/plugin.json'));

        exec('rm -rf ' . escapeshellarg($out));
    }

    /**
     * emit() promises that "anything that was already on disk before this
     * call, whether that is the `--out` directory itself or files inside it,
     * is left exactly as it was found". Rollback tracked only the files it
     * created, so a pre-existing file it overwrote on the way to a later
     * failure kept the new content and the promise did not hold.
     */
    #[Group('cli')]
    public function testFailedEmitRestoresFilesItOverwrote(): void
    {
        $root = $this->sourceRoot();
        $out = $this->temporaryPath('knossos-plugin-overwrite');
        // hooks/hooks.json is copied early; the manifest is written later. Make
        // the manifest path a directory so that later write fails, after the
        // copy has already replaced the caller's file.
        mkdir($out . '/hooks', 0o755, true);
        mkdir($out . '/.claude-plugin/plugin.json', 0o755, true);
        file_put_contents($out . '/hooks/hooks.json', '{"mine":true}');

        try {
            (new PluginCommand())->run(
                'install-agent-plugin',
                [],
                ['out' => [$out], 'data' => ['/srv/knossos-data']],
                $this->contextFor($root),
            );
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // Expected: the manifest cannot be written over a directory.
        }

        assertSame('{"mine":true}', (string) file_get_contents($out . '/hooks/hooks.json'));

        exec('rm -rf ' . escapeshellarg($out));
    }

    /**
     * A stand-in installation root carrying every file an install reads.
     *
     * Built rather than pointed at the real checkout because these tests
     * materialise a plugin directory inside the root they are given, and the
     * repository is not theirs to write into.
     */
    private function sourceRoot(): string
    {
        $root = $this->temporaryPath('knossos-plugin-source');
        foreach ([
            '/.claude-plugin/plugin.json',
            '/hooks/hooks.json',
            '/hooks/scripts/session-brief.sh',
            '/hooks/scripts/session-brief-container.sh',
            '/skills/knossos/SKILL.md',
        ] as $relative) {
            $directory = dirname($root . $relative);
            if (!is_dir($directory)) {
                mkdir($directory, 0o755, true);
            }
            copy(self::repositoryRoot() . $relative, $root . $relative);
        }

        return $root;
    }

    /**
     * Run the install with `claude` stubbed by a script that records its
     * arguments and then fails, so no test can register a marketplace on the
     * machine running it.
     *
     * @return list<string> one recorded line per invocation that was reached
     */
    private function runWithStubbedClaude(string $root, array $options, string $version = '0.0.0'): array
    {
        $bin = $this->temporaryPath('knossos-plugin-bin');
        mkdir($bin, 0o755, true);
        $log = $bin . '/argv';
        file_put_contents($bin . '/claude', "#!/bin/sh\nprintf '%s\\n' \"$*\" >> " . escapeshellarg($log) . "\nexit 1\n");
        chmod($bin . '/claude', 0o755);
        $path = (string) getenv('PATH');

        putenv('PATH=' . $bin);
        try {
            ob_start();
            try {
                (new PluginCommand($version))->run('install-agent-plugin', [], $options, $this->contextFor($root));
                self::fail('Expected the stubbed claude to fail the install.');
            } catch (InvalidArgumentException) {
                // Expected: the stub exits 1, so nothing is really installed.
            } finally {
                ob_get_clean();
            }
        } finally {
            putenv('PATH=' . $path);
        }
        $recorded = is_file($log) ? (string) file_get_contents($log) : '';
        exec('rm -rf ' . escapeshellarg($bin));

        return array_values(array_filter(explode("\n", trim($recorded)), static fn (string $line): bool => $line !== ''));
    }

    /** Every file under $directory, as paths relative to it, sorted. */
    private function treeOf(string $directory): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            $found[] = substr((string) $entry->getPathname(), strlen($directory));
        }
        sort($found);

        return $found;
    }

    #[Group('cli')]
    public function testTheMaterialisedManifestCarriesTheRunningVersion(): void
    {
        // Claude Code caches an installed plugin by the version in its
        // manifest, so a manifest pinned at a placeholder can never be
        // updated: `claude plugin update` reports it is already at the latest
        // version and the snapshot stays whatever was first installed. The
        // version is therefore written at materialise time from the version
        // this CLI is, not copied from the committed file.
        $root = $this->sourceRoot();

        $this->runWithStubbedClaude($root, ['execute' => ['true']], '9.9.9');

        $committed = json_decode(
            (string) file_get_contents($root . '/.claude-plugin/plugin.json'),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );
        $materialised = json_decode(
            (string) file_get_contents($root . '/.plugin/.claude-plugin/plugin.json'),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );

        assertSame('9.9.9', $materialised['version']);
        // The source file is read, never rewritten: an install must not dirty
        // the checkout it installs from.
        assertSame('0.0.0', $committed['version']);
        // Everything else is the committed manifest, so the description, author
        // and keywords stay the file's business rather than the command's.
        assertSame($committed['name'], $materialised['name']);
        assertSame($committed['description'], $materialised['description']);
        assertSame(array_keys($committed), array_keys($materialised));
        // A URL that came back escaped would still parse, and would look wrong
        // to anyone opening the file.
        assertSame(true, str_contains((string) file_get_contents($root . '/.plugin/.claude-plugin/plugin.json'), 'https://github.com'));

        exec('rm -rf ' . escapeshellarg($root));
    }

    #[Group('cli')]
    public function testExecuteRegistersTheMaterialisedPluginDirectoryRatherThanTheCheckout(): void
    {
        // The bug this pins: registering the checkout itself made Claude Code
        // snapshot the whole working tree, caches, vendor and graph included,
        // into its plugin cache. The marketplace it is handed must be a
        // directory that holds the plugin and nothing else.
        $root = $this->sourceRoot();

        $recorded = $this->runWithStubbedClaude($root, ['execute' => ['true']]);

        assertSame(1, count($recorded));
        assertSame("plugin marketplace add {$root}/.plugin --scope user", $recorded[0]);

        exec('rm -rf ' . escapeshellarg($root));
    }

    #[Group('cli')]
    public function testExecuteMaterialisesThePluginFilesAndNothingElse(): void
    {
        $root = $this->sourceRoot();

        $this->runWithStubbedClaude($root, ['execute' => ['true']]);

        assertSame([
            '/.claude-plugin/marketplace.json',
            '/.claude-plugin/plugin.json',
            '/hooks/hooks.json',
            '/hooks/scripts/session-brief.sh',
            '/skills/knossos/SKILL.md',
        ], $this->treeOf($root . '/.plugin'));
        // The descriptor belongs to the materialised directory now. Writing one
        // at the root is what made `marketplace add <root>` resolve at all.
        assertSame(false, file_exists($root . '/.claude-plugin/marketplace.json'));

        exec('rm -rf ' . escapeshellarg($root));
    }

    #[Group('cli')]
    public function testMaterialisedPluginCarriesTheLocalHookNotTheContainerOne(): void
    {
        $root = $this->sourceRoot();

        $this->runWithStubbedClaude($root, ['execute' => ['true']]);

        $hook = (string) file_get_contents($root . '/.plugin/hooks/scripts/session-brief.sh');
        // The local hook finds a binary on the host; the container variant runs
        // `docker run`. An install from a checkout must ship the former.
        assertSame(true, str_contains($hook, 'find_knossos'));
        assertSame(false, str_contains($hook, 'docker run'));
        assertSame('0755', substr(sprintf('%o', fileperms($root . '/.plugin/hooks/scripts/session-brief.sh')), -4));

        exec('rm -rf ' . escapeshellarg($root));
    }

    #[Group('cli')]
    public function testPreviewNamesThePluginDirectoryWithoutCreatingIt(): void
    {
        $root = $this->sourceRoot();

        ob_start();
        $status = (new PluginCommand())->run('install-agent-plugin', [], [], $this->contextFor($root));
        $output = (string) ob_get_clean();

        assertSame(0, $status);
        assertSame(true, str_contains($output, $root . '/.plugin'));
        // A preview that materialised the directory would leave a checkout
        // dirty just for being asked what it would do.
        assertSame(false, file_exists($root . '/.plugin'));

        exec('rm -rf ' . escapeshellarg($root));
    }

    // ----- mutation-audit gaps -----

    /**
     * Run the install with `claude` stubbed by a script that records its
     * arguments and exits with $code, so the success path is reachable without
     * registering anything on the machine running the test.
     *
     * @param array<string, list<string>> $options
     * @return array{status: ?int, output: string, recorded: list<string>, error: ?\Throwable}
     */
    private function runWithClaudeExiting(int $code, string $root, array $options, string $version = '0.0.0'): array
    {
        $bin = $this->temporaryPath('knossos-plugin-bin');
        mkdir($bin, 0o755, true);
        $log = $bin . '/argv';
        file_put_contents($bin . '/claude', "#!/bin/sh\nprintf '%s\\n' \"$*\" >> " . escapeshellarg($log) . "\nexit " . $code . "\n");
        chmod($bin . '/claude', 0o755);
        $path = (string) getenv('PATH');
        putenv('PATH=' . $bin);
        $status = null;
        $error = null;
        ob_start();
        try {
            $status = (new PluginCommand($version))->run('install-agent-plugin', [], $options, $this->contextFor($root));
        } catch (\Throwable $thrown) {
            $error = $thrown;
        } finally {
            $output = (string) ob_get_clean();
            putenv('PATH=' . $path);
        }
        $recorded = is_file($log) ? (string) file_get_contents($log) : '';
        exec('rm -rf ' . escapeshellarg($bin));

        return [
            'status' => $status,
            'output' => $output,
            'recorded' => array_values(array_filter(explode("\n", trim($recorded)), static fn (string $line): bool => $line !== '')),
            'error' => $error,
        ];
    }

    /** An install that succeeds runs both commands and records what it did. */
    #[Group('cli')]
    public function testASuccessfulInstallRunsBothCommandsAndReportsThem(): void
    {
        $root = $this->sourceRoot();

        $run = $this->runWithClaudeExiting(0, $root, ['execute' => ['true'], 'json' => ['true']]);

        assertSame(null, $run['error']);
        assertSame(0, $run['status']);
        assertSame([
            "plugin marketplace add {$root}/.plugin --scope user",
            'plugin install knossos@knossos --scope user --yes',
        ], $run['recorded']);
        $decoded = json_decode(trim($run['output']), true, 8, JSON_THROW_ON_ERROR);
        assertSame(true, $decoded['executed']);
        assertSame($root . '/.plugin', $decoded['plugin_directory']);
        assertSame(5, count($decoded['files']));

        exec('rm -rf ' . escapeshellarg($root));
    }

    /** A command that fails is named with the status it failed with. */
    #[Group('cli')]
    public function testAFailedCommandIsNamedWithItsExitStatus(): void
    {
        $root = $this->sourceRoot();

        $run = $this->runWithClaudeExiting(3, $root, ['execute' => ['true']]);

        assertSame(true, $run['error'] instanceof InvalidArgumentException);
        assertSame(
            sprintf('Command failed (exit 3): claude plugin marketplace add %s --scope user', escapeshellarg($root . '/.plugin')),
            $run['error']?->getMessage(),
        );

        exec('rm -rf ' . escapeshellarg($root));
    }

    /** The scope the caller asked for reaches both commands and the report. */
    #[Group('cli')]
    public function testTheRequestedScopeReachesTheCommands(): void
    {
        $root = $this->sourceRoot();

        ob_start();
        (new PluginCommand())->run('install-agent-plugin', [], ['scope' => ['project'], 'json' => ['true']], $this->contextFor($root));
        $preview = json_decode(trim((string) ob_get_clean()), true, 8, JSON_THROW_ON_ERROR);

        assertSame('project', $preview['scope']);
        assertSame(true, str_contains($preview['commands'][1], '--scope project'));

        exec('rm -rf ' . escapeshellarg($root));
    }

    /** The container image is the one asked for, and the default otherwise. */
    #[Group('cli')]
    public function testTheContainerImageIsTheOneAskedFor(): void
    {
        $named = $this->temporaryPath('knossos-plugin-image');
        $default = $this->temporaryPath('knossos-plugin-image-default');

        assertSame('ghcr.io/me/knossos:2', $this->emitJson($named, ['image' => ['ghcr.io/me/knossos:2']])['image']);
        assertSame(true, str_contains((string) file_get_contents($named . '/hooks/scripts/session-brief.sh'), 'ghcr.io/me/knossos:2'));

        assertSame('knossos-mcp:dev', $this->emitJson($default, [])['image']);
        assertSame(true, str_contains((string) file_get_contents($default . '/hooks/scripts/session-brief.sh'), 'knossos-mcp:dev'));

        exec('rm -rf ' . escapeshellarg($named) . ' ' . escapeshellarg($default));
    }

    /**
     * An emit says whether it replaced what was there, names the command that
     * installs it, and says when --execute was beside the point.
     */
    #[Group('cli')]
    public function testTheEmitProseSaysWhatItDidAndWhatToRunNext(): void
    {
        $out = $this->temporaryPath('knossos-plugin-prose');

        $first = $this->emitProse($out, []);
        assertSame(false, str_contains($first, 'already existed'));
        assertSame(false, str_contains($first, '--execute is not needed'));
        assertSame(true, str_contains($first, sprintf('Install it with: claude plugin marketplace add %s --scope user', escapeshellarg($out))));

        $second = $this->emitProse($out, ['execute' => ['true']]);
        assertSame(true, str_contains($second, 'The target directory already existed; its files were replaced.'));
        assertSame(true, str_contains($second, '--execute writes directly; --execute is not needed here and was ignored.') || str_contains($second, '--execute is not needed here and was ignored.'));

        exec('rm -rf ' . escapeshellarg($out));
    }

    /** A directory that already exists does not stop the ones after it being created. */
    #[Group('cli')]
    public function testDirectoriesAfterAnExistingOneAreStillCreated(): void
    {
        $out = $this->temporaryPath('knossos-plugin-dirs');
        mkdir($out . '/.claude-plugin', 0o755, true);

        $this->emitProse($out, []);

        foreach (['/.claude-plugin', '/hooks', '/hooks/scripts', '/skills', '/skills/knossos'] as $directory) {
            assertSame(true, is_dir($out . $directory), $directory);
        }
        assertSame('0755', substr(sprintf('%o', fileperms($out . '/hooks/scripts')), -4));

        exec('rm -rf ' . escapeshellarg($out));
    }

    /** An emit replaces a hand-edited descriptor, because the plugin it describes is regenerated. */
    #[Group('cli')]
    public function testTheEmittedDescriptorReplacesAHandEditedOne(): void
    {
        $out = $this->temporaryPath('knossos-plugin-descriptor');
        mkdir($out . '/.claude-plugin', 0o755, true);
        file_put_contents($out . '/.claude-plugin/marketplace.json', '{"mine":true}');

        $this->emitProse($out, []);

        $descriptor = (string) file_get_contents($out . '/.claude-plugin/marketplace.json');
        assertSame(false, str_contains($descriptor, 'mine'));
        assertSame(true, str_contains($descriptor, '"name": "knossos"'));

        exec('rm -rf ' . escapeshellarg($out));
    }

    /**
     * An emit that fails at the hook takes the manifest and descriptor it had
     * already written with it, rather than leaving a half-written plugin.
     */
    #[Group('cli')]
    public function testAFailedEmitRemovesTheManifestAndDescriptorItWrote(): void
    {
        $root = $this->sourceRoot();
        $out = $this->temporaryPath('knossos-plugin-latefail');
        // The hook is written last; a directory in its place fails that write
        // after the manifest and descriptor are already on disk.
        mkdir($out . '/hooks/scripts/session-brief.sh', 0o755, true);

        try {
            (new PluginCommand())->run('install-agent-plugin', [], ['out' => [$out], 'data' => ['/srv/data']], $this->contextFor($root));
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // Expected: the hook cannot be written over a directory.
        }

        assertSame(false, file_exists($out . '/.claude-plugin/plugin.json'));
        assertSame(false, file_exists($out . '/.claude-plugin/marketplace.json'));
        assertSame(false, file_exists($out . '/hooks/hooks.json'));

        exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($out));
    }

    /** The same failure restores a manifest and descriptor that were already there. */
    #[Group('cli')]
    public function testAFailedEmitRestoresTheManifestAndDescriptorItReplaced(): void
    {
        $root = $this->sourceRoot();
        $out = $this->temporaryPath('knossos-plugin-restore');
        mkdir($out . '/hooks/scripts/session-brief.sh', 0o755, true);
        mkdir($out . '/.claude-plugin', 0o755, true);
        file_put_contents($out . '/.claude-plugin/plugin.json', '{"mine":"manifest"}');
        file_put_contents($out . '/.claude-plugin/marketplace.json', '{"mine":"descriptor"}');

        try {
            (new PluginCommand())->run('install-agent-plugin', [], ['out' => [$out], 'data' => ['/srv/data']], $this->contextFor($root));
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // Expected, as above.
        }

        assertSame('{"mine":"manifest"}', (string) file_get_contents($out . '/.claude-plugin/plugin.json'));
        assertSame('{"mine":"descriptor"}', (string) file_get_contents($out . '/.claude-plugin/marketplace.json'));

        exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($out));
    }

    /** The materialised manifest is indented, ends with a newline, and keeps its slashes. */
    #[Group('cli')]
    public function testTheMaterialisedManifestIsReadableJson(): void
    {
        $root = $this->sourceRoot();

        $this->runWithClaudeExiting(0, $root, ['execute' => ['true']]);

        $manifest = (string) file_get_contents($root . '/.plugin/.claude-plugin/plugin.json');
        assertSame(true, str_ends_with($manifest, "}\n"));
        assertSame(true, str_contains($manifest, "\n    \"name\""));
        assertSame(true, str_contains($manifest, 'https://github.com'));

        exec('rm -rf ' . escapeshellarg($root));
    }

    /**
     * A container emit, returning its JSON report.
     *
     * @param array<string, list<string>> $options
     * @return array<string, mixed>
     */
    private function emitJson(string $out, array $options): array
    {
        ob_start();
        try {
            (new PluginCommand())->run(
                'install-agent-plugin',
                [],
                ['out' => [$out], 'data' => ['/srv/data'], 'json' => ['true']] + $options,
                $this->context(),
            );
        } finally {
            $output = (string) ob_get_clean();
        }

        return json_decode(trim($output), true, 8, JSON_THROW_ON_ERROR);
    }

    /**
     * A container emit, returning its prose.
     *
     * @param array<string, list<string>> $options
     */
    private function emitProse(string $out, array $options): string
    {
        ob_start();
        try {
            (new PluginCommand())->run('install-agent-plugin', [], ['out' => [$out], 'data' => ['/srv/data']] + $options, $this->context());
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }
}
