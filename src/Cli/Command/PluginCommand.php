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

    /**
     * Where an install materialises the plugin, relative to the installation root.
     *
     * The install registers this directory as the marketplace, never the
     * installation root itself. Claude Code snapshots a local marketplace into
     * its plugin cache, so handing it the root copied the entire working tree,
     * `vendor/`, `node_modules/`, build caches, the graph database and any
     * `.mcp.json` along with it. A directory holding only the plugin's own
     * files copies only those, and carries nothing that could register a
     * second, unintended MCP server.
     */
    private const PLUGIN_DIRECTORY = '/.plugin';

    /** The directories a materialised plugin needs, in creation order. */
    private const DIRECTORIES = ['/.claude-plugin', '/hooks', '/hooks/scripts', '/skills', '/skills/knossos'];

    /** Copied verbatim from the installation root into a materialised plugin. */
    private const COPIES = ['/.claude-plugin/plugin.json', '/hooks/hooks.json', '/skills/knossos/SKILL.md'];

    /** Everything a materialised plugin directory contains, for reporting. */
    private const FILES = [
        '.claude-plugin/plugin.json',
        '.claude-plugin/marketplace.json',
        'hooks/hooks.json',
        'hooks/scripts/session-brief.sh',
        'skills/knossos/SKILL.md',
    ];

    /**
     * The marketplace descriptor, byte for byte as an install needs it.
     *
     * Generated rather than committed. A copy of this file at the root of the
     * public repository is what makes `claude plugin marketplace add
     * AraneaDev/Knossos-MCP` resolve, and the clone it resolves to has no
     * `vendor/`: its `bin/knossos` cannot run and the hook fails silent, so
     * that install produces nothing, forever. Absent, the same command fails
     * immediately with "Marketplace file not found", which is the whole point.
     */
    private const MARKETPLACE = <<<'JSON'
        {
          "name": "knossos",
          "owner": { "name": "AraneaDev", "url": "https://github.com/AraneaDev" },
          "description": "The labyrinth mapped once, so nobody has to wander it again.",
          "plugins": [
            {
              "name": "knossos",
              "source": "./",
              "description": "Session-start architecture orientation, plus a skill for when to ask the graph."
            }
          ]
        }
        JSON . "\n";

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
        $pluginDirectory = $root . self::PLUGIN_DIRECTORY;
        $commands = [
            sprintf('claude plugin marketplace add %s --scope %s', escapeshellarg($pluginDirectory), $scope),
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
                [
                    'scope' => $scope,
                    'plugin_directory' => $pluginDirectory,
                    'commands' => $commands,
                    'executed' => false,
                    'preview' => true,
                ],
                $json,
                implode(PHP_EOL, $commands) . PHP_EOL
                . sprintf('The first command needs %s, which --execute writes before running it.', $pluginDirectory) . PHP_EOL
                . 'Preview only. Re-run with --execute to apply.',
            );
            return 0;
        }
        // Only here, never on the preview path: a preview writes nothing at
        // all. The marketplace this install registers does not exist until now,
        // because it is derived from the installation rather than committed to
        // it; this is the one place that knows the root is an installation
        // which already runs the server.
        $this->materialise($root, $pluginDirectory, $this->read($root . '/hooks/scripts/session-brief.sh'));
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
            [
                'scope' => $scope,
                'plugin_directory' => $pluginDirectory,
                'files' => self::FILES,
                'commands' => $commands,
                'executed' => true,
            ],
            $json,
            sprintf('Installed the plugin from %s.', $pluginDirectory),
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
        $template = $this->read($root . '/hooks/scripts/session-brief-container.sh');
        $existed = $this->materialise(
            $root,
            $out,
            strtr($template, ['__KNOSSOS_IMAGE__' => $image, '__KNOSSOS_DATA__' => $data]),
        );

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
                'files' => self::FILES,
            ],
            $context->options->flag($options, 'json'),
            $message,
        );
    }

    /**
     * Write a self-contained plugin directory at $out, with $hook as its
     * session-start script.
     *
     * The one place either mode writes a plugin. The two differ only in that
     * script: an install from a checkout ships the hook that finds a binary on
     * the host, a containerised emit ships the one that runs `docker run`.
     * Everything else, the descriptor included, is the same five files.
     *
     * All-or-nothing: anything created before a mid-way failure is removed
     * before the exception propagates, so a failure never leaves a directory
     * that looks like a plugin but is missing pieces. Anything that was
     * already on disk before this call, whether that is $out itself or files
     * inside it, is left exactly as it was found.
     *
     * @return bool whether $out already existed before this call
     */
    private function materialise(string $root, string $out, string $hook): bool
    {
        $existed = file_exists($out);
        $createdDirectories = [];
        $createdFiles = [];
        try {
            foreach (self::DIRECTORIES as $directory) {
                $path = $out . $directory;
                if (is_dir($path)) {
                    continue;
                }
                if (!@mkdir($path, 0o755, true)) {
                    throw new InvalidArgumentException(sprintf('Unable to create %s.', $path));
                }
                $createdDirectories[] = $path;
            }
            foreach (self::COPIES as $relative) {
                $target = $out . $relative;
                $isNew = !file_exists($target);
                if (!@copy($root . $relative, $target)) {
                    throw new InvalidArgumentException(sprintf('Unable to copy %s.', $relative));
                }
                if ($isNew) {
                    $createdFiles[] = $target;
                }
            }
            // Generated, not copied. The descriptor is not committed, so a
            // fresh checkout legitimately has none to copy from, and a
            // materialise that depended on one would fail for every install.
            $descriptor = $out . '/.claude-plugin/marketplace.json';
            if ($this->writeDescriptor($descriptor, true)) {
                $createdFiles[] = $descriptor;
            }
            $hookPath = $out . '/hooks/scripts/session-brief.sh';
            $hookIsNew = !file_exists($hookPath);
            if (@file_put_contents($hookPath, $hook) === false) {
                throw new InvalidArgumentException(sprintf('Unable to write %s.', $hookPath));
            }
            if ($hookIsNew) {
                $createdFiles[] = $hookPath;
            }
            if (!@chmod($hookPath, 0o755)) {
                throw new InvalidArgumentException(sprintf('Unable to make %s executable.', $hookPath));
            }
        } catch (Throwable $error) {
            $this->rollbackEmit($out, $existed, $createdDirectories, $createdFiles);
            throw $error;
        }

        return $existed;
    }

    /**
     * Read a file the plugin is assembled from, or say which one was missing.
     *
     * An unreadable source is reported by name because the two candidates,
     * the local hook and the container template, are different installations'
     * problems: one means a broken checkout, the other an image built without
     * the container variant.
     */
    private function read(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Unable to read %s.', $path));
        }

        return $contents;
    }

    /**
     * Write the marketplace descriptor at $path, creating its directory.
     *
     * With $overwrite false an existing descriptor is left exactly as it is,
     * because a checkout may carry a hand-edited one and an install is not the
     * place to overwrite it. With $overwrite true the write is unconditional,
     * matching what the copy() it replaced did to a `--out` target.
     *
     * @return bool whether the file did not exist before this call
     */
    private function writeDescriptor(string $path, bool $overwrite): bool
    {
        $isNew = !file_exists($path);
        if (!$isNew && !$overwrite) {
            return false;
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true)) {
            throw new InvalidArgumentException(sprintf('Unable to create %s.', $directory));
        }
        if (@file_put_contents($path, self::MARKETPLACE) === false) {
            throw new InvalidArgumentException(sprintf('Unable to write %s.', $path));
        }
        return $isNew;
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
