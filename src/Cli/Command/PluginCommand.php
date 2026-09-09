<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;

/**
 * Installs the agent orientation plugin from this installation.
 *
 * Never from a public marketplace. A marketplace clone of this repository has
 * no `vendor/`, so its `bin/knossos` would not run, and the hook fails silent
 * by design: that install would produce nothing, forever, with nothing to
 * diagnose. Sourcing the plugin from a checkout that is already running the
 * server makes a serverless install impossible rather than merely discouraged.
 *
 * Its failure contract is the opposite of SessionCommand's. A bad invocation
 * here must throw, because a silent no-op would look exactly like a successful
 * install.
 */
final class PluginCommand implements CliCommand
{
    private const SCOPES = ['user', 'project', 'local'];
    private const DEFAULT_IMAGE = 'knossos-mcp:dev';

    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'install-agent-plugin';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['db', 'json', 'scope', 'execute', 'out', 'data', 'image'];
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $root = $context->installationRoot();
        $out = $context->options->single($options, 'out');
        if ($out !== null) {
            $this->emit($root, $out, $options, $context);
            return 0;
        }
        $scope = $context->options->single($options, 'scope') ?? 'user';
        if (!in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('scope must be one of: ' . implode(', ', self::SCOPES) . '.');
        }
        $commands = [
            sprintf('claude plugin marketplace add %s --scope %s', escapeshellarg($root), $scope),
            sprintf('claude plugin install knossos@knossos --scope %s --yes', $scope),
        ];
        if (!$context->options->flag($options, 'execute')) {
            echo implode(PHP_EOL, $commands) . PHP_EOL
                . 'Preview only. Re-run with --execute to apply.' . PHP_EOL;
            return 0;
        }
        foreach ($commands as $line) {
            $status = 0;
            passthru($line, $status);
            if ($status !== 0) {
                throw new InvalidArgumentException(sprintf('Command failed (exit %d): %s', $status, $line));
            }
        }
        return 0;
    }

    /**
     * Write a self-contained plugin directory for a containerised installation.
     *
     * @param array<string, list<string>> $options
     */
    private function emit(string $root, string $out, array $options, CliCommandContext $context): void
    {
        $data = $context->options->single($options, 'data');
        if ($data === null || $data === '') {
            throw new InvalidArgumentException(
                'A containerised install needs --data=HOSTPATH, the host path of the Knossos data volume. ' .
                'A process inside the container cannot discover it.',
            );
        }
        $image = $context->options->single($options, 'image') ?? self::DEFAULT_IMAGE;
        foreach (['/.claude-plugin', '/hooks', '/hooks/scripts', '/skills/knossos'] as $directory) {
            if (!is_dir($out . $directory) && !mkdir($out . $directory, 0o755, true) && !is_dir($out . $directory)) {
                throw new InvalidArgumentException(sprintf('Unable to create %s.', $out . $directory));
            }
        }
        $copies = [
            '/.claude-plugin/plugin.json',
            '/.claude-plugin/marketplace.json',
            '/hooks/hooks.json',
            '/skills/knossos/SKILL.md',
        ];
        foreach ($copies as $relative) {
            if (!copy($root . $relative, $out . $relative)) {
                throw new InvalidArgumentException(sprintf('Unable to copy %s.', $relative));
            }
        }
        $template = (string) file_get_contents($root . '/hooks/scripts/session-brief-container.sh');
        $script = strtr($template, ['__KNOSSOS_IMAGE__' => $image, '__KNOSSOS_DATA__' => $data]);
        if (file_put_contents($out . '/hooks/scripts/session-brief.sh', $script) === false) {
            throw new InvalidArgumentException('Unable to write the hook script.');
        }
        chmod($out . '/hooks/scripts/session-brief.sh', 0o755);
        echo sprintf(
            'Wrote a container plugin to %s.%sInstall it with: claude plugin marketplace add %s --scope user%s',
            $out,
            PHP_EOL,
            escapeshellarg($out),
            PHP_EOL,
        );
    }
}
