<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use DirectoryIterator;
use Knossos\Discovery\Manifest\ManifestPaths;
use Knossos\Discovery\Manifest\ManifestReaders;
use Knossos\Scan\CancellationToken;
use RuntimeException;
use SplFileInfo;

/**
 * Walks a project tree and selects the files worth analysing.
 *
 * Enforces the allow-list, applies ignore rules, and stops at the configured
 * caps. Unreadable entries become diagnostics rather than exceptions, so one
 * permission problem does not deny a graph of everything else.
 */
final readonly class ProjectDiscoverer
{
    /** The unit kinds that make their directory a manifest root; see {@see SourceClassifier::MANIFEST_UNIT_KINDS}. */
    public const MANIFEST_UNIT_KINDS = SourceClassifier::MANIFEST_UNIT_KINDS;

    private RootGuard $rootGuard;
    private FileContentReader $contents;

    /**
     * @param ?FileContentReader $contents how a file discovery uses twice is
     *        read. Production reads the filesystem; injected only so a test
     *        can answer a second read with different bytes, which is the one
     *        way to show that a manifest's hash and its metadata come from a
     *        single read rather than from two that can disagree.
     */
    public function __construct(private DiscoveryConfig $config, ?FileContentReader $contents = null)
    {
        $this->rootGuard = new RootGuard($config->allowedRoots);
        $this->contents = $contents ?? new FilesystemContentReader();
    }
    /** Walk the tree and select the files worth analysing, within the configured caps. */

    public function discover(string $requestedRoot, ?CancellationToken $cancellation = null): DiscoveryResult
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

                // A manifest is read once, here, and that one buffer
                // answers both the hash and the parse below. Fingerprinting it
                // separately from parsing it meant two reads of one path with
                // nothing tying them together, so an edit landing between them
                // left the unit's hash describing bytes its metadata never came
                // from. A path that is both a manifest and a source file gets
                // its `files` row hash from the same buffer for the same
                // reason.
                //
                // The limit goes with the request rather than being taken as
                // already enforced by the size check above: that check and this
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
                    continue;
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
                    continue;
                }
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
                    $unit = $this->readUnit($unitKind, $relative, $absolute, $contentHash, $buffer, $diagnostics);
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

        return self::result($root, $files, $units, $diagnostics, $unparsedManifestHashes, [$manifestRoots, $directories]);
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
     * The walk's files and units in a stable order, with the entry points
     * derived across units, and the input and configuration hashes over both.
     *
     * @param list<DiscoveredFile> $files
     * @param list<ProjectUnit> $units
     * @param list<DiscoveryDiagnostic> $diagnostics
     * @param array<string, string> $unparsedManifestHashes
     * @param array{0: list<string>, 1: list<string>} $walked the manifest roots and the directories the walk opened
     */
    private static function result(
        string $root,
        array $files,
        array $units,
        array $diagnostics,
        array $unparsedManifestHashes,
        array $walked,
    ): DiscoveryResult {
        $files = SourceClassifier::withoutCompiledSiblings($files);
        usort($files, static fn(DiscoveredFile $left, DiscoveredFile $right): int =>
            $left->relativePath <=> $right->relativePath);
        usort($units, static fn(ProjectUnit $left, ProjectUnit $right): int =>
            [$left->kind, $left->configPath] <=> [$right->kind, $right->configPath]);
        $units = self::withBuildOutputSources($units);
        $units = self::withClassNameEntryPoints($units);
        $units = self::withLoadedDirectoryEntryPoints($units, $files);
        $units = self::withGlobEntryPoints($units, $files);

        $inputParts = array_map(
            static fn(DiscoveredFile $file): string => $file->relativePath . '=' . $file->contentHash,
            $files,
        );
        $configParts = array_map(
            static fn(ProjectUnit $unit): string => $unit->kind . ':' . $unit->configPath . '=' . $unit->contentHash,
            $units,
        );

        return new DiscoveryResult(
            $root,
            $files,
            $units,
            $diagnostics,
            hash('sha256', implode("\n", $inputParts)),
            hash('sha256', implode("\n", $configParts)),
            $unparsedManifestHashes,
            ...$walked,
        );
    }

    /**
     * Parse one project manifest from the bytes the caller already read,
     * recording a diagnostic rather than failing when there are none.
     *
     * Takes the content rather than the path on purpose: $contentHash is the
     * hash of exactly these bytes, and reading the file again here is what let
     * the two describe different content.
     *
     * @param ?string $contents the bytes this unit's hash was taken over, or null when the read failed
     * @param list<DiscoveryDiagnostic> $diagnostics
     */
    private function readUnit(
        string $kind,
        string $relative,
        string $absolute,
        string $contentHash,
        ?string $contents,
        array &$diagnostics,
    ): ?ProjectUnit {
        if ($contents === null) {
            $diagnostics[] = new DiscoveryDiagnostic(
                'warning',
                'DISCOVERY_CONFIG_UNREADABLE',
                'Configuration file could not be read.',
                $relative,
            );
            return null;
        }

        // TOML, plain text and text read for paths: none of them is JSON, and
        // feeding one to JsonConfig::decode() below would drop the unit as
        // DISCOVERY_CONFIG_INVALID.
        $text = ManifestReaders::text($kind);
        if ($text !== null) {
            return new ProjectUnit($kind, $relative, $contentHash, $text->read($relative, $absolute, $contents));
        }

        try {
            $decoded = JsonConfig::decode(
                $contents,
                in_array($kind, ['typescript', 'knossos'], true) || str_ends_with(strtolower($relative), '.jsonc'),
            );
        } catch (DiscoveryException $error) {
            $diagnostics[] = new DiscoveryDiagnostic(
                'warning',
                'DISCOVERY_CONFIG_INVALID',
                $error->getMessage(),
                $relative,
            );
            return null;
        }

        return new ProjectUnit($kind, $relative, $contentHash, ManifestReaders::json($kind)?->read($relative, $absolute, $decoded) ?? []);
    }

    /**
     * The sources a published build output is compiled from, when no tsconfig says.
     *
     * `dist/esm.mjs` is not in the scan (the build directory is excluded), and
     * what its consumers call is `src/esm.ts`, compiled to it the way
     * `rootDir: src` and `outDir: dist` lay a library out. A bundler builds
     * many libraries without a tsconfig `outDir`, so this is the fallback
     * {@see self::withBuildOutputSources()} uses for an entry no declared
     * layout covers. Each extension the source may have is named; a twin no
     * file answers to publishes nothing.
     *
     * @return list<string>
     */
    private static function sourceTwins(string $path, string $directory): array
    {
        $inside = $directory === '' ? $path : substr($path, strlen($directory) + 1);
        if (preg_match('#^(?:dist|build|lib|out)/(.+?)(?:\.d)?\.[cm]?[jt]sx?$#', $inside, $match) !== 1) {
            return [];
        }
        $prefix = ($directory === '' ? '' : $directory . '/') . 'src/' . $match[1];

        return array_map(static fn(string $extension): string => $prefix . $extension, ['.ts', '.tsx', '.mts', '.cts', '.js', '.jsx', '.mjs', '.cjs']);
    }

    /**
     * Add, to each YAML unit, the PHP files below the directories it loads
     * every class from: Doctrine migration paths and tagged service resources.
     *
     * @param list<ProjectUnit> $units
     * @param list<DiscoveredFile> $files
     * @return list<ProjectUnit>
     */
    private static function withLoadedDirectoryEntryPoints(array $units, array $files): array
    {
        return array_map(static function (ProjectUnit $unit) use ($files): ProjectUnit {
            $directories = $unit->metadata['loaded_directories'] ?? [];
            if ($unit->kind !== 'yaml' || !is_array($directories) || $directories === []) {
                return $unit;
            }
            $exclusions = array_values(array_filter($unit->metadata['loaded_exclusions'] ?? [], is_string(...)));
            $paths = array_fill_keys($unit->metadata['entry_points'] ?? [], true);
            foreach ($files as $file) {
                if (!str_ends_with($file->relativePath, '.php') || self::excludedResource($file->relativePath, $exclusions)) {
                    continue;
                }
                foreach ($directories as $directory) {
                    if (is_string($directory) && str_starts_with($file->relativePath, $directory . '/')) {
                        $paths[$file->relativePath] = true;
                    }
                }
            }
            $paths = array_map(strval(...), array_keys($paths));
            sort($paths, SORT_STRING);

            return new ProjectUnit($unit->kind, $unit->configPath, $unit->contentHash, [
                ...$unit->metadata,
                'entry_points' => $paths,
            ]);
        }, $units);
    }

    /**
     * Add, to each unit, the discovered files its entry globs match.
     *
     * `migrations: ['src/migrations/*.ts']` names every migration without
     * naming one; the walk's file list is what the pattern is matched against.
     * `**` spans directories, `*` and `?` stay within one.
     *
     * @param list<ProjectUnit> $units
     * @param list<DiscoveredFile> $files
     * @return list<ProjectUnit>
     */
    private static function withGlobEntryPoints(array $units, array $files): array
    {
        return array_map(static function (ProjectUnit $unit) use ($files): ProjectUnit {
            $globs = array_values(array_filter($unit->metadata['entry_globs'] ?? [], is_string(...)));
            if ($globs === []) {
                return $unit;
            }
            $patterns = array_map(static function (string $glob): string {
                $regex = '';
                for ($at = 0, $length = strlen($glob); $at < $length; ++$at) {
                    if (substr($glob, $at, 3) === '**/') {
                        $regex .= '(?:.*/)?';
                        $at += 2;
                    } elseif (substr($glob, $at, 2) === '**') {
                        $regex .= '.*';
                        ++$at;
                    } elseif ($glob[$at] === '*') {
                        $regex .= '[^/]*';
                    } elseif ($glob[$at] === '?') {
                        $regex .= '[^/]';
                    } else {
                        $regex .= preg_quote($glob[$at], '#');
                    }
                }

                return '#^' . $regex . '$#';
            }, $globs);
            $paths = array_fill_keys($unit->metadata['entry_points'] ?? [], true);
            foreach ($files as $file) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $file->relativePath) === 1) {
                        $paths[$file->relativePath] = true;
                        break;
                    }
                }
            }
            $paths = array_map(strval(...), array_keys($paths));
            sort($paths, SORT_STRING);

            return new ProjectUnit($unit->kind, $unit->configPath, $unit->contentHash, [
                ...$unit->metadata,
                'entry_points' => $paths,
            ]);
        }, $units);
    }

    /**
     * Whether a path falls under a resource `exclude:`: the path itself, a
     * directory above it, or a glob matching it.
     *
     * @param list<string> $exclusions
     */
    private static function excludedResource(string $path, array $exclusions): bool
    {
        foreach ($exclusions as $exclusion) {
            if ($path === $exclusion || str_starts_with($path, rtrim($exclusion, '/') . '/') || fnmatch($exclusion, $path, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add, to each YAML unit, the files its class names are declared in.
     *
     * Symfony and Doctrine wire classes up by name in YAML, and the container
     * instantiates them; nothing in PHP references them. Composer's PSR-4 map
     * turns a class name into its file, which is what entry points match on.
     *
     * @param list<ProjectUnit> $units
     * @return list<ProjectUnit>
     */
    private static function withClassNameEntryPoints(array $units): array
    {
        $prefixes = [];
        foreach ($units as $unit) {
            if ($unit->kind !== 'composer' || !is_array($unit->metadata['psr4'] ?? null)) {
                continue;
            }
            $directory = ManifestPaths::manifestDirectory($unit->configPath);
            foreach ($unit->metadata['psr4'] as $namespace => $paths) {
                foreach (is_array($paths) ? $paths : [$paths] as $path) {
                    if (is_string($namespace) && is_string($path) && $namespace !== '') {
                        $prefixes[] = [$namespace, ManifestPaths::joinPath($directory, trim($path, '/'))];
                    }
                }
            }
        }
        if ($prefixes === []) {
            return $units;
        }
        // Longest namespace first, as Composer resolves them.
        usort($prefixes, static fn(array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));

        return array_map(static function (ProjectUnit $unit) use ($prefixes): ProjectUnit {
            $classNames = $unit->metadata['class_names'] ?? [];
            if ($unit->kind !== 'yaml' || !is_array($classNames) || $classNames === []) {
                return $unit;
            }
            $paths = array_fill_keys($unit->metadata['entry_points'] ?? [], true);
            foreach ($classNames as $className) {
                foreach ($prefixes as [$namespace, $directory]) {
                    if (is_string($className) && str_starts_with($className, $namespace)) {
                        $rest = str_replace('\\', '/', substr($className, strlen($namespace)));
                        $paths[ManifestPaths::joinPath($directory, $rest . '.php')] = true;
                        break;
                    }
                }
            }
            $paths = array_keys($paths);
            sort($paths, SORT_STRING);

            return new ProjectUnit($unit->kind, $unit->configPath, $unit->contentHash, [
                ...$unit->metadata,
                'entry_points' => $paths,
            ]);
        }, $units);
    }

    /** Output extension => the source extensions the compiler emits it from. */
    private const BUILD_OUTPUT_SOURCES = [
        'js' => ['ts', 'tsx', 'js', 'jsx'],
        'mjs' => ['mts', 'mjs'],
        'cjs' => ['cts', 'cjs'],
    ];

    /**
     * Add, beside each package entry point inside a tsconfig's `outDir`, the
     * sources the compiler emits it from.
     *
     * A compiled package's `main`, `bin` and scripts name its build output,
     * which discovery never walks, so the name matched nothing and the source
     * behind it looked unreferenced. Without a `rootDir` the compiler infers
     * one, so the tsconfig's own directory and its `src/` are both offered.
     * Matching downstream is by exact path, so a candidate naming no file
     * costs nothing.
     *
     * @param list<ProjectUnit> $units
     * @return list<ProjectUnit>
     */
    private static function withBuildOutputSources(array $units): array
    {
        $layouts = [];
        foreach ($units as $unit) {
            $outDir = $unit->kind === 'typescript' ? ManifestPaths::layoutPath($unit->metadata['out_dir'] ?? null) : null;
            if ($outDir === null || $outDir === '') {
                continue;
            }
            $directory = ManifestPaths::manifestDirectory($unit->configPath);
            $rootDir = ManifestPaths::layoutPath($unit->metadata['root_dir'] ?? null);
            $layouts[] = [
                ManifestPaths::joinPath($directory, $outDir),
                $rootDir !== null ? [ManifestPaths::joinPath($directory, $rootDir)] : [$directory, ManifestPaths::joinPath($directory, 'src')],
            ];
        }

        return array_map(static function (ProjectUnit $unit) use ($layouts): ProjectUnit {
            if ($unit->kind !== 'node') {
                return $unit;
            }
            $metadata = $unit->metadata;
            $named = is_array($metadata['public_entry_points'] ?? null) ? $metadata['public_entry_points'] : null;
            foreach (['entry_points', 'public_entry_points'] as $key) {
                if (is_array($metadata[$key] ?? null) && $metadata[$key] !== []) {
                    $metadata[$key] = self::withSourcesOf($metadata[$key], $layouts);
                }
            }
            // A published entry the manifest names and no declared layout
            // covers: the bundler default.
            if ($named !== null && is_array($metadata['public_entry_points'] ?? null)) {
                $directory = ManifestPaths::manifestDirectory($unit->configPath);
                $published = array_fill_keys($metadata['public_entry_points'], true);
                foreach ($named as $entryPoint) {
                    if (!is_string($entryPoint) || self::underLayout($entryPoint, $layouts)) {
                        continue;
                    }
                    foreach (self::sourceTwins($entryPoint, $directory) as $twin) {
                        $published[$twin] = true;
                    }
                }
                $published = array_map(strval(...), array_keys($published));
                sort($published, SORT_STRING);
                $metadata['public_entry_points'] = $published;
            }

            return new ProjectUnit($unit->kind, $unit->configPath, $unit->contentHash, $metadata);
        }, $units);
    }

    /**
     * Whether a path lies in the `outDir` of a declared build layout.
     *
     * @param list<array{0: string, 1: list<string>}> $layouts
     */
    private static function underLayout(string $path, array $layouts): bool
    {
        foreach ($layouts as [$outDir]) {
            if (str_starts_with($path, $outDir . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Entry points with, beside each one in an `outDir`, the sources it is built from.
     *
     * @param list<mixed> $entryPoints
     * @param list<array{0: string, 1: list<string>}> $layouts
     * @return list<string>
     */
    private static function withSourcesOf(array $entryPoints, array $layouts): array
    {
        $paths = array_fill_keys(array_values(array_filter($entryPoints, is_string(...))), true);
        foreach ($entryPoints as $entryPoint) {
            foreach ($layouts as [$outDir, $sourceDirs]) {
                if (!is_string($entryPoint) || !str_starts_with($entryPoint, $outDir . '/')) {
                    continue;
                }
                $rest = substr($entryPoint, strlen($outDir) + 1);
                // A declaration the compiler emitted (`index.d.ts`) stands for
                // the source it describes, as the `.js` beside it does.
                if (preg_match('/^(.*)\.d\.(m|c)?ts$/', $rest, $declaration) === 1) {
                    $stem = $declaration[1];
                    $extension = ($declaration[2] ?? '') . 'js';
                } else {
                    $extension = strtolower(pathinfo($rest, PATHINFO_EXTENSION));
                    $stem = substr($rest, 0, -strlen($extension) - 1);
                }
                foreach (self::BUILD_OUTPUT_SOURCES[$extension] ?? [] as $sourceExtension) {
                    foreach ($sourceDirs as $sourceDir) {
                        $paths[ManifestPaths::joinPath($sourceDir, $stem . '.' . $sourceExtension)] = true;
                    }
                }
            }
        }
        $paths = array_map(strval(...), array_keys($paths));
        sort($paths, SORT_STRING);

        return $paths;
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

    /** Whether a path names a package or build manifest; see {@see SourceClassifier::isManifest()}. */
    public static function isManifest(string $relativePath): bool
    {
        return SourceClassifier::isManifest($relativePath);
    }

    /**
     * Whether a JavaScript file is `tsc` output beside its TypeScript source;
     * see {@see SourceClassifier::isCompiledSibling()}.
     *
     * @param callable(string): bool $exists whether a project-relative path exists
     */
    public static function isCompiledSibling(string $relativePath, string $absolutePath, callable $exists): bool
    {
        return SourceClassifier::isCompiledSibling($relativePath, $absolutePath, $exists);
    }

    /** Whether a path is configuration discovery always reads; see {@see SourceClassifier::isConfigurationFile()}. */
    public static function isConfigurationFile(string $relativePath): bool
    {
        return SourceClassifier::isConfigurationFile($relativePath);
    }

    /** The source language of a path, or null; see {@see SourceClassifier::languageFor()}. */
    public static function languageFor(string $relativePath, ?string $absolutePath = null): ?string
    {
        return SourceClassifier::languageFor($relativePath, $absolutePath);
    }

    /** The project unit kind a path configures, or null; see {@see SourceClassifier::unitKindFor()}. */
    public static function unitKindFor(string $relativePath): ?string
    {
        return SourceClassifier::unitKindFor($relativePath);
    }

    /** Whether a path's name can be carried by a stable id; see {@see SourceClassifier::isSupportedPath()}. */
    public static function isSupportedPath(string $relative): bool
    {
        return SourceClassifier::isSupportedPath($relative);
    }
}
