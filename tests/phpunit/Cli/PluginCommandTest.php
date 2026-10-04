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
    /** Everything a host install writes, relative to the plugin directory, sorted. */
    private const INSTALLED = [
        '.claude-plugin/marketplace.json',
        '.claude-plugin/plugin.json',
        'hooks/hooks.json',
        'hooks/lib/activity.ts',
        'hooks/lib/agent.ts',
        'hooks/lib/alerts.ts',
        'hooks/lib/band.ts',
        'hooks/lib/baseline.ts',
        'hooks/lib/boundaries.ts',
        'hooks/lib/branch.ts',
        'hooks/lib/cards.ts',
        'hooks/lib/changes.ts',
        'hooks/lib/churn.ts',
        'hooks/lib/cycles.ts',
        'hooks/lib/diagram.ts',
        'hooks/lib/diff.ts',
        'hooks/lib/envelopes.ts',
        'hooks/lib/files.ts',
        'hooks/lib/finder.ts',
        'hooks/lib/flash.ts',
        'hooks/lib/hover.ts',
        'hooks/lib/layout.ts',
        'hooks/lib/live.ts',
        'hooks/lib/notes.ts',
        'hooks/lib/overview.ts',
        'hooks/lib/palette.ts',
        'hooks/lib/paths.ts',
        'hooks/lib/raster.ts',
        'hooks/lib/rings.ts',
        'hooks/lib/route.ts',
        'hooks/lib/rows.ts',
        'hooks/lib/scheduler.ts',
        'hooks/lib/sparkline.ts',
        'hooks/lib/tiles.ts',
        'hooks/lib/views.ts',
        'hooks/mod/state.ts',
        'hooks/register.tsx',
        'hooks/scripts/knossos-run.sh',
        'hooks/scripts/lib.sh',
        'hooks/scripts/session-brief.sh',
        'skills/graph/SKILL.md',
        'types/index.d.ts',
    ];

    /** The mod's own sources, as an installation root holds them: what the stand-in roots copy. */
    private const MOD_SOURCES = [
        '/hooks/register.tsx',
        '/hooks/lib/activity.ts',
        '/hooks/lib/agent.ts',
        '/hooks/lib/alerts.ts',
        '/hooks/lib/band.ts',
        '/hooks/lib/baseline.ts',
        '/hooks/lib/boundaries.ts',
        '/hooks/lib/branch.ts',
        '/hooks/lib/cards.ts',
        '/hooks/lib/changes.ts',
        '/hooks/lib/churn.ts',
        '/hooks/lib/cycles.ts',
        '/hooks/lib/diagram.ts',
        '/hooks/lib/diff.ts',
        '/hooks/lib/envelopes.ts',
        '/hooks/lib/files.ts',
        '/hooks/lib/finder.ts',
        '/hooks/lib/flash.ts',
        '/hooks/lib/hover.ts',
        '/hooks/lib/layout.ts',
        '/hooks/lib/live.ts',
        '/hooks/lib/notes.ts',
        '/hooks/lib/overview.ts',
        '/hooks/lib/palette.ts',
        '/hooks/lib/paths.ts',
        '/hooks/lib/raster.ts',
        '/hooks/lib/rings.ts',
        '/hooks/lib/route.ts',
        '/hooks/lib/rows.ts',
        '/hooks/lib/scheduler.ts',
        '/hooks/lib/sparkline.ts',
        '/hooks/lib/tiles.ts',
        '/hooks/lib/views.ts',
        '/hooks/mod/state.ts',
    ];

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
        assertSame(true, in_array('hooks/scripts/knossos-run.sh', $decoded['files'], true));
        assertSame(true, in_array('skills/graph/SKILL.md', $decoded['files'], true));
        // The container scripts are standalone, so no shared library is shipped for them.
        assertSame(false, in_array('hooks/scripts/lib.sh', $decoded['files'], true));
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
        assertSame(true, is_file($out . '/skills/graph/SKILL.md'));

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
            '/hooks/scripts/knossos-run-container.sh',
            ...self::MOD_SOURCES,
            '/types/index.d.ts',
            '/skills/graph/SKILL.md',
        ] as $relative) {
            if (!is_dir(dirname($root . $relative))) {
                mkdir(dirname($root . $relative), 0o755, true);
            }
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
        // and skills/graph all succeed and ARE tracked; the first copy() then
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

    #[Group('cli')]
    public function testSpecFilesNeverReachAnInstalledPlugin(): void
    {
        $root = $this->sourceRoot();
        try {
            // A spec sitting beside the sources it tests must stay behind.
            file_put_contents($root . '/hooks/lib/band.spec.ts', '// spec');
            file_put_contents($root . '/hooks/lib/band.test.ts', '// test');
            file_put_contents($root . '/hooks/tsconfig.json', '{}');

            $this->runWithStubbedClaude($root, ['execute' => ['true']]);

            foreach ($this->treeOf($root . '/.plugin') as $file) {
                assertSame(false, str_contains($file, '.spec.') || str_contains($file, '.test.') || str_contains($file, 'tsconfig'));
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    #[Group('cli')]
    public function testContainerEmitWritesTheRunWrapperWithImageAndDataSubstituted(): void
    {
        $out = $this->temporaryPath('knossos-plugin-run');
        try {
            ob_start();
            try {
                (new PluginCommand())->run(
                    'install-agent-plugin',
                    [],
                    ['out' => [$out], 'data' => ['/srv/knossos-data'], 'image' => ['ghcr.io/me/knossos:9']],
                    $this->context(),
                );
            } finally {
                ob_get_clean();
            }

            $wrapper = (string) file_get_contents($out . '/hooks/scripts/knossos-run.sh');
            assertSame(true, str_contains($wrapper, 'docker run'));
            assertSame(true, str_contains($wrapper, 'ghcr.io/me/knossos:9'));
            assertSame(true, str_contains($wrapper, '/srv/knossos-data'));
            assertSame(false, str_contains($wrapper, '__KNOSSOS_'));
            assertSame(false, file_exists($out . '/hooks/scripts/lib.sh'));
        } finally {
            exec('rm -rf ' . escapeshellarg($out));
        }
    }

    #[Group('cli')]
    public function testInstalledScriptsAreExecutableAndTheLibraryIsNot(): void
    {
        $root = $this->sourceRoot();
        $out = $this->temporaryPath('knossos-plugin-modes');
        try {

            $this->runWithStubbedClaude($root, ['execute' => ['true']]);
            ob_start();
            try {
                (new PluginCommand())->run('install-agent-plugin', [], ['out' => [$out], 'data' => ['/srv/d']], $this->contextFor($root));
            } finally {
                ob_get_clean();
            }

            foreach ([$root . '/.plugin', $out] as $directory) {
                foreach (['session-brief.sh', 'knossos-run.sh'] as $script) {
                    assertSame('0755', substr(sprintf('%o', fileperms($directory . '/hooks/scripts/' . $script)), -4));
                }
            }
            assertSame('0644', substr(sprintf('%o', fileperms($root . '/.plugin/hooks/scripts/lib.sh')), -4));
        } finally {
            exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($out));
        }
    }

    /**
     * Install, then run an installed script against a stub binary.
     *
     * The stub prints the data location it was handed and its arguments, so
     * what a person's hook would really read is what is asserted. The
     * variable is removed from the child's environment so the baked value is
     * the only source.
     *
     * @param array<string, list<string>> $options
     * @return string the stub's first line
     */
    private function runInstalledScript(string $script, array $options, ?string $installerEnv): string
    {
        $root = $this->sourceRoot();
        try {
            $previous = getenv('KNOSSOS_DATA_DIR');
            putenv($installerEnv === null ? 'KNOSSOS_DATA_DIR' : 'KNOSSOS_DATA_DIR=' . $installerEnv);
            try {
                $this->runWithStubbedClaude($root, ['execute' => ['true']] + $options);
            } finally {
                putenv($previous === false ? 'KNOSSOS_DATA_DIR' : 'KNOSSOS_DATA_DIR=' . $previous);
            }
            return $this->runStubbed($root, $script);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /** Run an installed script from $root against a stub binary and return its trimmed output. */
    private function runStubbed(string $root, string $script): string
    {
        $stub = $root . '/stub-knossos';
        file_put_contents($stub, <<<'SH'
            #!/bin/sh
            printf 'DATA=%s ARGS=%s\n' "${KNOSSOS_DATA_DIR-}" "$*"
            SH . "\n");
        chmod($stub, 0o755);
        $command = sprintf(
            'cd %1$s && env -u KNOSSOS_DATA_DIR -u KNOSSOS_ROOTS_FILE KNOSSOS_BIN=%2$s CLAUDE_PROJECT_DIR=%1$s sh %3$s %4$s 2>&1',
            escapeshellarg($root),
            escapeshellarg($stub),
            escapeshellarg($root . '/.plugin/hooks/scripts/' . $script),
            $script === 'knossos-run.sh' ? 'turn-brief ' . escapeshellarg($root) : '',
        );

        return trim((string) shell_exec($command));
    }

    #[Group('cli')]
    public function testInstalledSessionBriefReadsTheDataDirectoryGivenOnTheCommandLine(): void
    {
        $line = $this->runInstalledScript('session-brief.sh', ['data-dir' => ['/x y/data']], null);

        assertSame(true, str_starts_with($line, 'DATA=/x y/data ARGS=session-brief '));
    }

    #[Group('cli')]
    public function testInstalledSessionBriefSurvivesASingleQuoteInTheDataDirectory(): void
    {
        $line = $this->runInstalledScript('session-brief.sh', ['data-dir' => ["/it's/data"]], null);

        assertSame(true, str_starts_with($line, "DATA=/it's/data ARGS=session-brief "));
    }

    #[Group('cli')]
    public function testInstalledSessionBriefTakesTheInstallersEnvironmentWhenNoOptionIsGiven(): void
    {
        $line = $this->runInstalledScript('session-brief.sh', [], '/from/env');

        assertSame(true, str_starts_with($line, 'DATA=/from/env ARGS=session-brief '));
    }

    #[Group('cli')]
    public function testInstalledSessionBriefLeavesTheDataDirectoryEmptyWithoutAnySource(): void
    {
        $line = $this->runInstalledScript('session-brief.sh', [], null);

        assertSame(true, str_starts_with($line, 'DATA= ARGS=session-brief '));
    }

    #[Group('cli')]
    public function testInstalledRunWrapperReadsTheBakedDataDirectory(): void
    {
        $line = $this->runInstalledScript('knossos-run.sh', ['data-dir' => ["/it's/data"]], null);

        assertSame(true, str_starts_with($line, "DATA=/it's/data ARGS=turn-brief "));
    }

    #[Group('cli')]
    public function testTheDataDirectoryIsReportedInTheJsonOutput(): void
    {
        $root = $this->sourceRoot();
        $bin = $this->temporaryPath('knossos-plugin-bin');
        try {
            mkdir($bin, 0o755, true);
            file_put_contents($bin . '/claude', "#!/bin/sh\nexit 0\n");
            chmod($bin . '/claude', 0o755);
            $path = (string) getenv('PATH');
            putenv('PATH=' . $bin . ':' . $path);
            ob_start();
            try {
                (new PluginCommand())->run(
                    'install-agent-plugin',
                    [],
                    ['execute' => ['true'], 'json' => ['true'], 'data-dir' => ['/srv/graph']],
                    $this->contextFor($root),
                );
            } finally {
                $output = (string) ob_get_clean();
                putenv('PATH=' . $path);
            }
            $lines = array_values(array_filter(explode("\n", trim($output))));
            $decoded = json_decode((string) end($lines), true, 8, JSON_THROW_ON_ERROR);

            assertSame('/srv/graph', $decoded['data_dir']);
        } finally {
            exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($bin));
        }
    }

    #[Group('cli')]
    public function testADataDirectoryWithALineBreakOrNulIsRejected(): void
    {
        $root = $this->sourceRoot();
        try {

            foreach (["\n", "\r", "\0"] as $character) {
                try {
                    (new PluginCommand())->run('install-agent-plugin', [], ['execute' => ['true'], 'data-dir' => ['/a' . $character . 'b']], $this->contextFor($root));
                    self::fail('Expected an InvalidArgumentException.');
                } catch (InvalidArgumentException $error) {
                    assertSame(true, str_contains($error->getMessage(), 'data-dir'));
                }
            }
            assertSame(false, file_exists($root . '/.plugin'));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * Emit a container plugin with a hostile --data and run its installed
     * script with a stub `docker` first on PATH that prints its arguments.
     *
     * @return array{output: string, marker: bool}
     */
    private function runHostileContainerScript(string $script, string $subcommandArguments): array
    {
        $root = $this->sourceRoot();
        $out = $this->temporaryPath('knossos-plugin-hostile');
        $bin = $this->temporaryPath('knossos-plugin-bin');
        $marker = $this->temporaryPath('knossos-marker');
        $hostile = '/a/$(touch ' . $marker . ')"q\'s`touch ' . $marker . '`\\z';
        try {
            ob_start();
            try {
                (new PluginCommand())->run(
                    'install-agent-plugin',
                    [],
                    ['out' => [$out], 'data' => [$hostile], 'image' => ["img/it's/$(touch " . $marker . '):1']],
                    $this->contextFor($root),
                );
            } finally {
                ob_get_clean();
            }
            mkdir($bin, 0o755, true);
            file_put_contents($bin . '/docker', <<<'SH'
                #!/bin/sh
                for a in "$@"; do printf 'ARG=%s\n' "$a"; done
                SH . "\n");
            chmod($bin . '/docker', 0o755);
            $command = sprintf(
                'cd %1$s && PATH=%2$s:$PATH CLAUDE_PROJECT_DIR=%1$s sh %3$s %4$s 2>&1',
                escapeshellarg($root),
                escapeshellarg($bin),
                escapeshellarg($out . '/hooks/scripts/' . $script),
                $subcommandArguments,
            );
            $output = (string) shell_exec($command);

            return ['output' => $output . "\nHOSTILE=" . $hostile, 'marker' => file_exists($marker)];
        } finally {
            exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($out) . ' ' . escapeshellarg($bin) . ' ' . escapeshellarg($marker));
        }
    }

    #[Group('cli')]
    public function testHostileValuesReachTheContainerSessionBriefVerbatim(): void
    {
        $result = $this->runHostileContainerScript('session-brief.sh', '');

        $hostile = substr(strstr($result['output'], 'HOSTILE=') ?: '', 8);
        assertSame(true, $hostile !== '');
        assertSame(true, str_contains($result['output'], 'ARG=' . $hostile . ':/data'));
        assertSame(true, str_contains($result['output'], "ARG=img/it's/$(touch "));
        assertSame(false, $result['marker']);
    }

    #[Group('cli')]
    public function testHostileValuesReachTheContainerRunWrapperVerbatim(): void
    {
        $root = sys_get_temp_dir();
        $result = $this->runHostileContainerScript('knossos-run.sh', 'turn-brief ' . escapeshellarg($root));

        $hostile = substr(strstr($result['output'], 'HOSTILE=') ?: '', 8);
        assertSame(true, $hostile !== '');
        assertSame(true, str_contains($result['output'], 'ARG=' . $hostile . ':/data'));
        assertSame(true, str_contains($result['output'], "ARG=img/it's/$(touch "));
        assertSame(false, $result['marker']);
    }

    #[Group('cli')]
    public function testARelativeDataDirectoryIsRejected(): void
    {
        $root = $this->sourceRoot();
        try {
            foreach ([['data-dir' => ['rel/data']], []] as $options) {
                $previous = getenv('KNOSSOS_DATA_DIR');
                putenv('KNOSSOS_DATA_DIR=' . ($options === [] ? 'also/relative' : ''));
                try {
                    (new PluginCommand())->run('install-agent-plugin', [], ['execute' => ['true']] + $options, $this->contextFor($root));
                    self::fail('Expected an InvalidArgumentException.');
                } catch (InvalidArgumentException $error) {
                    assertSame(true, str_contains($error->getMessage(), 'absolute'));
                    assertSame(true, str_contains($error->getMessage(), $options === [] ? 'also/relative' : 'rel/data'));
                } finally {
                    putenv($previous === false ? 'KNOSSOS_DATA_DIR' : 'KNOSSOS_DATA_DIR=' . $previous);
                }
            }
            assertSame(false, file_exists($root . '/.plugin'));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    #[Group('cli')]
    public function testDataDirIsReportedAsIgnoredOnTheContainerRoute(): void
    {
        $out = $this->temporaryPath('knossos-plugin-ignored');
        try {
            $prose = $this->emitProse($out, ['data-dir' => ['/srv/graph']]);

            assertSame(true, str_contains($prose, '--data-dir only applies to a host install and was ignored; the container scripts read --data.'));
            assertSame(false, str_contains($this->emitProse($out, []), '--data-dir'));
        } finally {
            exec('rm -rf ' . escapeshellarg($out));
        }
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
            '/hooks/scripts/knossos-run.sh',
            '/hooks/scripts/knossos-run-container.sh',
            '/hooks/scripts/lib.sh',
            ...self::MOD_SOURCES,
            '/types/index.d.ts',
            '/skills/graph/SKILL.md',
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

        assertSame(
            array_map(static fn (string $file): string => '/' . $file, self::INSTALLED),
            $this->treeOf($root . '/.plugin'),
        );
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
        assertSame(self::INSTALLED, $decoded['files']);

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
        assertSame(true, str_contains($second, '--out writes directly; --execute is not needed here and was ignored.'));

        exec('rm -rf ' . escapeshellarg($out));
    }

    /** A directory that already exists does not stop the ones after it being created. */
    #[Group('cli')]
    public function testDirectoriesAfterAnExistingOneAreStillCreated(): void
    {
        $out = $this->temporaryPath('knossos-plugin-dirs');
        mkdir($out . '/.claude-plugin', 0o755, true);

        $this->emitProse($out, []);

        foreach (['/.claude-plugin', '/hooks', '/hooks/scripts', '/skills', '/skills/graph'] as $directory) {
            assertSame(true, is_dir($out . $directory), $directory);
        }
        assertSame('0755', substr(sprintf('%o', fileperms($out . '/hooks/scripts')), -4));

        exec('rm -rf ' . escapeshellarg($out));
    }

    /** An update over an earlier install leaves one skill, never the old directory beside the renamed one. */
    #[Group('cli')]
    public function testAnInstallRemovesTheSkillDirectoryAnEarlierReleaseLeft(): void
    {
        $root = $this->sourceRoot();
        $out = $root . '/.plugin';
        mkdir($out . '/skills/knossos', 0o755, true);
        file_put_contents($out . '/skills/knossos/SKILL.md', "---\nname: knossos\n---\n");
        file_put_contents($out . '/skills/knossos/extra.md', 'old');
        mkdir($out . '/skills/ask-the-graph', 0o755, true);
        file_put_contents($out . '/skills/ask-the-graph/SKILL.md', "---\nname: ask-the-graph\n---\n");

        $this->runWithStubbedClaude($root, ['execute' => ['true']]);

        assertSame(false, file_exists($out . '/skills/knossos'));
        assertSame(false, file_exists($out . '/skills/ask-the-graph'));
        assertSame(['graph'], array_values(array_diff(scandir($out . '/skills') ?: [], ['.', '..'])));
        assertSame(true, is_file($out . '/skills/graph/SKILL.md'));

        exec('rm -rf ' . escapeshellarg($root));
    }

    /** An emit names any directory, such as ~/.claude: skill directories of the person's own under the old names survive it. */
    #[Group('cli')]
    public function testAnEmitKeepsSkillDirectoriesUnderTheNamesEarlierReleasesUsed(): void
    {
        $out = $this->temporaryPath('knossos-plugin-claude-home');
        mkdir($out . '/skills/knossos', 0o755, true);
        file_put_contents($out . '/skills/knossos/SKILL.md', 'a skill of the person\'s own');
        mkdir($out . '/skills/ask-the-graph', 0o755, true);
        file_put_contents($out . '/skills/ask-the-graph/notes.md', 'theirs');

        $this->emitProse($out, []);

        assertSame('a skill of the person\'s own', (string) file_get_contents($out . '/skills/knossos/SKILL.md'));
        assertSame('theirs', (string) file_get_contents($out . '/skills/ask-the-graph/notes.md'));
        assertSame(
            ['ask-the-graph', 'graph', 'knossos'],
            array_values(array_diff(scandir($out . '/skills') ?: [], ['.', '..'])),
        );

        exec('rm -rf ' . escapeshellarg($out));
    }

    /** A failed install puts the old skill directory back exactly as it was. */
    #[Group('cli')]
    public function testAFailedInstallRestoresTheStaleSkillDirectory(): void
    {
        $root = $this->sourceRoot();
        $out = $root . '/.plugin';
        mkdir($out . '/skills/knossos', 0o755, true);
        file_put_contents($out . '/skills/knossos/SKILL.md', 'old skill');
        mkdir($out . '/skills/ask-the-graph', 0o755, true);
        // plugin.json is a directory, so the manifest cannot be written after the skills are retired.
        mkdir($out . '/.claude-plugin/plugin.json', 0o755, true);

        $this->runWithStubbedClaude($root, ['execute' => ['true']]);

        assertSame('old skill', (string) file_get_contents($out . '/skills/knossos/SKILL.md'));
        assertSame(['ask-the-graph', 'knossos'], array_values(array_diff(scandir($out . '/skills') ?: [], ['.', '..'])));

        exec('rm -rf ' . escapeshellarg($root));
    }

    /** An emit names any directory: a file of someone else's in it survives, whatever its name. */
    #[Group('cli')]
    public function testAnEmitNeverDeletesAFileItDidNotWrite(): void
    {
        $out = $this->temporaryPath('knossos-plugin-foreign');
        mkdir($out . '/hooks/lib', 0o755, true);
        mkdir($out . '/hooks/scripts', 0o755, true);
        file_put_contents($out . '/hooks/my-guard.sh', '# a hook of the person\'s own');
        file_put_contents($out . '/hooks/lib/trend.ts', 'export const mine = 1');
        file_put_contents($out . '/hooks/scripts/lib.sh', '# theirs');

        $this->emitProse($out, []);

        assertSame('# a hook of the person\'s own', (string) file_get_contents($out . '/hooks/my-guard.sh'));
        assertSame('export const mine = 1', (string) file_get_contents($out . '/hooks/lib/trend.ts'));
        assertSame('# theirs', (string) file_get_contents($out . '/hooks/scripts/lib.sh'));
        assertSame(true, is_file($out . '/hooks/lib/layout.ts'));

        exec('rm -rf ' . escapeshellarg($out));
    }

    /** An update deletes the files an earlier install left in the plugin's directories that this one no longer ships, and nothing else. */
    #[Group('cli')]
    public function testAnInstallDeletesOnlyTheFilesItNoLongerShipsInsideThePluginDirectories(): void
    {
        $root = $this->sourceRoot();
        $out = $root . '/.plugin';
        $outside = $this->temporaryPath('knossos-plugin-outside');
        mkdir($out . '/hooks/lib/kept', 0o755, true);
        mkdir($outside, 0o755, true);
        file_put_contents($out . '/hooks/lib/trend.ts', 'export const old = 1');
        file_put_contents($out . '/hooks/lib/kept/inner.ts', 'a subdirectory is not the plugin\'s');
        file_put_contents($out . '/notes.txt', 'the root is not one of the plugin\'s directories');
        file_put_contents($outside . '/keep.ts', 'outside');
        symlink($outside . '/keep.ts', $out . '/hooks/lib/linked.ts');

        $this->runWithStubbedClaude($root, ['execute' => ['true']]);

        assertSame(false, file_exists($out . '/hooks/lib/trend.ts'));
        assertSame(false, is_link($out . '/hooks/lib/linked.ts'));
        assertSame('outside', (string) file_get_contents($outside . '/keep.ts'));
        assertSame(true, is_file($out . '/hooks/lib/kept/inner.ts'));
        assertSame(true, is_file($out . '/notes.txt'));
        assertSame(true, is_file($out . '/hooks/lib/layout.ts'));

        exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($outside));
    }

    /** A plugin directory that is a link to somewhere else is never pruned: what is there is not the plugin's. */
    #[Group('cli')]
    public function testAnInstallNeverDeletesThroughALinkedDirectory(): void
    {
        $root = $this->sourceRoot();
        $outside = $this->temporaryPath('knossos-plugin-outside');
        mkdir($root . '/.plugin', 0o755, true);
        mkdir($outside, 0o755, true);
        file_put_contents($outside . '/other.d.ts', 'someone else\'s');
        symlink($outside, $root . '/.plugin/types');

        $this->runWithStubbedClaude($root, ['execute' => ['true']]);

        assertSame("someone else's", (string) file_get_contents($outside . '/other.d.ts'));

        exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($outside));
    }

    /** The host install prunes the same way: its tree is exactly what it ships. */
    #[Group('cli')]
    public function testAnInstallDeletesTheFilesItNoLongerShips(): void
    {
        $root = $this->sourceRoot();
        mkdir($root . '/.plugin/hooks/lib', 0o755, true);
        file_put_contents($root . '/.plugin/hooks/lib/trend.ts', 'export const old = 1');
        file_put_contents($root . '/.plugin/hooks/register.test.ts', 'a test is never shipped');

        $this->runWithStubbedClaude($root, ['execute' => ['true']]);

        assertSame(
            array_map(static fn (string $file): string => '/' . $file, self::INSTALLED),
            $this->treeOf($root . '/.plugin'),
        );

        exec('rm -rf ' . escapeshellarg($root));
    }

    /** A failed install puts back every file it deleted, with its bytes and its mode. */
    #[Group('cli')]
    public function testAFailedInstallRestoresTheFilesItDeleted(): void
    {
        $root = $this->sourceRoot();
        $out = $root . '/.plugin';
        mkdir($out . '/hooks/lib', 0o755, true);
        file_put_contents($out . '/hooks/lib/trend.ts', 'export const old = 1');
        chmod($out . '/hooks/lib/trend.ts', 0o600);
        // plugin.json is a directory, so the manifest cannot be written after the prune.
        mkdir($out . '/.claude-plugin/plugin.json', 0o755, true);

        $this->runWithStubbedClaude($root, ['execute' => ['true']]);

        assertSame('export const old = 1', (string) file_get_contents($out . '/hooks/lib/trend.ts'));
        assertSame('0600', substr(sprintf('%o', fileperms($out . '/hooks/lib/trend.ts')), -4));

        exec('rm -rf ' . escapeshellarg($root));
    }

    /** The plugin's slash command is `/knossos`, so no skill it ships may carry that name again. */
    #[Group('cli')]
    public function testNoSkillTheClaudePluginShipsSharesANameWithItsCommand(): void
    {
        $root = self::repositoryRoot();
        $mod = (string) file_get_contents($root . '/hooks/register.tsx');
        assertSame(1, preg_match("/command\\.register\\(\\{\\s*name: '([^']+)'/", $mod, $command));

        $names = [];
        foreach (glob($root . '/skills/*/SKILL.md') ?: [] as $file) {
            assertSame(1, preg_match('/^---\nname: (\S+)\n/', (string) file_get_contents($file), $found), $file);
            // The directory and the declared name must agree, or the person
            // sees one name in the picker and finds another on disk.
            assertSame(basename(dirname($file)), $found[1], $file);
            $names[] = $found[1];
        }

        assertSame(true, $names !== []);
        assertSame(false, in_array($command[1], $names, true), $command[1]);
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

    /** The container values sit inside single quotes in a line-oriented script: a line break or NUL is refused. */
    #[Group('cli')]
    public function testContainerDataAndImageWithALineBreakOrNulAreRejected(): void
    {
        foreach (['data', 'image'] as $option) {
            foreach (["\n", "\r", "\0"] as $character) {
                $out = $this->temporaryPath('knossos-plugin-break');
                $options = ['out' => [$out], 'data' => ['/srv/data']];
                $options[$option] = [($option === 'data' ? '/srv/' : 'img') . $character . 'x'];
                try {
                    (new PluginCommand())->run('install-agent-plugin', [], $options, $this->context());
                    self::fail('Expected an InvalidArgumentException for ' . $option);
                } catch (InvalidArgumentException $error) {
                    assertSame(true, str_contains($error->getMessage(), $option . ' must not contain'));
                }
                assertSame(false, file_exists($out));
            }
        }
    }

    /** A relative --data would reach `docker run -v` as a named volume, not the data directory. */
    #[Group('cli')]
    public function testARelativeContainerDataPathIsRejected(): void
    {
        $out = $this->temporaryPath('knossos-plugin-relative');
        try {
            (new PluginCommand())->run('install-agent-plugin', [], ['out' => [$out], 'data' => ['srv/data']], $this->context());
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $error) {
            assertSame(true, str_contains($error->getMessage(), 'absolute'));
            assertSame(true, str_contains($error->getMessage(), 'srv/data'));
        }
        assertSame(false, file_exists($out));
    }

    /** The preview names the graph the hooks will read, as data and as a sentence. */
    #[Group('cli')]
    public function testThePreviewNamesTheDataDirectory(): void
    {
        $previous = getenv('KNOSSOS_DATA_DIR');
        putenv('KNOSSOS_DATA_DIR');
        try {
            ob_start();
            (new PluginCommand())->run('install-agent-plugin', [], ['json' => ['true'], 'data-dir' => ['/srv/graph']], $this->context());
            $decoded = json_decode(trim((string) ob_get_clean()), true, 8, JSON_THROW_ON_ERROR);
            assertSame('/srv/graph', $decoded['data_dir']);

            ob_start();
            (new PluginCommand())->run('install-agent-plugin', [], ['data-dir' => ['/srv/graph']], $this->context());
            assertSame(true, str_contains((string) ob_get_clean(), "\nData directory: /srv/graph (the hooks read the graph there).\n"));

            ob_start();
            (new PluginCommand())->run('install-agent-plugin', [], ['json' => ['true']], $this->context());
            assertSame('', json_decode(trim((string) ob_get_clean()), true, 8, JSON_THROW_ON_ERROR)['data_dir']);

            ob_start();
            (new PluginCommand())->run('install-agent-plugin', [], [], $this->context());
            assertSame(true, str_contains((string) ob_get_clean(), "\nData directory: not set, the hooks derive the graph from the project path.\n"));
        } finally {
            putenv($previous === false ? 'KNOSSOS_DATA_DIR' : 'KNOSSOS_DATA_DIR=' . $previous);
        }
    }
}
