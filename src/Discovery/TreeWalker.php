<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use DirectoryIterator;
use Knossos\Scan\CancellationToken;
use RuntimeException;
use SplFileInfo;

/**
 * Walks a project tree and reads what discovery keeps from it: the source
 * files, the manifests as units, and the diagnostics for whatever it skipped.
 *
 * Enforces the allow-list, applies ignore rules, and stops at the configured
 * caps. Unreadable entries become diagnostics rather than exceptions, so one
 * permission problem does not deny a graph of everything else.
 */
final readonly class TreeWalker
{
    private RootGuard $rootGuard;

    /** @param FileContentReader $contents how a file discovery uses twice is read */
    public function __construct(private DiscoveryConfig $config, private FileContentReader $contents)
    {
        $this->rootGuard = new RootGuard($config->allowedRoots);
    }

    /**
     * Walk the tree and select the files worth analysing, within the configured caps.
     *
     * Units are read where the walk meets them, so a manifest's diagnostics
     * sit among the walk's own in the order the tree produced them.
     *
     * @return array{
     *     root: string,
     *     files: list<DiscoveredFile>,
     *     units: list<ProjectUnit>,
     *     diagnostics: list<DiscoveryDiagnostic>,
     *     unparsedManifestHashes: array<string, string>,
     *     manifestRoots: list<string>,
     *     directories: list<string>,
     * } the files and units in walk order; the manifest roots and the directories the walk opened, sorted
     */
    public function walk(string $requestedRoot, ?CancellationToken $cancellation = null): array
    {
        $root = $this->rootGuard->resolve($requestedRoot);
        $files = [];
        $units = [];
        $unparsedManifestHashes = [];
        $diagnostics = [];
        $stack = [$root];
        $inputCount = 0;
        $seen = 0;
        $gitIgnore = new GitIgnoreRules();
        /** @var array<string, FileContent> $gitIgnoreReads each `.gitignore` read, kept to hash as its unit */
        $gitIgnoreReads = [];

        /** @var array<string, true> $manifestRoots directories whose listing holds a manifest */
        $manifestRoots = [];
        /** @var list<string> $directories every directory listed, the root left out */
        $directories = [];
        // Asked only about directories already listed: a path is matched while
        // its parent is walked, after every ancestor's listing was recorded.
        $ignoreMatcher = new IgnoreMatcher(
            $this->config->ignorePatterns,
            static function (string $directory) use (&$manifestRoots): bool {
                return isset($manifestRoots[$directory]);
            },
        );

        while ($stack !== []) {
            $directory = array_pop($stack);
            $this->readGitIgnore($root, $directory, $gitIgnore, $gitIgnoreReads);
            $names = $this->listDirectory($root, $directory, $diagnostics, $manifestRoots);
            if ($names !== null && $directory !== $root) {
                $directories[] = $this->relative($root, $directory);
            }

            foreach ($names ?? [] as $name) {
                // Discovery walks and hashes up to maxFiles entries — the longest
                // non-worker stage. Poll cancellation periodically so a client's
                // notifications/cancelled is observable here, not just around RPCs.
                if ($cancellation !== null && (++$seen % 512) === 0) {
                    $cancellation->throwIfCancelled();
                }

                $absolute = str_replace('\\', '/', $directory . '/' . $name);
                $entry = new SplFileInfo($absolute);
                $relative = $this->relative($root, $absolute);
                // First, before the entry is classified or a directory is queued:
                // a name that is not valid UTF-8 cannot be encoded into a stable
                // id, and one with a control character fails a worker's path
                // check. Skipping a directory here also skips its children.
                if (!SourceClassifier::isSupportedPath($relative)) {
                    $diagnostics[] = new DiscoveryDiagnostic(
                        'warning',
                        'DISCOVERY_PATH_UNSUPPORTED',
                        'Skipped a path whose name is not valid UTF-8 or contains a control character: ' . bin2hex($relative),
                    );
                    continue;
                }
                if (!SourceClassifier::isConfigurationFile($relative) && $ignoreMatcher->matches($relative)) {
                    self::reportBuildOutput($ignoreMatcher, $gitIgnore, $entry, $relative, $diagnostics);
                    continue;
                }

                // Every stat-dependent probe below (isLink/isDir/isFile/getSize/getMTime)
                // throws RuntimeException when the entry vanishes mid-walk (a concurrent
                // build deleting a temp file). Treat that as an unreadable file — emit a
                // diagnostic and keep going rather than failing the whole scan.
                try {
                    if ($entry->isLink()) {
                        $diagnostics[] = self::symlinkDiagnostic($root, $absolute, $relative);
                        continue;
                    }

                    if ($entry->isDir()) {
                        if (!$gitIgnore->ignores($relative, true)) {
                            $stack[] = $absolute;
                        }
                        continue;
                    }
                    if (!$entry->isFile()) {
                        continue;
                    }
                    if (!SourceClassifier::isConfigurationFile($relative) && $gitIgnore->ignores($relative, false)) {
                        continue;
                    }

                    $language = SourceClassifier::languageFor($relative, $absolute);
                    $unitKind = SourceClassifier::unitKindFor($relative);
                    if ($language === null && $unitKind === null) {
                        continue;
                    }

                    ++$inputCount;
                    if ($inputCount > $this->config->maxFiles) {
                        throw new DiscoveryException(sprintf('Discovery file limit exceeded (%d).', $this->config->maxFiles));
                    }

                    $size = $entry->getSize();
                    if ($size > $this->config->maxFileBytes) {
                        $diagnostics[] = new DiscoveryDiagnostic(
                            'warning',
                            'DISCOVERY_FILE_TOO_LARGE',
                            sprintf('File exceeds the %d-byte discovery limit.', $this->config->maxFileBytes),
                            $relative,
                        );
                        continue;
                    }

                    $mtime = max(0, $entry->getMTime());
                } catch (DiscoveryException $error) {
                    throw $error;
                } catch (RuntimeException $error) {
                    $diagnostics[] = new DiscoveryDiagnostic(
                        'warning',
                        'DISCOVERY_FILE_UNREADABLE',
                        sprintf('File could not be inspected: %s', $error->getMessage()),
                        $relative,
                    );
                    continue;
                }

                $fingerprinted = $this->fingerprint($unitKind, $relative, $absolute, $gitIgnoreReads, $diagnostics);
                if ($fingerprinted === null) {
                    continue;
                }
                [$buffer, $fingerprint] = $fingerprinted;
                $contentHash = $fingerprint->contentHash;

                if ($language !== null) {
                    $files[] = new DiscoveredFile(
                        $relative,
                        $absolute,
                        $language,
                        $size,
                        $mtime,
                        $contentHash,
                        $fingerprint->lineCount,
                        $fingerprint->gitBlobHash,
                    );
                }

                if ($unitKind !== null) {
                    $unit = UnitReader::read($unitKind, $relative, $absolute, $contentHash, $buffer, $diagnostics);
                    if ($unit !== null) {
                        $units[] = $unit;
                    } elseif ($buffer !== null) {
                        // Read and hashed, but not a unit: a worker can still
                        // read these bytes, so their hash is kept to check it by.
                        $unparsedManifestHashes[$relative] = $contentHash;
                    }
                }
            }
        }

        $manifestRoots = array_map('strval', array_keys($manifestRoots));
        sort($manifestRoots);
        sort($directories);

        return [
            'root' => $root,
            'files' => $files,
            'units' => $units,
            'diagnostics' => $diagnostics,
            'unparsedManifestHashes' => $unparsedManifestHashes,
            'manifestRoots' => $manifestRoots,
            'directories' => $directories,
        ];
    }

    /**
     * The bytes a kept file was read as and their fingerprint, or null, with
     * a diagnostic, when the file grew past the limit or could not be hashed.
     *
     * @param array<string, FileContent> $gitIgnoreReads the `.gitignore` reads already taken, reused as their bytes
     * @param list<DiscoveryDiagnostic> $diagnostics
     * @return ?array{0: ?string, 1: FileFingerprint} the bytes (null for a file that is not a unit) and their fingerprint
     */
    private function fingerprint(?string $unitKind, string $relative, string $absolute, array $gitIgnoreReads, array &$diagnostics): ?array
    {
        // A manifest is read once, here, and that one buffer
        // answers both the hash and the parse that follows in walk(). Fingerprinting it
        // separately from parsing it meant two reads of one path with
        // nothing tying them together, so an edit landing between them
        // left the unit's hash describing bytes its metadata never came
        // from. A path that is both a manifest and a source file gets
        // its `files` row hash from the same buffer for the same
        // reason.
        //
        // The limit goes with the request rather than being taken as
        // already enforced by the walk's size check: that check and this
        // read are two moments, and a file that grows between them is
        // over the limit by the time the bytes are asked for. Reading
        // it whole on the strength of a stale size is an unbounded
        // allocation driven by the tree being scanned.
        $read = $unitKind === null
            ? null
            : ($gitIgnoreReads[$relative] ?? $this->contents->read($absolute, $this->config->maxFileBytes));
        if ($read !== null && $read->oversized) {
            // The same diagnostic a file already too large when its
            // size was checked gets: it is the same fact, learned one
            // moment later, and a caller acts on it the same way.
            $diagnostics[] = new DiscoveryDiagnostic(
                'warning',
                'DISCOVERY_FILE_TOO_LARGE',
                sprintf('File exceeds the %d-byte discovery limit.', $this->config->maxFileBytes),
                $relative,
            );
            return null;
        }
        $buffer = $read?->bytes;
        $fingerprint = $buffer === null
            ? FileFingerprint::compute($absolute, $this->config->gitObjectHash)
            : FileFingerprint::fromContents($buffer, $this->config->gitObjectHash);
        if ($fingerprint === null) {
            $diagnostics[] = new DiscoveryDiagnostic(
                'warning',
                'DISCOVERY_FILE_UNREADABLE',
                'File could not be hashed.',
                $relative,
            );
            return null;
        }

        return [$buffer, $fingerprint];
    }

    /**
     * The names in one directory, read whole before any is matched, recording
     * the directory as a manifest root when one of them is a manifest.
     *
     * Read whole because whether `dist` beside `package.json` is build output
     * depends on a sibling the iterator may reach later. Presence is decided by
     * name, before any ignore rule, so a `.gitignore` cannot change what counts
     * as a root. Null, with a diagnostic, when the directory cannot be opened.
     *
     * @param list<DiscoveryDiagnostic> $diagnostics
     * @param array<string, true> $manifestRoots
     * @return ?list<string>
     */
    private function listDirectory(string $root, string $directory, array &$diagnostics, array &$manifestRoots): ?array
    {
        try {
            // UnexpectedValueException, which is a RuntimeException, is what
            // DirectoryIterator throws for a directory it cannot open. Caught
            // by that name rather than as Throwable so a programming error in
            // the walk is not filed as an unreadable directory.
            $entries = new DirectoryIterator($directory);
        } catch (RuntimeException $error) {
            $directoryPath = $this->relative($root, $directory);
            // The iterator's own message quotes the raw path, so an
            // unsupported name must not reach it either.
            $supported = SourceClassifier::isSupportedPath($directoryPath);
            $diagnostics[] = new DiscoveryDiagnostic(
                'warning',
                'DISCOVERY_DIRECTORY_UNREADABLE',
                $supported ? $error->getMessage() : 'Could not open a directory whose name is not supported: ' . bin2hex($directoryPath),
                $supported ? $directoryPath : null,
            );
            return null;
        }

        $names = [];
        foreach ($entries as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $names[] = $entry->getFilename();
            if (SourceClassifier::isManifest($entry->getFilename())) {
                $manifestRoots[$this->relative($root, $directory)] = true;
            }
        }

        return $names;
    }

    /**
     * Say that a declined directory was build output, when it was.
     *
     * Build output is skipped by a rule the user did not write, so it is
     * reported, once per directory; what the user's own patterns or a
     * `.gitignore` skip is what they asked for, and a file named `dist` is no
     * directory.
     *
     * @param list<DiscoveryDiagnostic> $diagnostics
     */
    private static function reportBuildOutput(
        IgnoreMatcher $ignoreMatcher,
        GitIgnoreRules $gitIgnore,
        SplFileInfo $entry,
        string $relative,
        array &$diagnostics,
    ): void {
        if (!$ignoreMatcher->anchoredBuiltIn($relative)
            || $entry->isLink()
            || !$entry->isDir()
            || $gitIgnore->ignores($relative, true)) {
            return;
        }
        $diagnostics[] = new DiscoveryDiagnostic(
            'info',
            'DISCOVERY_BUILD_OUTPUT_SKIPPED',
            // Root-anchored, so the suggested pattern re-includes this
            // directory only: `!dist` would re-include every anchored `dist`.
            sprintf('Skipped build output directory %s/; add "!/%s" to ignores to scan it.', $relative, $relative),
            $relative,
        );
    }

    /**
     * Read a directory's `.gitignore` into the rules before its entries are walked.
     *
     * It governs its siblings, which the iterator may reach first, so it is
     * read before any of them. The same read later answers its unit's hash, so
     * the rules applied and the hash recorded cannot describe two different
     * files.
     *
     * @param array<string, FileContent> $gitIgnoreReads
     */
    private function readGitIgnore(string $root, string $directory, GitIgnoreRules $gitIgnore, array &$gitIgnoreReads): void
    {
        $ignoreFile = $directory . '/.gitignore';
        if (!is_file($ignoreFile) || is_link($ignoreFile)) {
            return;
        }
        $ignoreRelative = $this->relative($root, $ignoreFile);
        $gitIgnoreReads[$ignoreRelative] = $this->contents->read($ignoreFile, $this->config->maxFileBytes);
        $ignoreBytes = $gitIgnoreReads[$ignoreRelative]->bytes;
        if ($ignoreBytes !== null) {
            $gitIgnore->add($this->relative($root, $directory), $ignoreBytes);
        }
    }

    /**
     * The diagnostic for a symlink discovery skipped.
     *
     * A link that resolves stays inside the root or escapes it, and realpath
     * says which. A link that resolves to nothing, dangling or looping, cannot
     * escape anything, and calling it an escape told the reader the project
     * pointed outside itself. So the link's own target is read instead: a target
     * that lies inside the root is a broken link, one that lies outside is still
     * an escape. The target is normalised as text, which is enough for naming a
     * diagnostic, because every link is skipped whatever it is called.
     */
    private static function symlinkDiagnostic(string $root, string $absolute, string $relative): DiscoveryDiagnostic
    {
        // file_exists() first: PHP's realpath cache can hand back a path for a
        // link loop created earlier in the same process, where a stat fails.
        $resolved = file_exists($absolute) ? realpath($absolute) : false;
        if ($resolved !== false) {
            $escapes = !RootGuard::contains($root, $resolved);
        } else {
            $target = @readlink($absolute);
            if (!is_string($target)) {
                return new DiscoveryDiagnostic(
                    'warning',
                    'DISCOVERY_FILE_UNREADABLE',
                    'Symlink could not be inspected; the link was skipped.',
                    $relative,
                );
            }
            $escapes = !RootGuard::contains($root, self::lexicalTarget($absolute, $target));
            if (!$escapes) {
                return new DiscoveryDiagnostic(
                    'warning',
                    'DISCOVERY_SYMLINK_BROKEN',
                    'Symlink target does not exist or cannot be resolved; the link was skipped.',
                    $relative,
                );
            }
        }

        return new DiscoveryDiagnostic(
            'warning',
            $escapes ? 'DISCOVERY_SYMLINK_ESCAPE' : 'DISCOVERY_SYMLINK_SKIPPED',
            $escapes
                ? 'Symlink target escapes the project root and was rejected.'
                : 'Symlink was skipped because discovery does not follow symlinks.',
            $relative,
        );
    }

    /** A link's target as an absolute path, with `.` and `..` applied as text. */
    private static function lexicalTarget(string $link, string $target): string
    {
        $target = str_replace('\\', '/', $target);
        $path = str_starts_with($target, '/') ? $target : dirname(str_replace('\\', '/', $link)) . '/' . $target;
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    /** A path expressed relative to the project root, which is the only form facts carry. */

    private function relative(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);

        return ltrim(substr($path, strlen($root)), '/');
    }
}
