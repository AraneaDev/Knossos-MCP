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
}
