<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Throwable;

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
        $json = $context->options->flag($options, 'json');
        if (!$context->options->flag($options, 'execute')) {
            // The commands themselves, as a list, because that is the only
            // thing a preview has to say and the only thing a caller could act
            // on: a script that wants to run them, or check them, needs them
            // one per element rather than glued into a paragraph it would have
            // to parse back apart.
            $context->output(
                ['scope' => $scope, 'commands' => $commands, 'executed' => false, 'preview' => true],
                $json,
                implode(PHP_EOL, $commands) . PHP_EOL . 'Preview only. Re-run with --execute to apply.',
            );
            return 0;
        }
        foreach ($commands as $line) {
            $status = 0;
            passthru($line, $status);
            if ($status !== 0) {
                throw new InvalidArgumentException(sprintf('Command failed (exit %d): %s', $status, $line));
            }
        }
        // After passthru(), not instead of it. The installer writes to the
        // inherited streams as it runs, so under --json its own output has
        // already gone to STDOUT and this object cannot be the whole of what a
        // caller reads. It is still worth emitting: it is the machine-readable
        // record of what was run, on the last line, where a caller that keeps
        // only the tail finds it.
        $context->output(
            ['scope' => $scope, 'commands' => $commands, 'executed' => true],
            $json,
            'Installed the plugin.',
        );
        return 0;
    }

    /**
     * Write a self-contained plugin directory for a containerised installation.
     *
     * All-or-nothing: anything created before a mid-way failure is removed
     * before the exception propagates, so a failed emit never leaves a
     * directory that looks like a plugin but is missing pieces. Anything that
     * was already on disk before this call, whether that is the `--out`
     * directory itself or files inside it, is left exactly as it was found.
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
        $existed = file_exists($out);
        $createdDirectories = [];
        $createdFiles = [];
        try {
            foreach (['/.claude-plugin', '/hooks', '/hooks/scripts', '/skills', '/skills/knossos'] as $directory) {
                $path = $out . $directory;
                if (is_dir($path)) {
                    continue;
                }
                if (!@mkdir($path, 0o755, true)) {
                    throw new InvalidArgumentException(sprintf('Unable to create %s.', $path));
                }
                $createdDirectories[] = $path;
            }
            $copies = [
                '/.claude-plugin/plugin.json',
                '/.claude-plugin/marketplace.json',
                '/hooks/hooks.json',
                '/skills/knossos/SKILL.md',
            ];
            foreach ($copies as $relative) {
                $target = $out . $relative;
                $isNew = !file_exists($target);
                if (!@copy($root . $relative, $target)) {
                    throw new InvalidArgumentException(sprintf('Unable to copy %s.', $relative));
                }
                if ($isNew) {
                    $createdFiles[] = $target;
                }
            }
            $template = (string) file_get_contents($root . '/hooks/scripts/session-brief-container.sh');
            $script = strtr($template, ['__KNOSSOS_IMAGE__' => $image, '__KNOSSOS_DATA__' => $data]);
            $hook = $out . '/hooks/scripts/session-brief.sh';
            $hookIsNew = !file_exists($hook);
            if (file_put_contents($hook, $script) === false) {
                throw new InvalidArgumentException('Unable to write the hook script.');
            }
            if ($hookIsNew) {
                $createdFiles[] = $hook;
            }
            if (!@chmod($hook, 0o755)) {
                throw new InvalidArgumentException('Unable to make the hook script executable.');
            }
        } catch (Throwable $error) {
            $this->rollbackEmit($out, $existed, $createdDirectories, $createdFiles);
            throw $error;
        }
        $message = sprintf('Wrote a container plugin to %s.', $out);
        if ($existed) {
            $message .= PHP_EOL . 'The target directory already existed; its files were replaced.';
        }
        if ($context->options->flag($options, 'execute')) {
            $message .= PHP_EOL . '--out writes directly; --execute is not needed here and was ignored.';
        }
        $message .= PHP_EOL . sprintf('Install it with: claude plugin marketplace add %s --scope user', escapeshellarg($out));
        // The directory and what is now in it, relative to that directory: a
        // caller that wants to copy, mount or checksum the emitted plugin needs
        // the file list, and absolute paths would only repeat the prefix it
        // already has. `existed` is reported because it is the one fact the
        // prose carries that changes what the caller wrote to: an emit into an
        // existing directory replaced files that were already there.
        $context->output(
            [
                'out' => $out,
                'existed' => $existed,
                'image' => $image,
                'data' => $data,
                'files' => [
                    '.claude-plugin/plugin.json',
                    '.claude-plugin/marketplace.json',
                    'hooks/hooks.json',
                    'hooks/scripts/session-brief.sh',
                    'skills/knossos/SKILL.md',
                ],
            ],
            $context->options->flag($options, 'json'),
            $message,
        );
    }

    /**
     * Undo exactly what emit() created before it failed.
     *
     * When the `--out` target did not exist at all beforehand, everything
     * under it is ours, so the whole tree is removed. Otherwise only the
     * specific files and directories created during this call are removed,
     * in reverse order, leaving anything that predates this call untouched.
     *
     * @param list<string> $createdDirectories
     * @param list<string> $createdFiles
     */
    private function rollbackEmit(string $out, bool $outExisted, array $createdDirectories, array $createdFiles): void
    {
        if (!$outExisted) {
            $this->removeTree($out);
            return;
        }
        foreach ($createdFiles as $file) {
            @unlink($file);
        }
        foreach (array_reverse($createdDirectories) as $directory) {
            @rmdir($directory);
        }
    }

    /** Recursively remove a path this command created, best-effort. */
    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
