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
    public function testExecuteGeneratesTheMarketplaceDescriptorWhenItIsAbsent(): void
    {
        // `claude` is stubbed by a script that exits non-zero and prints
        // nothing, so the install fails at its first command: this test can
        // never register a marketplace on the machine running it. The
        // descriptor is written before that first command, which is the point.
        $root = $this->temporaryPath('knossos-plugin-root');
        $bin = $this->temporaryPath('knossos-plugin-bin');
        mkdir($root, 0o755, true);
        mkdir($bin, 0o755, true);
        file_put_contents($bin . '/claude', "#!/bin/sh\nexit 1\n");
        chmod($bin . '/claude', 0o755);
        $path = (string) getenv('PATH');

        putenv('PATH=' . $bin);
        try {
            ob_start();
            try {
                (new PluginCommand())->run(
                    'install-agent-plugin',
                    [],
                    ['execute' => ['true']],
                    $this->contextFor($root),
                );
                self::fail('Expected the stubbed claude to fail the install.');
            } catch (InvalidArgumentException) {
                // Expected: the stub exits 1, so nothing is really installed.
            } finally {
                ob_get_clean();
            }
        } finally {
            putenv('PATH=' . $path);
        }

        $descriptor = $root . '/.claude-plugin/marketplace.json';
        assertSame(true, is_file($descriptor));
        $decoded = json_decode((string) file_get_contents($descriptor), true, 8, JSON_THROW_ON_ERROR);
        // The same top-level keys the committed descriptor carried, so a
        // generated one is still a marketplace file Claude Code can read.
        assertSame(['name', 'owner', 'description', 'plugins'], array_keys($decoded));
        assertSame('knossos', $decoded['name']);
        assertSame(1, count($decoded['plugins']));
        assertSame('knossos', $decoded['plugins'][0]['name']);
        // A local source: the whole reason the install points at a checkout.
        assertSame('./', $decoded['plugins'][0]['source']);

        exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($bin));
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
}
