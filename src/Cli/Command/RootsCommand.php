<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use JsonException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Discovery\AllowedRoots;

/**
 * Grants a project directory scan access, without hand-editing roots.json.
 *
 * `serve` refuses to scan anything outside its allowed roots, and the only
 * remedy until now was editing that file directly. A running server re-reads
 * it per request, which is what makes a CLI command for this worthwhile
 * rather than a formality: the root it appends takes effect immediately,
 * with no restart and no re-registration of the server.
 *
 * Preview by default, matching `annotate-component` and
 * `install-agent-plugin`, because this command mutates a security-relevant
 * allowlist: the set of directory trees the server may read.
 */
final class RootsCommand implements CliCommand
{
    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'allow-root';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['db', 'json', 'execute'];
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $path = $positionals[0] ?? throw new InvalidArgumentException(
            'Usage: knossos allow-root <path> [--execute] [--db=FILE] [--json]',
        );
        // Roots are compared against the canonical path a scan is asked to
        // cover, which is always absolute, so a relative entry would never
        // match anything and would silently grant nothing.
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException(sprintf(
                '%s is not absolute. allow-root needs an absolute path so it can be compared against scan requests.',
                $path,
            ));
        }
        // A root that does not exist looks identical to a working one until
        // something tries to scan it, which is the confusion this check
        // prevents up front instead of at scan time.
        if (!is_dir($path)) {
            throw new InvalidArgumentException(sprintf('%s is not an existing directory.', $path));
        }
        // Both the comparison below and the entry written out use the reader's
        // own spelling of a root, borrowed from AllowedRoots rather than
        // reinvented, so `/p` and `/p/` cannot become two lines granting one
        // directory. AllowedRoots::normalise() folds them back together when it
        // reads the file, so the duplicate was never a security hole; it was
        // rot in a file people hand-edit, and a command reporting "already
        // present" for neither spelling.
        $path = AllowedRoots::normaliseRoot($path);

        $configPath = AllowedRoots::defaultConfigPath($context->databasePath());
        $roots = self::readRoots($configPath);
        $json = $context->options->flag($options, 'json');

        // Existing entries are compared normalised but rewritten verbatim: a
        // root someone typed with a trailing slash before this fix is already
        // granted, and silently rewriting lines this call did not add would
        // make an append look like an edit.
        if (in_array($path, array_map(AllowedRoots::normaliseRoot(...), $roots), true)) {
            $context->output(
                ['path' => $path, 'roots_file' => $configPath, 'added' => false],
                $json,
                sprintf('%s is already present in %s. Nothing to do.', $path, $configPath),
            );
            return 0;
        }

        $roots[] = $path;

        if (!$context->options->flag($options, 'execute')) {
            $context->output(
                ['path' => $path, 'roots_file' => $configPath, 'added' => false, 'preview' => true],
                $json,
                sprintf(
                    "Would add %s to %s.\nA running server re-reads that file per request, so the addition would take effect with no restart.\nRe-run with --execute to write it.",
                    $path,
                    $configPath,
                ),
            );
            return 0;
        }

        self::writeRoots($configPath, $roots);
        $context->output(
            ['path' => $path, 'roots_file' => $configPath, 'added' => true],
            $json,
            sprintf(
                "Added %s to %s.\nA running server re-reads that file per request, so this takes effect immediately with no restart.",
                $path,
                $configPath,
            ),
        );
        return 0;
    }

    /**
     * The roots already on disk, in file order.
     *
     * Tolerates both `{"roots": [...]}` and a bare `[...]`, the same two shapes
     * {@see AllowedRoots::parse()} accepts when a running server reads this
     * file. Rejecting the bare form here would make this command call a file
     * "corrupt" that the server itself reads and honours just fine, which is
     * exactly the hand-editing dead end the command exists to remove.
     *
     * Invalid JSON is a different failure and still throws rather than being
     * treated as empty: a reader that must never grant more than it should is
     * right to fall back to nothing, but a writer must never silently replace
     * content it could not understand with just the one root this call knows
     * about.
     *
     * @return list<string>
     */
    private static function readRoots(string $configPath): array
    {
        if (!is_file($configPath)) {
            return [];
        }
        $contents = file_get_contents($configPath);
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Unable to read %s.', $configPath));
        }
        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                sprintf('%s is not valid JSON (%s). Fix or remove it before retrying.', $configPath, $error->getMessage()),
                previous: $error,
            );
        }
        $decodedRoots = is_array($decoded) ? ($decoded['roots'] ?? $decoded) : null;
        if (!is_array($decodedRoots)) {
            throw new InvalidArgumentException(sprintf('%s does not have the expected {"roots": [...]} shape.', $configPath));
        }
        $roots = [];
        foreach ($decodedRoots as $root) {
            if (is_string($root)) {
                $roots[] = $root;
            }
        }
        return $roots;
    }

    /**
     * Write the roots file, creating its parent directory when this is the
     * first root ever granted for this database.
     *
     * Always writes the canonical `{"roots": [...]}` shape, so a bare-array
     * file that readRoots() tolerated on the way in is normalised the first
     * time a root is added through this command.
     *
     * Written to a temporary file beside the target and renamed into place.
     * rename() is atomic only within one filesystem, so the temporary file
     * must sit in the same directory; a write that fails partway (disk full,
     * for instance) then lands on the temporary name and never truncates the
     * hand-edited file it would otherwise clobber. Every failure path here
     * removes that temporary file before throwing, so a failed run leaves no
     * litter beside the real one.
     *
     * @param list<string> $roots
     */
    private static function writeRoots(string $configPath, array $roots): void
    {
        $directory = dirname($configPath);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true)) {
            throw new InvalidArgumentException(sprintf('Unable to create %s.', $directory));
        }
        // Captured before the temporary file is even written: rename() carries
        // over the TEMPORARY file's mode, not the target's, so a file the
        // operator deliberately chmod'd (0600, say) would otherwise silently
        // widen to the umask default the moment it is replaced. A target that
        // does not exist yet gets no mode forced onto it; the umask default is
        // the ordinary behaviour for a file created for the first time. A
        // target that exists but whose fileperms() call itself fails (a stat
        // race, say) is treated the same way, with no mode forced: an unknown
        // mode is not a reason to invent one, and `false & 0o777` would
        // otherwise coerce to int 0, silently locking the file to mode 0000.
        $existingMode = null;
        if (is_file($configPath)) {
            $stattedMode = fileperms($configPath);
            if ($stattedMode !== false) {
                $existingMode = $stattedMode & 0o777;
            }
        }

        $encoded = json_encode(['roots' => $roots], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $directory . '/.roots.json.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temporary, $encoded . PHP_EOL) === false) {
            @unlink($temporary);
            throw new InvalidArgumentException(sprintf('Unable to write %s.', $configPath));
        }
        // Set on the temporary file BEFORE the rename, not after, so the file
        // is never in place, even briefly, with the wrong permissions.
        if ($existingMode !== null && !@chmod($temporary, $existingMode)) {
            @unlink($temporary);
            throw new InvalidArgumentException(sprintf('Unable to write %s.', $configPath));
        }
        if (!@rename($temporary, $configPath)) {
            @unlink($temporary);
            throw new InvalidArgumentException(sprintf('Unable to write %s.', $configPath));
        }
    }
}
