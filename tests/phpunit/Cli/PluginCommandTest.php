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
        return new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            ':memory:',
        );
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

        exec('rm -rf ' . escapeshellarg($out));
    }
}
