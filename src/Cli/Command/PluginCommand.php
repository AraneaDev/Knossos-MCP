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
     * @param string $version what this CLI is, written into the materialised
     *   manifest. Injected the way {@see MetaCommand} takes it rather than read
     *   from {@see \Knossos\Application}, so a command stays testable without
     *   the constant the whole application is versioned by.
     */
    public function __construct(private readonly string $version = '0.0.0') {}

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

    /** The directories this skill was installed under by earlier releases, which an install removes from the target. */
    private const STALE_SKILLS = ['/skills/knossos', '/skills/ask-the-graph'];

    /** The directories a materialised plugin needs, in creation order. */
    private const DIRECTORIES = ['/.claude-plugin', '/hooks', '/hooks/lib', '/hooks/scripts', '/skills', '/skills/graph', '/types'];

    /**
     * Copied verbatim from the installation root into a materialised plugin.
     *
     * The mod's library files are named one by one, never globbed, so a spec
     * or a tsconfig sitting beside them can never reach an installed plugin.
     */
    private const COPIES = [
        '/hooks/hooks.json',
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
        '/skills/graph/SKILL.md',
        '/types/index.d.ts',
    ];

    /** The manifest, read from the installation root and rewritten with this CLI's version. */
    private const MANIFEST = '/.claude-plugin/plugin.json';

    /** The files a materialised plugin has besides its scripts, for reporting. */
    private const STATIC_FILES = [
        '.claude-plugin/plugin.json',
        '.claude-plugin/marketplace.json',
        'hooks/hooks.json',
        'hooks/register.tsx',
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
        'skills/graph/SKILL.md',
        'types/index.d.ts',
    ];

    /** The one script that is sourced rather than executed, so it is not made executable. */
    private const LIBRARY_SCRIPT = 'hooks/scripts/lib.sh';

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
        return ['db', 'json', 'scope', 'execute', 'out', 'data', 'image', 'data-dir'];
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
        $dataDir = $this->dataDirectory($context->options->single($options, 'data-dir'));
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
                    'data_dir' => $dataDir,
                    'commands' => $commands,
                    'executed' => false,
                    'preview' => true,
                ],
                $json,
                implode(PHP_EOL, $commands) . PHP_EOL
                . $this->dataDirectoryLine($dataDir) . PHP_EOL
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
        $scripts = [];
        foreach (['session-brief.sh', 'knossos-run.sh'] as $script) {
            $scripts['hooks/scripts/' . $script] = $this->read($root . '/hooks/scripts/' . $script);
        }
        // Sourced by both scripts above, so it ships with them. The data
        // location is baked in here, once, because a hook cannot ask the MCP
        // server which directory it was started with.
        $scripts[self::LIBRARY_SCRIPT] = strtr(
            $this->read($root . '/' . self::LIBRARY_SCRIPT),
            ['__KNOSSOS_DATA_DIR__' => $this->singleQuoted($dataDir, 'data-dir')],
        );
        $this->materialise($root, $pluginDirectory, $scripts, prune: true);
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
                'data_dir' => $dataDir,
                'files' => $this->pluginFiles($scripts),
                'commands' => $commands,
                'executed' => true,
            ],
            $json,
            sprintf('Installed the plugin from %s.', $pluginDirectory) . PHP_EOL . $this->dataDirectoryLine($dataDir),
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
        if (!str_starts_with($data, '/')) {
            // `docker run -v` reads a relative source as a named volume, not a
            // directory, so the hooks would mount an empty graph.
            throw new InvalidArgumentException(sprintf('data must be an absolute host path, got "%s".', $data));
        }
        $image = $context->options->single($options, 'image') ?? self::DEFAULT_IMAGE;
        $scripts = [];
        // The container templates are standalone, so they ship under the host
        // names without the shared library the host scripts source.
        foreach (['session-brief' => 'session-brief', 'knossos-run' => 'knossos-run'] as $name => $installed) {
            $scripts['hooks/scripts/' . $installed . '.sh'] = strtr(
                $this->read($root . '/hooks/scripts/' . $name . '-container.sh'),
                [
                    '__KNOSSOS_IMAGE__' => $this->singleQuoted($image, 'image'),
                    '__KNOSSOS_DATA__' => $this->singleQuoted($data, 'data'),
                ],
            );
        }
        // Never pruned: `--out` names any directory (a person's own hooks directory among them), and what else is there is not the plugin's.
        $existed = $this->materialise($root, $out, $scripts, prune: false);

        $message = sprintf('Wrote a container plugin to %s.', $out);
        if ($existed) {
            $message .= PHP_EOL . 'The target directory already existed; its files were replaced.';
        }
        if ($context->options->flag($options, 'execute')) {
            $message .= PHP_EOL . '--out writes directly; --execute is not needed here and was ignored.';
        }
        if ($context->options->single($options, 'data-dir') !== null) {
            $message .= PHP_EOL . '--data-dir only applies to a host install and was ignored; the container scripts read --data.';
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
                'files' => $this->pluginFiles($scripts),
            ],
            $context->options->flag($options, 'json'),
            $message,
        );
    }

    /**
     * Write a self-contained plugin directory at $out, with $scripts as its
     * shell scripts.
     *
     * The one place either mode writes a plugin. The two differ only in those
     * scripts: an install from a checkout ships the ones that find a binary on
     * the host, a containerised emit ships the ones that run `docker run`.
     * Everything else, the descriptor and the mod's files included, is the
     * same in both.
     *
     * All-or-nothing: anything created before a mid-way failure is removed
     * before the exception propagates, so a failure never leaves a directory
     * that looks like a plugin but is missing pieces. Anything that was
     * already on disk before this call, whether that is $out itself or files
     * inside it, is left exactly as it was found.
     *
     * @param array<string, string> $scripts installed relative path => content
     * @param bool $prune whether to delete what an earlier install left that this one does not ship: only in the
     *   plugin directory this command manages, never in a directory `--out` names
     * @return bool whether $out already existed before this call
     */
    private function materialise(string $root, string $out, array $scripts, bool $prune): bool
    {
        $existed = file_exists($out);
        $createdDirectories = [];
        $createdFiles = [];
        // Contents of files that were already there and are about to be
        // replaced. Tracking only what was CREATED left an overwritten file
        // holding the new bytes after a later step failed, which is exactly
        // what this method's contract says cannot happen.
        $replacedFiles = [];
        $keepOriginal = static function (string $target) use (&$replacedFiles): void {
            if (!is_file($target)) {
                return;
            }
            $original = @file_get_contents($target);
            if ($original !== false) {
                $replacedFiles[$target] = $original;
            }
        };
        // Where a stale skill directory from an earlier install was moved to,
        // so a failure further down can put it back.
        $retired = [];
        // Files an earlier install left that this one no longer ships, as they were, so a failure can put them back.
        $pruned = [];
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
            $retired = $this->retireStaleSkills($out);
            foreach (self::COPIES as $relative) {
                $target = $out . $relative;
                $isNew = !file_exists($target);
                $keepOriginal($target);
                if (!@copy($root . $relative, $target)) {
                    throw new InvalidArgumentException(sprintf('Unable to copy %s.', $relative));
                }
                if ($isNew) {
                    $createdFiles[] = $target;
                }
            }
            if ($prune) {
                $this->pruneUnshipped($out, $scripts, $pruned);
            }
            // Read and rewritten rather than copied. Claude Code caches an
            // installed plugin by the version in this file, so a manifest
            // frozen at a placeholder can never be refreshed: `claude plugin
            // update` finds the cached version equal to the declared one and
            // reports there is nothing to do, leaving whatever was first
            // installed in place forever. Everything else in the file stays
            // the file's business.
            $manifest = $out . self::MANIFEST;
            $manifestIsNew = !file_exists($manifest);
            $keepOriginal($manifest);
            if (@file_put_contents($manifest, $this->versionedManifest($root . self::MANIFEST)) === false) {
                throw new InvalidArgumentException(sprintf('Unable to write %s.', $manifest));
            }
            if ($manifestIsNew) {
                $createdFiles[] = $manifest;
            }
            // Generated, not copied. The descriptor is not committed, so a
            // fresh checkout legitimately has none to copy from, and a
            // materialise that depended on one would fail for every install.
            $descriptor = $out . '/.claude-plugin/marketplace.json';
            $keepOriginal($descriptor);
            if ($this->writeDescriptor($descriptor, true)) {
                $createdFiles[] = $descriptor;
            }
            foreach ($scripts as $relative => $content) {
                $scriptPath = $out . '/' . $relative;
                $scriptIsNew = !file_exists($scriptPath);
                $keepOriginal($scriptPath);
                if (@file_put_contents($scriptPath, $content) === false) {
                    throw new InvalidArgumentException(sprintf('Unable to write %s.', $scriptPath));
                }
                if ($scriptIsNew) {
                    $createdFiles[] = $scriptPath;
                }
                if (!@chmod($scriptPath, $relative === self::LIBRARY_SCRIPT ? 0o644 : 0o755)) {
                    throw new InvalidArgumentException(sprintf('Unable to set the mode of %s.', $scriptPath));
                }
            }
        } catch (Throwable $error) {
            $this->restorePruned($pruned);
            foreach ($retired as $original => $parked) {
                @rename($parked, $original);
            }
            $this->rollbackEmit($out, $existed, $createdDirectories, $createdFiles, $replacedFiles);
            throw $error;
        }
        // Only now that nothing after it can fail is the old copy deleted.
        foreach ($retired as $parked) {
            $this->removeTree($parked);
        }

        return $existed;
    }

    /**
     * Delete the files in the plugin's own directories that this install does not ship.
     *
     * An earlier release's file left in `hooks/lib/` (a module since renamed or
     * removed) is still bundled by the engine and read by nothing, and a file
     * there is what a person debugging the plugin reads first. Only the files
     * directly inside the directories a plugin is made of are looked at, never
     * their subdirectories, never the target's own root, and never a
     * directory that is a link or resolves outside the target. Only the
     * plugin directory the host install manages is pruned: a `--out`
     * directory is whatever the person named, and a file of theirs in its
     * `hooks/` is not the plugin's to delete. Each file's bytes are kept so a later failure can
     * restore it.
     *
     * @param array<string, string> $scripts installed relative path => content
     * @param array<string, array{link: ?string, contents: string, mode: int}> $pruned filled with each deleted file as it
     *   was, as it is deleted, so a failure half way still knows what to put back
     */
    private function pruneUnshipped(string $out, array $scripts, array &$pruned): void
    {
        $shipped = array_flip($this->pluginFiles($scripts));
        $base = realpath($out);
        if ($base === false) {
            return;
        }
        foreach (self::DIRECTORIES as $directory) {
            $path = $out . $directory;
            $resolved = realpath($path);
            if (is_link($path) || $resolved === false || !str_starts_with($resolved . '/', $base . '/')) {
                continue;
            }
            foreach (scandir($path) ?: [] as $entry) {
                $file = $path . '/' . $entry;
                $relative = ltrim($directory, '/') . '/' . $entry;
                if ($entry === '.' || $entry === '..' || isset($shipped[$relative]) || (is_dir($file) && !is_link($file))) {
                    continue;
                }
                $was = $this->asFound($file);
                if (!@unlink($file)) {
                    throw new InvalidArgumentException(sprintf('Unable to remove the stale %s.', $file));
                }
                $pruned[$file] = $was;
            }
        }
    }

    /**
     * A file as it is now, enough to write it back: a link's target, else its bytes and mode.
     *
     * @return array{link: ?string, contents: string, mode: int}
     */
    private function asFound(string $file): array
    {
        if (is_link($file)) {
            $target = readlink($file);
            if ($target === false) {
                throw new InvalidArgumentException(sprintf('Unable to read the stale %s.', $file));
            }
            return ['link' => $target, 'contents' => '', 'mode' => 0];
        }
        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Unable to read the stale %s.', $file));
        }

        return ['link' => null, 'contents' => $contents, 'mode' => fileperms($file) & 0o777];
    }

    /**
     * Put back the files {@see pruneUnshipped()} deleted, as they were.
     *
     * @param array<string, array{link: ?string, contents: string, mode: int}> $pruned
     */
    private function restorePruned(array $pruned): void
    {
        foreach ($pruned as $file => $was) {
            if ($was['link'] !== null) {
                @symlink($was['link'], $file);
                continue;
            }
            if (@file_put_contents($file, $was['contents']) !== false) {
                @chmod($file, $was['mode']);
            }
        }
    }

    /**
     * Move aside the skill directories earlier releases installed under their old names.
     *
     * The skill was `skills/knossos`, then `skills/ask-the-graph`. Claude Code
     * loads every directory under `skills/`, so an updated install that only
     * added the renamed one would offer the same instructions twice. Each old
     * directory is renamed rather than deleted so that a later failure can
     * restore it, and is only removed once the whole install has succeeded.
     *
     * @return array<string, string> original path => where it was parked
     */
    private function retireStaleSkills(string $out): array
    {
        $retired = [];
        foreach (self::STALE_SKILLS as $relative) {
            $stale = $out . $relative;
            if (!is_dir($stale) && !is_link($stale)) {
                continue;
            }
            $parked = $out . '/skills/.retired-' . bin2hex(random_bytes(4));
            if (!@rename($stale, $parked)) {
                foreach ($retired as $original => $moved) {
                    @rename($moved, $original);
                }
                throw new InvalidArgumentException(sprintf('Unable to remove the stale %s.', $stale));
            }
            $retired[$stale] = $parked;
        }

        return $retired;
    }

    /**
     * Every file a materialised plugin holds, sorted, for reporting.
     *
     * @param array<string, string> $scripts installed relative path => content
     * @return list<string>
     */
    private function pluginFiles(array $scripts): array
    {
        $files = [...self::STATIC_FILES, ...array_keys($scripts)];
        sort($files);

        return $files;
    }

    /**
     * The data directory the installed hooks will read, as a value to bake in.
     *
     * The option wins, then the installer's own `KNOSSOS_DATA_DIR`, then the
     * empty string, which keeps the hooks deriving the graph from the project
     * path. A newline, carriage return or NUL is rejected because the value ends up inside a
     * single-quoted shell assignment, where either would break the script.
     */
    private function dataDirectory(?string $option): string
    {
        $value = $option;
        if ($value === null || $value === '') {
            $fromEnvironment = getenv('KNOSSOS_DATA_DIR');
            $value = is_string($fromEnvironment) ? $fromEnvironment : '';
        }
        if ($value !== '' && !str_starts_with($value, '/')) {
            // A relative path would be resolved against each project's
            // directory by the hooks, which is a different graph from the one
            // the server reads.
            throw new InvalidArgumentException(sprintf('data-dir must be an absolute path, got "%s".', $value));
        }
        $this->singleQuoted($value, 'data-dir');

        return $value;
    }

    /**
     * $value made safe to sit between the single quotes of a shell assignment.
     *
     * A single quote is closed, escaped and reopened, so no character inside
     * can expand or end the string. A newline, carriage return or NUL is
     * rejected outright: none belongs in a path or an image name, and each
     * breaks a line-oriented script.
     */
    private function singleQuoted(string $value, string $name): string
    {
        if (str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\0")) {
            throw new InvalidArgumentException(sprintf('%s must not contain a newline, carriage return or NUL.', $name));
        }

        return str_replace("'", "'\\''", $value);
    }

    /** The sentence that tells a person which graph the installed hooks read. */
    private function dataDirectoryLine(string $dataDir): string
    {
        return $dataDir === ''
            ? 'Data directory: not set, the hooks derive the graph from the project path.'
            : sprintf('Data directory: %s (the hooks read the graph there).', $dataDir);
    }

    /**
     * The installation's manifest with this CLI's version substituted in.
     *
     * Key order is the source file's, because json_decode() preserves it and
     * json_encode() writes it back in the same order: the emitted file reads
     * as the committed one with a different version, not as a reordering of
     * it. Slashes are left unescaped for the same reason, so the URLs in it
     * stay readable to anyone who opens it.
     */
    private function versionedManifest(string $source): string
    {
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->read($source), true, 16, JSON_THROW_ON_ERROR);
        $manifest['version'] = $this->version;

        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
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
     * @param array<string, string> $replacedFiles path => contents as found
     */
    private function rollbackEmit(string $out, bool $outExisted, array $createdDirectories, array $createdFiles, array $replacedFiles = []): void
    {
        if (!$outExisted) {
            $this->removeTree($out);
            return;
        }
        // Restored before the created files are removed, so a path that was
        // both is put back rather than deleted.
        foreach ($replacedFiles as $file => $original) {
            @file_put_contents($file, $original);
        }
        foreach ($createdFiles as $file) {
            if (array_key_exists($file, $replacedFiles)) {
                continue;
            }
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
