<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use DirectoryIterator;
use Knossos\Discovery\Manifest\Toml;
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

        // Cargo.toml is TOML, not JSON, exactly like pyproject.toml — feeding
        // either to JsonConfig::decode() below would fail to parse and drop
        // the unit as DISCOVERY_CONFIG_INVALID. Neither gets a real parser;
        // targeted regexes over the specific tables keep them self-contained.
        if ($kind === 'python') {
            return new ProjectUnit($kind, $relative, $contentHash, [
                'name' => Toml::tableString($contents, '[project]') ?? Toml::tableString($contents, '[tool.poetry]'),
                'requires' => self::pythonRequirements($contents),
                'entry_points' => self::pythonEntryPoints($contents, $relative),
                'library_roots' => self::pythonLibraryRoots($contents, $relative),
            ]);
        }
        if ($kind === 'cargo') {
            return new ProjectUnit($kind, $relative, $contentHash, [
                'name' => self::cargoPackageName($contents),
                'requires' => self::cargoRequirements($contents),
                'entry_points' => self::cargoEntryPoints($contents, $relative, dirname($absolute)),
            ]);
        }
        // Its patterns decide which files the walk takes, so an edit to one
        // changes what a rescan produces; it carries nothing else.
        if ($kind === 'gitignore') {
            return new ProjectUnit($kind, $relative, $contentHash);
        }
        if ($kind === 'requirements') {
            return new ProjectUnit($kind, $relative, $contentHash, [
                'requires' => self::pipRequirements($contents),
            ]);
        }
        if ($kind === 'html') {
            return new ProjectUnit($kind, $relative, $contentHash, [
                'entry_points' => self::htmlScriptEntryPoints($contents, $relative),
            ]);
        }
        if ($kind === 'yaml') {
            return new ProjectUnit($kind, $relative, $contentHash, [
                'entry_points' => self::yamlPathEntryPoints($contents, $relative),
                'class_names' => self::yamlClassNames($contents),
                ...self::loadedDirectories($contents, $relative),
            ]);
        }
        if ($kind === 'dockerfile') {
            // Read as text for paths, as a YAML file is: a build context is the
            // project root, so a path resolves there as well as beside the file.
            return new ProjectUnit($kind, $relative, $contentHash, [
                'entry_points' => self::yamlPathEntryPoints(self::withCopySources($contents), $relative),
            ]);
        }
        if ($kind === 'readme') {
            return new ProjectUnit($kind, $relative, $contentHash, [
                'entry_points' => self::readmeRunnerEntryPoints($contents, $relative),
            ]);
        }
        if ($kind === 'shell') {
            return new ProjectUnit($kind, $relative, $contentHash, [
                'entry_points' => self::shellPathEntryPoints($contents, $relative),
            ]);
        }
        if ($kind === 'agent_config') {
            // Read as text for paths, as a YAML file is: the commands are shell
            // lines, and `$CLAUDE_PLUGIN_ROOT/src/x.ts` leaves a token the path
            // reader anchors at the project root.
            return new ProjectUnit($kind, $relative, $contentHash, [
                'entry_points' => self::yamlPathEntryPoints(
                    // `$CLAUDE_PLUGIN_ROOT/src/x.ts` names `src/x.ts` in the
                    // plugin; left in, the variable's name reads as a directory.
                    preg_replace('#\$\{?[A-Za-z_][A-Za-z0-9_]*\}?/#', '/', self::jsonStrings($contents)) ?? '',
                    $relative,
                ),
            ]);
        }
        if ($kind === 'tool_config') {
            return new ProjectUnit($kind, $relative, $contentHash, self::toolConfigEntryPoints($contents, $relative));
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

        $metadata = match ($kind) {
            'composer' => [
                'name' => is_string($decoded['name'] ?? null) ? $decoded['name'] : null,
                'psr4' => self::composerPsr4($decoded),
                'library_roots' => self::composerLibraryRoots($decoded, $relative),
                'requires' => self::composerRequirements($decoded),
                'entry_points' => self::manifestEntryPoints($decoded, $relative, ['bin']),
            ],
            'node' => [
                'name' => is_string($decoded['name'] ?? null) ? $decoded['name'] : null,
                'type' => is_string($decoded['type'] ?? null) ? $decoded['type'] : null,
                'workspaces' => self::workspaces($decoded['workspaces'] ?? []),
                'typescript_range' => self::typescriptRange($decoded),
                'vue' => self::dependsOn($decoded, 'vue'),
                'entry_points' => [
                    ...self::manifestEntryPoints($decoded, $relative, ['bin', 'main', 'module']),
                    ...self::createReactAppEntryPoints($decoded, $relative, dirname($absolute)),
                ],
                'public_entry_points' => self::publicEntryPoints($decoded, $relative),
            ],
            'azure_function' => [
                'entry_points' => self::azureFunctionEntryPoints($decoded, $relative),
            ],
            'typescript' => self::typescriptMetadata($decoded),
            'knip' => ['entry_points' => self::knipEntryPoints($decoded, $relative)],
            'knossos' => ['version' => $decoded['version'] ?? null],
            default => [],
        };

        return new ProjectUnit($kind, $relative, $contentHash, $metadata);
    }

    /**
     * The crate name from a Cargo.toml's `[package]` table, or null when
     * there is none — a virtual workspace manifest declares no `[package]`
     * table at all, and null is the correct answer for it.
     *
     * Deliberately scoped to that one table: Cargo.toml can carry other
     * `name = "..."` keys under `[[bin]]`, `[[test]]`, `[dependencies.foo]`,
     * and similar tables, anywhere in the file, and in any order relative to
     * `[package]`. An unscoped first-match regex — the shape reused from
     * pyproject.toml, which has no such competing keys — picks up whichever
     * one happens to appear first, not the crate's own name.
     */
    private static function cargoPackageName(string $contents): ?string
    {
        return Toml::tableString($contents, '[package]');
    }

    /**
     * Dependency names from a PEP 621 `[project]` block: the `dependencies`
     * and each `optional-dependencies.<group>` list.
     *
     * Keys are what matter; version constraints, extras, and markers are not
     * parsed. `dependencies = ["Django>=4.2", "fastapi[all]"]` yields
     * `django` and `fastapi`, matching how composer requires are consumed.
     *
     * @return array<string, string>
     */
    private static function pythonRequirements(string $contents): array
    {
        $result = [];
        $lists = [];
        $block = Toml::tableBlock($contents, '[project]');
        if ($block !== null) {
            $lists = array_merge($lists, Toml::stringLists($block, ['dependencies']));
        }
        $block = Toml::tableBlock($contents, '[project.optional-dependencies]');
        if ($block !== null) {
            $lists = array_merge($lists, Toml::stringLists($block, null));
        }
        foreach ($lists as $raw) {
            // Name, before any version specifier, marker, extras, or "@ URL".
            $name = strtolower(preg_split('/\s*[<>=!~;@\[\]]\s*/', $raw, 2)[0]);
            if ($name !== '') {
                $result[$name] = $raw;
            }
        }
        foreach (self::poetryRequirements($contents) as $name => $raw) {
            $result[$name] ??= $raw;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Dependency names from Poetry's own tables.
     *
     * Poetry predates PEP 621 and states requirements as key/value tables
     * rather than a list: `[tool.poetry.dependencies]`, the legacy
     * `[tool.poetry.dev-dependencies]`, and
     * `[tool.poetry.group.<name>.dependencies]`. A single dependency may also
     * take its own sub-table, `[tool.poetry.dependencies.fastapi]`, where the
     * header carries the name and the body holds only its settings.
     *
     * The `python` key states the interpreter constraint rather than a
     * package, so it is not recorded as a requirement.
     *
     * @return array<string, string>
     */
    private static function poetryRequirements(string $contents): array
    {
        $tail = '(?:dependencies|dev-dependencies|group\.[^.\[\]]+\.dependencies)';
        $result = [];
        foreach (Toml::headers($contents) as $header) {
            $names = [];
            if (preg_match('/^tool\.poetry\.' . $tail . '$/', $header) === 1) {
                $block = Toml::tableBlock($contents, '[' . $header . ']');
                $names = $block === null ? [] : Toml::tableKeys($block);
            } elseif (preg_match('/^tool\.poetry\.' . $tail . '\.["\']?([A-Za-z0-9_.-]+)["\']?$/', $header, $m) === 1) {
                $names = [strtolower($m[1])];
            }
            foreach ($names as $name) {
                if ($name !== '' && $name !== 'python') {
                    $result[$name] = '1';
                }
            }
        }

        return $result;
    }

    /**
     * Requirement names from a pip requirements file.
     *
     * One name per non-comment, non-option line, before any version operator,
     * extras, environment marker, or `--hash` value. `-r other.txt` includes
     * are not followed (the file is analysed standalone); `-e` editable
     * installs resolve to a path and are skipped.
     *
     * @return array<string, string>
     */
    private static function pipRequirements(string $contents): array
    {
        $result = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim(preg_replace('/\s+#.*$/', '', $line) ?? $line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '-') || str_starts_with($line, '--')) {
                continue;
            }
            // Requirement specifiers: name, optional extras, version, marker.
            if (preg_match('/^([A-Za-z0-9_.-]+)/', $line, $m) !== 1) {
                continue;
            }
            $name = strtolower($m[1]);
            if ($name !== '') {
                $result[$name] = $line;
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Cargo.toml dependencies: the `[dependencies]`, `[dev-dependencies]`, and
     * `[build-dependencies]` tables, their target-scoped variants, and each
     * crate that takes a sub-table of its own.
     *
     * Keys are what matter; version constraints are not parsed.
     *
     * @return array<string, string>
     */
    private static function cargoRequirements(string $contents): array
    {
        $result = [];
        foreach (self::cargoDependencyHeaders($contents) as $header) {
            $block = Toml::tableBlock($contents, $header);
            if ($block === null) {
                continue;
            }
            foreach (Toml::tableKeys($block) as $dep) {
                $result[$dep] = '1';
            }
            foreach (Toml::inlinePackageNames($block) as $dep) {
                $result[$dep] = '1';
            }
        }
        foreach (self::cargoDependencySubTableNames($contents) as $dep) {
            $result[$dep] = '1';
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Every Cargo table header that holds dependencies.
     *
     * Covers `[dependencies]`, `[dev-dependencies]`, `[build-dependencies]`,
     * and target-scoped tables such as `[target.'cfg(unix)'.dependencies]`.
     * A `[dependencies.foo]` sub-table ends in the crate name rather than
     * `dependencies` and so is not one of these; its body holds version and
     * feature settings, not dependencies. cargoDependencySubTableNames()
     * reads the crate name out of that header instead.
     *
     * @return list<string>
     */
    private static function cargoDependencyHeaders(string $contents): array
    {
        $headers = [];
        foreach (Toml::headers($contents) as $header) {
            if (preg_match('/(?:^|[-.])(dependencies|dev-dependencies|build-dependencies)$/', $header) === 1) {
                $headers[] = '[' . $header . ']';
            }
        }

        return $headers;
    }

    /**
     * Crate names declared by a dependency's own sub-table header.
     *
     * `[dependencies.axum]` and `[target.'cfg(unix)'.dev-dependencies.axum]`
     * declare `axum` exactly as an `axum = "0.7"` line inside `[dependencies]`
     * does. The name is in the header and the body carries only that crate's
     * settings, so the body must not be read as a list of dependencies. The
     * one key that does name a crate is `package`, which renames it:
     * `[dependencies.rt]` with `package = "tokio"` depends on tokio, and both
     * names are recorded.
     *
     * @return list<string>
     */
    private static function cargoDependencySubTableNames(string $contents): array
    {
        $names = [];
        foreach (Toml::headers($contents) as $header) {
            if (preg_match('/(?:^|[-.])(?:dependencies|dev-dependencies|build-dependencies)\.["\']?([A-Za-z0-9_-]+)["\']?$/', $header, $m) !== 1) {
                continue;
            }
            $names[] = strtolower($m[1]);
            $block = Toml::tableBlock($contents, '[' . $header . ']');
            if ($block !== null && preg_match('/^[ \t]*package[ \t]*=[ \t]*["\']([^"\']+)["\']/m', $block, $renamed) === 1) {
                $names[] = strtolower($renamed[1]);
            }
        }

        return $names;
    }

    /**
     * pyproject.toml script entry points (`[project.scripts]` and Poetry's
     * `[tool.poetry.scripts]`), mapped to a module path.
     *
     * `module:func` → `module.py`; `module.sub:func` → `module/sub.py`. Only
     * entries whose path could name a real scanned file are kept — extension
     * filtering happens later anyway.
     *
     * @return list<string>
     */
    private static function pythonEntryPoints(string $contents, string $configPath): array
    {
        $directory = self::manifestDirectory($configPath);
        $scripts = [];
        foreach (['[project.scripts]', '[tool.poetry.scripts]'] as $header) {
            $block = Toml::tableBlock($contents, $header);
            if ($block === null) {
                continue;
            }
            // Script tables are `name = "module:func"` pairs, not lists.
            if (preg_match_all('/^\s*["\']?([A-Za-z0-9_.-]+)["\']?\s*=\s*["\']([^"\']+)["\']/m', $block, $s) > 0) {
                foreach ($s[2] as $target) {
                    $module = explode(':', $target, 2)[0];
                    // A single-segment module is the common `cli = "app:main"`
                    // shape the docblock above promises; only an empty module
                    // has no file to name.
                    if ($module === '') {
                        continue;
                    }
                    $path = self::entryPointPath(str_replace('.', '/', $module) . '.py', $directory);
                    if ($path !== null) {
                        $scripts[] = $path;
                    }
                }
            }
        }

        // vulture reads a whitelist as ordinary source through its `paths`.
        $vulture = Toml::tableBlock($contents, '[tool.vulture]');
        if ($vulture !== null && preg_match('/^\s*paths\s*=\s*\[([^\]]*)\]/m', $vulture, $paths) === 1
            && preg_match_all('/["\']([^"\']+\.py)["\']/', $paths[1], $files) > 0) {
            foreach ($files[1] as $file) {
                $path = self::entryPointPath($file, $directory);
                if ($path !== null) {
                    $scripts[] = $path;
                }
            }
        }

        $scripts = array_values(array_unique($scripts));
        sort($scripts, SORT_STRING);

        return $scripts;
    }

    /**
     * Cargo.toml [[bin]] entry points, plus Cargo's default paths.
     *
     * - A [[bin]] with a `path` maps to that path.
     * - A [[bin]] without a `path` defaults to `src/bin/<name>.rs`.
     * - A package with no [[bin]] at all has one implicit binary at
     *   `src/main.rs`. A lib-only crate emits no such file, so the path
     *   matches no node downstream and is dropped.
     *
     * @return list<string>
     */
    private static function cargoEntryPoints(string $contents, string $configPath, string $absoluteDirectory): array
    {
        $directory = self::manifestDirectory($configPath);
        $candidates = [];
        foreach (Toml::arrayTables($contents, '[[bin]]') as $bin) {
            if ($bin['path'] !== '') {
                $candidates[] = $bin['path'];
            } elseif ($bin['name'] !== '') {
                $candidates[] = 'src/bin/' . $bin['name'] . '.rs';
            }
        }
        // Auto-discovery finds src/main.rs and everything under src/bin/, and
        // it runs alongside any explicit [[bin]] rather than instead of it.
        // A virtual workspace has no [package] and so no binary of its own.
        // Cargo runs a package's build script before compiling it: the file
        // `build =` names, or a `build.rs` beside the manifest.
        $package = Toml::tableBlock($contents, '[package]');
        if ($package !== null) {
            if (preg_match('/^\s*build\s*=\s*"([^"]+)"/m', $package, $build) === 1) {
                $candidates[] = $build[1];
            } elseif (preg_match('/^\s*build\s*=\s*false\b/m', $package) !== 1 && is_file($absoluteDirectory . '/build.rs')) {
                $candidates[] = 'build.rs';
            }
        }
        if (self::cargoAutobins($contents) && $package !== null) {
            $candidates[] = 'src/main.rs';
            foreach (self::cargoDiscoveredBinaries($absoluteDirectory) as $discovered) {
                $candidates[] = $discovered;
            }
        }
        $paths = [];
        foreach ($candidates as $candidate) {
            $path = self::entryPointPath($candidate, $directory);
            if ($path !== null) {
                $paths[$path] = true;
            }
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Whether Cargo discovers binary targets from the file system.
     *
     * An explicit `autobins` decides it outright. Otherwise the edition does:
     * "For packages with the 2015 edition, the default for auto-discovery is
     * false if at least one target is manually defined in Cargo.toml.
     * Beginning with the 2018 edition, the default is always true."
     * An absent `edition` key means 2015.
     *
     * The prose says "at least one target", but the behaviour is per target
     * kind: only a hand-written `[[bin]]` turns binary discovery off. Verified
     * against cargo 1.94.1 with `cargo metadata`, which on the 2015 edition
     * still reports `src/main.rs` as a binary for a manifest declaring `[lib]`
     * or `[[test]]`, and stops reporting it only once a `[[bin]]` is declared.
     * `testDiscoverKeepsImplicitMainWhenOnlyNonBinTargetsAreDeclared` pins
     * that, so the looser reading cannot be applied here by mistake.
     */
    private static function cargoAutobins(string $contents): bool
    {
        $block = Toml::tableBlock($contents, '[package]');
        if ($block === null) {
            return false;
        }
        if (preg_match('/^[ \t]*autobins[ \t]*=[ \t]*(true|false)\b/m', $block, $explicit) === 1) {
            return $explicit[1] === 'true';
        }
        $edition = preg_match('/^[ \t]*edition[ \t]*=[ \t]*["\']([^"\']+)["\']/m', $block, $m) === 1 ? $m[1] : '2015';

        return $edition !== '2015' || Toml::arrayTables($contents, '[[bin]]') === [];
    }

    /**
     * Binaries Cargo finds under `src/bin/` without being told about them.
     *
     * Cargo reads these off the file system, so this does too: `src/bin/x.rs`
     * and `src/bin/x/main.rs` are both binary targets. Only files that exist
     * are returned, which is stricter than the inferred `src/main.rs` beside
     * it and cannot invent an entry point.
     *
     * @return list<string>
     */
    private static function cargoDiscoveredBinaries(string $absoluteDirectory): array
    {
        $found = [];
        foreach (glob($absoluteDirectory . '/src/bin/*.rs') ?: [] as $file) {
            $found[] = 'src/bin/' . basename($file);
        }
        foreach (glob($absoluteDirectory . '/src/bin/*/main.rs') ?: [] as $file) {
            $found[] = 'src/bin/' . basename(dirname($file)) . '/main.rs';
        }
        sort($found, SORT_STRING);

        return $found;
    }

    /** Extensions a scanner emits nodes for (or anything else cannot be matched later). */
    private const ENTRY_POINT_EXTENSIONS = [
        'php', 'js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'mts', 'cts', 'vue', 'svelte', 'astro', 'py', 'pyi', 'rs',
    ];

    /** The globs one brace pattern may name before it is dropped as hostile. */
    private const BRACE_EXPANSION_LIMIT = 256;

    /** The order react-scripts resolves `src/index` in; the first that exists is built. */
    private const CREATE_REACT_APP_EXTENSIONS = ['web.mjs', 'mjs', 'web.js', 'js', 'web.ts', 'ts', 'web.tsx', 'tsx', 'web.jsx', 'jsx'];

    /** Config keys whose files a tool leaves out rather than loads. */
    private const DROPPING_KEYS = ['exclude', 'excludes', 'ignore', 'ignores', 'ignored', 'external', 'externals'];

    /**
     * Config keys whose value names a file the tool LOADS.
     *
     * Deliberately a short allow-list rather than every key. A config also
     * names files to exclude, and an excluded path is exactly the kind that
     * turns out to be dead — suppressing it would hide the finding this
     * analysis exists to produce.
     */
    private const CONFIG_REFERENCE_KEYS = [
        'setupFiles', 'setupFilesAfterEnv', 'globalSetup', 'globalTeardown', 'entry', 'input',
        // Vite's server-side entry, and Cypress's plugins and support files.
        'ssr', 'pluginsFile', 'supportFile',
        // TypeORM loads what these name, usually by glob.
        'migrations', 'entities', 'subscribers',
        // Bundlers and desktop shells (esbuild, Bun, Electrobun).
        'entrypoint', 'entrypoints', 'entryPoints',
        // A process a tool starts, such as Playwright's `webServer.command`.
        'command',
    ];

    /**
     * Collect the source files a package manifest names, anchored to the
     * project root.
     *
     * Nothing in a project imports its own bin or its build scripts — npm and
     * Composer invoke them by name — so each has an in-degree of zero and reads
     * as unreferenced code. The manifest is the reference, and this is the only
     * place it is visible.
     *
     * `$fields` are read as paths directly (`bin`, `main`); `scripts` values are
     * shell commands and are tokenised, keeping only tokens that look like a
     * source file. That tokenising is deliberately loose because it cannot
     * produce a false positive on its own: classification matches these paths
     * exactly against emitted nodes, so a token naming something that is not a
     * scanned file simply never matches.
     *
     * @param array<string, mixed> $manifest
     * @param list<string> $fields
     * @return list<string>
     */
    private static function manifestEntryPoints(array $manifest, string $configPath, array $fields): array
    {
        $directory = self::manifestDirectory($configPath);
        $candidates = [];
        foreach ($fields as $field) {
            $value = $manifest[$field] ?? null;
            foreach (is_array($value) ? $value : [$value] as $entry) {
                if (is_string($entry)) {
                    $candidates[] = $entry;
                }
            }
        }
        if (is_array($manifest['scripts'] ?? null)) {
            foreach ($manifest['scripts'] as $command) {
                foreach (is_array($command) ? $command : [$command] as $line) {
                    if (is_string($line)) {
                        // Quotes and parentheses are token boundaries too, so a
                        // path inside `node -e "require('./x.js')"` is separated
                        // from the code around it.
                        $candidates = [...$candidates, ...preg_split('/[\s"\'()]+/', $line, -1, PREG_SPLIT_NO_EMPTY)];
                    }
                }
            }
        }

        $paths = [];
        foreach ($candidates as $candidate) {
            $path = self::entryPointPath($candidate, $directory);
            if ($path !== null) {
                $paths[$path] = true;
            }
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * The entry a Create React App package is built from: `react-scripts`
     * builds the first `src/index` file it finds, in its own extension order,
     * which its config names and nothing in the project imports.
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private static function createReactAppEntryPoints(array $manifest, string $configPath, string $absoluteDirectory): array
    {
        if (!self::dependsOn($manifest, 'react-scripts')) {
            return [];
        }
        $directory = self::manifestDirectory($configPath);
        foreach (self::CREATE_REACT_APP_EXTENSIONS as $extension) {
            if (is_file($absoluteDirectory . '/src/index.' . $extension)) {
                return [($directory === '' ? '' : $directory . '/') . 'src/index.' . $extension];
            }
        }

        return [];
    }

    /**
     * The handler an Azure Functions binding manifest points at, so it reaches
     * {@see ManifestEntryPointRule} like any other manifest's entry points.
     *
     * The host runs exactly the file `scriptFile` names, which is what makes
     * this worth reading: nothing in the project imports the handler, so its
     * in-degree is zero however live it is. A directory holding both `index.js`
     * and a stale `index.ts` made that visible — TypeScript's own module
     * resolution answers a sibling's `require('../management')` with the `.ts`,
     * leaving the `.js` the host actually executes looking like dead code.
     * Reading the manifest settles which of the two is the entry point on the
     * runtime's authority rather than the type checker's.
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private static function azureFunctionEntryPoints(array $manifest, string $configPath): array
    {
        $directory = self::manifestDirectory($configPath);
        $scriptFile = $manifest['scriptFile'] ?? null;
        if (is_string($scriptFile)) {
            $path = self::entryPointPath($scriptFile, $directory);

            return $path === null ? [] : [$path];
        }
        // `scriptFile` is optional, and most manifests leave it out: the host
        // then loads the conventional handler for the runtime from the
        // manifest's own directory. Two thirds of the 69 manifests in the
        // project that prompted this omit the key, so reading only the explicit
        // form would have covered a third of the handlers and left the rest
        // reported as reachable from nothing but their own tests.
        //
        // Gated on a non-empty `bindings` array, which is what makes a
        // `function.json` an Azure one rather than some other tool's file with
        // a generic name. Both conventional names are offered because matching
        // is by exact project-relative path: whichever the directory does not
        // hold matches nothing and costs nothing.
        if (!is_array($manifest['bindings'] ?? null) || $manifest['bindings'] === []) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(string $candidate): ?string => self::entryPointPath($candidate, $directory),
            ['index.js', '__init__.py'],
        ), static fn(?string $path): bool => $path !== null));
    }

    /**
     * The scripts an HTML shell loads, in every project-relative form the
     * reference could mean.
     *
     * A single-page application is entered through this tag and through nothing
     * else: no module in the project imports `main.tsx`, so its in-degree is
     * zero however live it is, and the same is true of a plain `<script>` that
     * publishes runtime configuration onto `window`.
     *
     * A root-absolute `src` is resolved against the WEB root rather than the
     * project root, and every common bundler serves a directory of untouched
     * assets there — `public/` for Vite, Create React App, Next and Astro,
     * `static/` for SvelteKit and Hugo. Which one applies cannot be known from
     * the HTML, so all three readings are offered. Matching downstream is by
     * exact path against something a scanner emitted, so the two that name no
     * file cost nothing; guessing wrong in the other direction would lose the
     * entry point silently.
     *
     * Deliberately only `<script src>`. A stylesheet or an image is not code
     * and could not be a dead-code candidate anyway, and scraping every `href`
     * would put ordinary prose links through the same suppression.
     *
     * @return list<string>
     */
    private static function htmlScriptEntryPoints(string $contents, string $configPath): array
    {
        $directory = self::manifestDirectory($configPath);
        if (preg_match_all('/<script\b[^>]*?\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $contents, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        $paths = [];
        foreach ($matches as $match) {
            $source = $match[3] ?? '';
            if (($match[1] ?? '') !== '') {
                $source = $match[1];
            } elseif (($match[2] ?? '') !== '') {
                $source = $match[2];
            }
            foreach (self::webRootReadings($source) as $candidate) {
                $path = self::entryPointPath($candidate, $directory);
                if ($path !== null) {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
    }

    /**
     * The ways one web-root-absolute reference could name a file on disk.
     *
     * A relative source is already project-relative once anchored and gets a
     * single reading. A leading slash means the web root, so the asset
     * directories bundlers serve there are prefixed as well.
     *
     * @return list<string>
     */
    private static function webRootReadings(string $source): array
    {
        $source = trim($source);
        if ($source === '' || !str_starts_with($source, '/')) {
            return [$source];
        }
        $bare = ltrim($source, '/');

        return [$bare, 'public/' . $bare, 'static/' . $bare];
    }

    /**
     * Config keys under which a YAML value names files to EXCLUDE rather than
     * load: `ignore:` in codecov.yml, `exclude:` in a pre-commit config,
     * `paths-ignore:` in a GitHub Actions workflow trigger, PHPStan's
     * `excludePaths:` with its `analyse:` and `analyseAndScan:` lists. A path appearing
     * only under one of these is not read as an entry point — the same
     * reasoning {@see self::CONFIG_REFERENCE_KEYS} applies to a tool's own
     * config module, restated here as a deny-list because YAML's exclusion
     * keys, unlike a tool config's load keys, are not enumerable in advance.
     */
    private const YAML_EXCLUSION_KEYS = ['exclude', 'ignore', 'paths-ignore', 'skip', 'exclude_paths', 'excludes', 'excludePaths', 'analyse', 'analyseAndScan'];

    /**
     * Every token in a YAML file shaped like a path to a source file.
     *
     * A Compose file mounts a config into a container, a CI workflow runs a
     * script by name, a deployment manifest names an entry module. None of
     * those is an import, so the file they name has an in-degree of zero while
     * being the reason the thing runs at all.
     *
     * No YAML parser is used, and the file is scanned as text. That is the same
     * bargain {@see self::manifestEntryPoints()} strikes with Composer's shell
     * commands: what makes it safe is not the precision of the tokenising but
     * the exactness of the matching. {@see ManifestEntryPointRule} compares
     * against paths a scanner actually emitted, so a token naming nothing is
     * inert, and the source-extension guard inside {@see self::entryPointPath()}
     * keeps image tags, version strings and action references out.
     *
     * Two narrowings keep the blanket tokenising from reading too much in:
     *
     * - A `#`-comment tail is stripped from every line before tokenising. A
     *   `#` inside a quoted scalar is not really a comment, but treating every
     *   `#` as one is the conservative direction — it can only cause a real
     *   path to be missed, never a wrongful suppression to be added.
     * - A token on a line scoped by {@see self::YAML_EXCLUSION_KEYS} — see
     *   {@see self::yamlExclusionLines()} — is dropped. YAML carries exclusion
     *   lists at least as often as it carries genuine references, and a path
     *   named only there is not loaded by anything; suppressing it would hide
     *   the finding this analysis exists to produce. This is the same call
     *   {@see self::toolConfigEntryPoints()} makes by being key-scoped outright.
     *
     * The character class stops at a colon, which is what splits a bind mount's
     * host path from its container path: both halves are offered and only the
     * half naming a real file can match.
     *
     * Two anchors, because YAML does not have one path convention. A Compose
     * bind mount is relative to the compose file's own directory; a CI
     * workflow's `run:` step executes with the repository root as its working
     * directory. Both readings are offered for every token and the one naming
     * no emitted file falls away, which is the same bargain {@see self::webRootReadings()}
     * strikes with a bundler's asset directories. A caller that knows of other
     * working directories, as a shell script's `cd` names them, adds them.
     *
     * @param list<string> $extraAnchors project-relative directories to read a path from as well
     * @return list<string>
     */
    private static function yamlPathEntryPoints(string $contents, string $configPath, array $extraAnchors = []): array
    {
        $directory = self::manifestDirectory($configPath);
        $extensions = implode('|', array_map(preg_quote(...), self::ENTRY_POINT_EXTENSIONS));
        $stripped = preg_replace('/#.*$/m', '', $contents) ?? $contents;
        if (preg_match_all(sprintf('#[A-Za-z0-9_./-]+\.(?:%s)\b#', $extensions), $stripped, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $excludedLines = self::yamlExclusionLines($stripped);
        $paths = [];
        $anchors = array_unique([$directory, '', ...$extraAnchors]);
        // One pass over the newlines, so the line lookup below is a search
        // rather than a rescan of the prefix for every matched token.
        $lineStarts = [0];
        for ($at = strpos($stripped, "\n"); $at !== false; $at = strpos($stripped, "\n", $at + 1)) {
            $lineStarts[] = $at + 1;
        }
        foreach ($matches[0] as [$token, $offset]) {
            if (isset($excludedLines[self::lineAt($lineStarts, $offset)])) {
                continue;
            }
            foreach ($anchors as $anchor) {
                $path = self::entryPointPath($token, $anchor);
                if ($path !== null) {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
    }

    /**
     * The files a README runs: a path given straight to an interpreter
     * (`node scripts/screenshot.mjs`, `npx tsx src/seed.ts`, `python3 x.py`).
     *
     * Prose mentions files for every reason, so a path only counts after a
     * runner. Read from the README's directory and from the project root, as
     * a YAML file's paths are.
     *
     * @return list<string>
     */
    private static function readmeRunnerEntryPoints(string $contents, string $configPath): array
    {
        $extensions = implode('|', array_map(preg_quote(...), self::ENTRY_POINT_EXTENSIONS));
        preg_match_all(
            sprintf('#\b(?:node|nodejs|deno(?:\s+run)?|bun(?:\s+run)?|tsx|ts-node|python3?|php)\s+(?:-{1,2}[a-z][\w-]*(?:=\S+)?\s+)*([A-Za-z0-9_./-]+\.(?:%s))\b#', $extensions),
            $contents,
            $matches,
        );

        return self::yamlPathEntryPoints(implode("\n", $matches[1]), $configPath);
    }

    /**
     * A Dockerfile with each path a `COPY` put in the image written as the
     * source it was copied from.
     *
     * `COPY scripts/probe /tmp/probe` then `RUN python3 /tmp/probe/check.py`
     * runs `scripts/probe/check.py`, but only the image path is written. Each
     * `COPY` line's destination is rewritten to its source on every other
     * line, the longest destination first; a `COPY` with several sources, or
     * from another stage, names no single source and is left alone.
     */
    private static function withCopySources(string $contents): string
    {
        $mappings = [];
        $lines = explode("\n", $contents);
        foreach ($lines as $line) {
            if (preg_match('#^\s*(?:COPY|ADD)\s+((?:--[a-z-]+=\S+\s+)*)(\S+)\s+(/\S+)\s*$#i', $line, $copy) !== 1
                || str_contains($copy[1], '--from=')
                || str_starts_with($copy[2], '/')
                || str_contains($copy[2], '..')) {
                continue;
            }
            $source = trim(str_starts_with($copy[2], './') ? substr($copy[2], 2) : $copy[2], '/');
            $destination = rtrim($copy[3], '/');
            // A file copied into a directory keeps its name there.
            if (str_ends_with($copy[3], '/') && str_contains(basename($source), '.')) {
                $destination .= '/' . basename($source);
            }
            if ($source !== '' && $source !== '.' && $destination !== '') {
                $mappings[$destination] = $source;
            }
        }
        if ($mappings === []) {
            return $contents;
        }
        uksort($mappings, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:COPY|ADD)\s/i', $line) === 1) {
                continue;
            }
            foreach ($mappings as $destination => $source) {
                $line = preg_replace('#(?<![\w./-])' . preg_quote($destination, '#') . '(?=/|\s|$|["\';|&])#', $source, $line) ?? $line;
            }
            $lines[$index] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * The files a shell script names by path, as a YAML file's are read.
     *
     * `cd server && npx tsx src/scripts/reset.ts` names a path relative to the
     * directory the line changed into, and a `cd` on a line of its own moves
     * the lines after it until its `case` branch or block ends. Each line is
     * read from the project root, the script's own directory and whatever
     * `cd` reaches it; a path no file answers to under any of them names
     * nothing.
     *
     * @return list<string>
     */
    private static function shellPathEntryPoints(string $contents, string $configPath): array
    {
        $directory = self::manifestDirectory($configPath);
        $paths = [];
        $current = [];
        foreach (explode("\n", $contents) as $line) {
            $line = preg_replace('/(?:^|\s)#.*$/', '', $line) ?? $line;
            $inline = self::shellCdTargets($line, $directory);
            $standalone = preg_match('/^\s*cd\s/', $line) === 1 && preg_match('/&&|;|\|/', $line) !== 1;
            foreach (self::yamlPathEntryPoints($line, $configPath, [...$current, ...$inline]) as $path) {
                $paths[$path] = true;
            }
            if ($standalone) {
                $current = $inline;
            } elseif (preg_match('/;;|^\s*(?:(?:esac|fi|done)\b|\})/', $line) === 1) {
                $current = [];
            }
        }

        return array_keys($paths);
    }

    /**
     * The project-relative directories the `cd` commands on one line change into.
     *
     * @return list<string>
     */
    private static function shellCdTargets(string $line, string $directory): array
    {
        preg_match_all('#\bcd\s+[\'"]?([A-Za-z0-9_][A-Za-z0-9_./-]*)#', $line, $matches);
        $anchors = [];
        foreach ($matches[1] as $target) {
            $target = rtrim(str_starts_with($target, './') ? substr($target, 2) : $target, '/');
            if ($target === '' || in_array('..', explode('/', $target), true)) {
                continue;
            }
            $anchors[$target] = true;
            if ($directory !== '') {
                $anchors[$directory . '/' . $target] = true;
            }
        }

        return array_keys($anchors);
    }

    /**
     * The 0-indexed line an offset falls on, by binary search over the line starts.
     *
     * @param list<int> $lineStarts Byte offset of each line's first character.
     */
    private static function lineAt(array $lineStarts, int $offset): int
    {
        $low = 0;
        $high = count($lineStarts) - 1;
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($lineStarts[$middle] <= $offset) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }
        return $low;
    }

    /**
     * Line numbers, 0-indexed to match `explode("\n", $contents)`, that fall
     * under one of {@see self::YAML_EXCLUSION_KEYS}.
     *
     * Two shapes are recognised, and recognised is all this does: a single
     * line carrying an inline value (`ignore: src/legacy/old.php`), and a
     * block sequence that immediately follows a bare `key:` line, one level
     * more indented (`ignore:` then `  - src/legacy/old.php`). An exclusion
     * list nested under an unrelated parent key, or one that resumes after a
     * blank or comment-only line breaks the sequence, is not tracked — a full
     * YAML indentation model would cover those too, at a cost this reader,
     * which does not otherwise parse YAML at all, is not paying.
     *
     * @return array<int, true>
     */
    private static function yamlExclusionLines(string $contents): array
    {
        $keys = implode('|', array_map(preg_quote(...), self::YAML_EXCLUSION_KEYS));
        $excluded = [];
        $blockIndent = null;
        foreach (explode("\n", $contents) as $index => $line) {
            if ($blockIndent !== null) {
                if (preg_match('/^(\s*)-\s/', $line, $item) === 1 && strlen($item[1]) > $blockIndent) {
                    $excluded[$index] = true;
                    continue;
                }
                $blockIndent = null;
            }
            if (preg_match('/^(\s*)(?:' . $keys . ')\s*:(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $excluded[$index] = true;
            if (trim($m[2]) === '') {
                $blockIndent = strlen($m[1]);
            }
        }

        return $excluded;
    }

    /**
     * The files a tool's own config module tells it to load.
     *
     * Vitest reads `setupFiles` before every test file, Jest reads
     * `setupFilesAfterEnv`, a bundler reads `entry`. Nothing in the project
     * imports any of them, so each has an in-degree of zero while running on
     * every invocation of the tool.
     *
     * The config module is scanned as ordinary source by the language worker
     * as well; this reads the same file a second time for its string literals,
     * which is cheaper and far narrower than teaching a worker which keys of
     * which config objects hold paths.
     *
     * Unlike {@see self::yamlPathEntryPoints()} this does NOT tokenise the
     * whole file, and the difference is the point. A config names files to
     * exclude beside the files it loads — `exclude: ['src/legacy/old.ts']` is
     * ordinary — and marking an excluded path as an entry point would suppress
     * precisely the candidate worth reporting. Only the keys in
     * {@see self::CONFIG_REFERENCE_KEYS} are read.
     *
     * The value may be one quoted string or an array of them, so the key match
     * captures either shape and the quoted tokens are pulled out of whichever
     * it turned out to be.
     *
     * @return array{entry_points: list<string>, entry_globs: list<string>}
     */
    private static function toolConfigEntryPoints(string $contents, string $configPath): array
    {
        $directory = self::manifestDirectory($configPath);
        $keys = implode('|', array_map(preg_quote(...), self::CONFIG_REFERENCE_KEYS));
        // A JSON config quotes its keys; a module config usually does not.
        $matched = preg_match_all(
            sprintf('/\b(?:%s)[\'"]?\s*:\s*(\[[^\]]*\]|[\'"`][^\'"`]*[\'"`])/', $keys),
            $contents,
            $matches,
            PREG_SET_ORDER,
        );
        $values = [];
        foreach ($matched === false ? [] : $matches as $match) {
            if (preg_match_all('/[\'"`]([^\'"`]+)[\'"`]/', $match[1], $tokens) !== false) {
                array_push($values, ...$tokens[1]);
            }
        }
        // Laravel Mix names its entries as the first argument of a call:
        // `mix.js('resources/js/app.js', 'public/js')`.
        if (basename($configPath) === 'webpack.mix.js'
            && preg_match_all('/\.(?:js|ts|typeScript|react|preact|vue)\(\s*[\'"`]([^\'"`]+)[\'"`]/', $contents, $calls) > 0) {
            array_push($values, ...$calls[1]);
        }
        // Cypress loads these unless its config names others or turns them off.
        if (basename($configPath) === 'cypress.json') {
            foreach (['pluginsFile' => 'cypress/plugins/index', 'supportFile' => 'cypress/support/index'] as $key => $default) {
                if (preg_match('/[\'"]' . $key . '[\'"]\s*:/', $contents) !== 1) {
                    array_push($values, $default . '.js', $default . '.ts');
                }
            }
        }
        $paths = [];
        $globs = [];
        // Anchored to the config's own directory, so `../shared/stub.ts` is resolved
        // there first; only a path that then leaves the project is dropped.
        foreach (self::anchoredConfigPaths($contents) as $anchored) {
            $resolved = self::withinProject($directory === '' ? $anchored : $directory . '/' . $anchored);
            $path = $resolved === null ? null : self::entryPointPath($resolved, '');
            if ($path !== null) {
                $paths[$path] = true;
            }
        }
        foreach ($values as $token) {
            // A `command` is a shell line; any other value is one path,
            // which a split on whitespace leaves whole.
            foreach (preg_split('/\s+/', trim($token)) ?: [] as $word) {
                if (str_contains($word, '*')) {
                    foreach (self::expandBraces($word) as $alternative) {
                        $glob = self::entryGlob($alternative, $directory);
                        if ($glob !== null) {
                            $globs[$glob] = true;
                        }
                    }
                    continue;
                }
                $path = self::entryPointPath($word, $directory);
                if ($path !== null) {
                    $paths[$path] = true;
                }
            }
        }

        return ['entry_points' => array_keys($paths), 'entry_globs' => array_keys($globs)];
    }

    /**
     * The paths a config maps a key to through a path anchored to itself:
     * `replacement: resolve(__dirname, 'visual/stubs.tsx')` in a bundler
     * alias, `'./AuthContext': path.join(__dirname, 'stub.ts')`, or
     * `fileURLToPath(new URL('./stub.ts', import.meta.url))`. An alias that
     * swaps one module for another is the only way to reach the replacement,
     * and no import names it.
     *
     * Only a value under a key: a bare anchored path (`root: resolve(...)`
     * is keyed too, but names a directory, which {@see self::entryPointPath()}
     * drops) and one under a key that drops files are left out.
     *
     * @return list<string> paths relative to the config's directory
     */
    private static function anchoredConfigPaths(string $contents): array
    {
        $quoted = '[\'"`][^\'"`]+[\'"`]';
        $pattern = sprintf(
            '/([A-Za-z_$][\w$]*|%1$s)\s*:\s*(?:(?:[A-Za-z_$][\w$]*\.)?(?:resolve|join)\(\s*__dirname((?:\s*,\s*%1$s)+)\s*\)|(?:fileURLToPath\(\s*)?new\s+URL\(\s*(%1$s)\s*,\s*import\.meta\.url\s*\))/',
            $quoted,
        );
        if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === false) {
            return [];
        }
        $paths = [];
        foreach ($matches as $match) {
            if (in_array(strtolower(trim($match[1], '\'"`')), self::DROPPING_KEYS, true)) {
                continue;
            }
            $arguments = $match[2] ?? $match[3] ?? '';
            preg_match_all('/[\'"`]([^\'"`]+)[\'"`]/', $arguments, $segments);
            $paths[] = implode('/', array_map(static fn(string $segment): string => trim($segment, '/'), $segments[1]));
        }

        return $paths;
    }

    /**
     * `$path` with its `.` and `..` segments folded, or null when it climbs
     * above the project root.
     */
    private static function withinProject(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /**
     * A glob's brace alternatives spelled out: `*{.js,.ts}` is `*.js` and
     * `*.ts`, the form TypeORM documents for its file lists. None at all when
     * they would pass {@see self::BRACE_EXPANSION_LIMIT}.
     *
     * @return list<string>
     */
    private static function expandBraces(string $glob): array
    {
        $expanded = [$glob];
        do {
            $next = [];
            $open = false;
            foreach ($expanded as $pattern) {
                if (preg_match('/\{([^{}]*)\}/', $pattern, $brace, PREG_OFFSET_CAPTURE) !== 1) {
                    $next[] = $pattern;
                    continue;
                }
                $open = true;
                foreach (explode(',', $brace[1][0]) as $option) {
                    // Each pair of alternatives doubles the count: a glob
                    // past the budget is dropped rather than expanded.
                    if (count($next) >= self::BRACE_EXPANSION_LIMIT) {
                        return [];
                    }
                    $next[] = substr_replace($pattern, $option, $brace[0][1], strlen($brace[0][0]));
                }
            }
            $expanded = $next;
        } while ($open);

        return $expanded;
    }

    /**
     * A glob a config names (`src/migrations/*.ts`) as a project-relative
     * pattern, or null when it leaves the config's directory or names no
     * source file.
     */
    private static function entryGlob(string $glob, string $directory): ?string
    {
        $glob = str_starts_with($glob, './') ? substr($glob, 2) : $glob;
        if ($glob === '' || str_starts_with($glob, '/') || str_contains($glob, '..')
            || !in_array(strtolower(pathinfo($glob, PATHINFO_EXTENSION)), self::ENTRY_POINT_EXTENSIONS, true)) {
            return null;
        }

        return $directory === '' ? $glob : $directory . '/' . $glob;
    }

    /**
     * The directory a manifest's entry-point paths resolve against.
     *
     * `dirname()` answers '.' for a manifest at the root. Only that exact
     * answer means "no directory" — trimming dots off the string instead
     * turned `.github/actions/setup` into `github/actions/setup`, a path
     * that matches no emitted node, so the entry point was lost silently.
     *
     * Every manifest reader needs this: ManifestEntryPointRule matches on the
     * exact project-relative path a scanner emitted, so a nested manifest's
     * `src/main.rs` has to be recorded as `services/api/src/main.rs`.
     */
    private static function manifestDirectory(string $configPath): string
    {
        $directory = dirname($configPath);

        return in_array($directory, ['.', '', DIRECTORY_SEPARATOR, '/'], true) ? '' : $directory;
    }

    /**
     * Normalise one manifest token to a root-relative source path, or null when
     * it is not one: a flag, a bare command, a glob, a dependency's binary, or
     * a path that climbs out of the project.
     */
    private static function entryPointPath(string $candidate, string $directory): ?string
    {
        $token = str_replace('\\', '/', trim($candidate));
        // `@php`, `@composer`, and `@script` are Composer's own indirections.
        if ($token === '' || str_starts_with($token, '-') || str_starts_with($token, '@')) {
            return null;
        }
        if (str_contains($token, '*') || str_contains($token, '..')) {
            return null;
        }
        if (!in_array(strtolower(pathinfo($token, PATHINFO_EXTENSION)), self::ENTRY_POINT_EXTENSIONS, true)) {
            return null;
        }
        $token = ltrim($token, '/');
        if (str_starts_with($token, './')) {
            $token = substr($token, 2);
        }
        if ($token === '' || str_starts_with($token, 'node_modules/') || str_starts_with($token, 'vendor/')) {
            return null;
        }

        return $directory === '' ? $token : $directory . '/' . $token;
    }

    /**
     * Every string value in a JSON document, one per line, or the raw text when it does not parse.
     *
     * JSON may escape a slash as `\/`, which hides a path from a reader of
     * the raw text; the decoded strings carry it plainly.
     */
    private static function jsonStrings(string $contents): string
    {
        try {
            $decoded = JsonConfig::decode($contents, true);
        } catch (DiscoveryException) {
            return $contents;
        }
        $strings = [];
        array_walk_recursive($decoded, static function (mixed $value) use (&$strings): void {
            if (is_string($value)) {
                $strings[] = $value;
            }
        });

        return implode("\n", $strings);
    }

    /**
     * The files knip is told are entry points: `entry` at the top and in each workspace.
     *
     * Read key by key rather than as text, because the same file's `ignore`
     * and `project` lists name files that are not entry points at all.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private static function knipEntryPoints(array $config, string $configPath): array
    {
        $directory = self::manifestDirectory($configPath);
        $scopes = [[$directory, $config['entry'] ?? null]];
        foreach (is_array($config['workspaces'] ?? null) ? $config['workspaces'] : [] as $workspace => $settings) {
            if (is_string($workspace) && is_array($settings) && !str_contains($workspace, '*')) {
                $scopes[] = [self::joinPath($directory, trim($workspace, './')), $settings['entry'] ?? null];
            }
        }
        $paths = [];
        foreach ($scopes as [$anchor, $entries]) {
            foreach (is_array($entries) ? $entries : [$entries] as $entry) {
                $path = is_string($entry) ? self::entryPointPath($entry, $anchor) : null;
                if ($path !== null) {
                    $paths[$path] = true;
                }
            }
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * The modules a library publishes: its `main`, `module`, `types` and every
     * path under `exports`, for a package that is not private. What they
     * re-export is API for consumers outside the repository.
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private static function publicEntryPoints(array $manifest, string $configPath): array
    {
        if (($manifest['private'] ?? false) === true) {
            return [];
        }
        $candidates = [];
        foreach (['main', 'module', 'types', 'typings'] as $field) {
            if (is_string($manifest[$field] ?? null)) {
                $candidates[] = $manifest[$field];
            }
        }
        if (is_string($manifest['exports'] ?? null) || is_array($manifest['exports'] ?? null)) {
            $exports = is_array($manifest['exports']) ? $manifest['exports'] : [$manifest['exports']];
            array_walk_recursive($exports, static function (mixed $value) use (&$candidates): void {
                if (is_string($value)) {
                    $candidates[] = $value;
                }
            });
        }
        $directory = self::manifestDirectory($configPath);
        $paths = [];
        foreach ($candidates as $candidate) {
            $path = self::entryPointPath($candidate, $directory);
            if ($path !== null) {
                $paths[$path] = true;
            }
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);

        return $paths;
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
     * Whether a package.json lists a package in any of its dependency tables.
     *
     * @param array<string, mixed> $manifest
     */
    private static function dependsOn(array $manifest, string $package): bool
    {
        foreach (['dependencies', 'devDependencies', 'peerDependencies'] as $table) {
            if (is_array($manifest[$table] ?? null) && array_key_exists($package, $manifest[$table])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `typescript` version a package.json declares, from the first dependency table naming it.
     *
     * @param array<string, mixed> $manifest
     */
    private static function typescriptRange(array $manifest): ?string
    {
        foreach (['devDependencies', 'dependencies', 'peerDependencies'] as $table) {
            $range = is_array($manifest[$table] ?? null) ? ($manifest[$table]['typescript'] ?? null) : null;
            if (is_string($range)) {
                return $range;
            }
        }

        return null;
    }

    /**
     * PHP class names a YAML file mentions: `class: App\\Doctrine\\Filter`, a
     * service id, a listener. Loose on purpose, for the reason the path
     * reader is: a name that maps to no scanned file matches nothing.
     *
     * @return list<string>
     */
    private static function yamlClassNames(string $contents): array
    {
        $stripped = preg_replace('/#.*$/m', '', $contents) ?? $contents;
        preg_match_all('/(?<![\\\\\w])[A-Z][A-Za-z0-9_]*(?:\\\\{1,2}[A-Z][A-Za-z0-9_]*)+/', $stripped, $matches);
        $names = [];
        foreach ($matches[0] as $match) {
            $names[str_replace('\\\\', '\\', $match)] = true;
        }
        $names = array_keys($names);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The directories a Doctrine Migrations config loads migrations from.
     *
     * `migrations_paths` maps a namespace to a directory, usually under
     * `%kernel.project_dir%`; Doctrine loads every class there and nothing
     * imports one. Only a block directly under that key is read, and a
     * directory outside the project names nothing.
     *
     * @return list<string>
     */
    private static function doctrineMigrationDirectories(string $contents): array
    {
        $directories = [];
        $indent = null;
        foreach (explode("\n", $contents) as $line) {
            // A comment tail is not part of the value.
            $line = preg_replace('/(?:^|\s)#.*$/', '', $line) ?? $line;
            if ($indent === null) {
                if (preg_match('/^(\s*)migrations_paths\s*:\s*$/', $line, $key) === 1) {
                    $indent = strlen($key[1]);
                }
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^(\s*)\S/', $line, $lead) !== 1 || strlen($lead[1]) <= $indent) {
                $indent = null;
                continue;
            }
            if (preg_match('/:\s*[\'"]?(?:%kernel\.project_dir%\/)?([A-Za-z0-9_.\/-]+?)\/?[\'"]?\s*$/', $line, $value) !== 1) {
                continue;
            }
            $directory = $value[1];
            if (str_starts_with($directory, './')) {
                $directory = substr($directory, 2);
            }
            if ($directory !== '' && !str_starts_with($directory, '/') && !in_array('..', explode('/', $directory), true)) {
                $directories[$directory] = true;
            }
        }

        return array_keys($directories);
    }

    /**
     * Tags whose owner calls every service carrying them: a message bus, the
     * event dispatcher, the console, the router, Twig, the validator, forms,
     * security. A tag like `container.no_preload` only configures the
     * container and proves nothing about use.
     */
    private const INVOKING_TAG = '/(?:handler|listener|subscriber|command|controller|voter|extension|constraint_validator|form\.type|scheduler\.task)(?:$|[._])/i';

    /**
     * The directories a tagged Symfony `resource:` block registers every class
     * in, and the paths its `exclude:` leaves out.
     *
     * `App\\Handler\\: { resource: '../src/Handler', tags: [...] }`, inline or as a
     * block, makes each class there a service the tag's owner (a message bus,
     * an event dispatcher, the console) calls, and nothing in PHP names them.
     * Only a tag whose owner invokes what carries it counts
     * ({@see self::INVOKING_TAG}); a block without one only autowires, which
     * says nothing about use. Paths are relative to the config file, or to
     * the project under `%kernel.project_dir%`; one outside the project names
     * nothing.
     *
     * @return array{directories: list<string>, exclusions: list<string>}
     */
    private static function taggedResourceDirectories(string $contents, string $configPath): array
    {
        $directories = [];
        $exclusions = [];
        $base = self::manifestDirectory($configPath);
        $resolve = static function (string $path) use ($base): ?string {
            $path = trim($path, "'\" ");
            $prefix = $base;
            if (str_starts_with($path, '%kernel.project_dir%/')) {
                $path = substr($path, strlen('%kernel.project_dir%/'));
                $prefix = '';
            }
            $segments = [];
            foreach (explode('/', ($prefix === '' ? '' : $prefix . '/') . $path) as $segment) {
                if ($segment === '..') {
                    if ($segments === []) {
                        return null;
                    }
                    array_pop($segments);
                } elseif ($segment !== '' && $segment !== '.') {
                    $segments[] = $segment;
                }
            }

            return $segments === [] ? null : implode('/', $segments);
        };
        $values = static fn(string $text): array => array_values(array_filter(array_map(
            static fn(string $item): string => trim($item, " '\"{}"),
            preg_split('/,/', trim($text, ' []')) ?: [],
        ), static fn(string $item): bool => $item !== ''));
        $tagNames = static function (string $text): array {
            if (preg_match_all('/\bname\s*:\s*[\'"]?([\w.\-]+)/', $text, $named) > 0) {
                return $named[1];
            }
            preg_match_all('/[\'"]?([A-Za-z_][\w.\-]*)[\'"]?/', trim($text, ' []-'), $bare);

            return $bare[1];
        };
        $flush = static function (?array $block) use (&$directories, &$exclusions, $resolve): void {
            if ($block === null || $block['resource'] === null) {
                return;
            }
            $invoked = false;
            foreach ($block['tags'] as $tag) {
                $invoked = $invoked || preg_match(self::INVOKING_TAG, $tag) === 1;
            }
            $directory = $invoked ? $resolve(rtrim(preg_replace('/[*{].*$/', '', $block['resource']) ?? '', '/')) : null;
            if ($directory === null) {
                return;
            }
            $directories[$directory] = true;
            foreach ($block['exclude'] as $pattern) {
                $excluded = $resolve($pattern);
                if ($excluded !== null) {
                    $exclusions[$excluded] = true;
                }
            }
        };
        $block = null;
        foreach (explode("\n", $contents) as $line) {
            $line = preg_replace('/(?:^|\s)#.*$/', '', $line) ?? $line;
            if (trim($line) === '') {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line));
            if ($block !== null && $indent <= $block['indent']) {
                $flush($block);
                $block = null;
            }
            if (preg_match('/^\s*[\'"]?[A-Za-z_][A-Za-z0-9_\\\\]*\\\\[\'"]?\s*:\s*$/', $line) === 1) {
                $block = ['indent' => $indent, 'resource' => null, 'exclude' => [], 'tags' => [], 'list' => null, 'listIndent' => 0];
                continue;
            }
            // The inline form: `App\\Listener\\: { resource: '...', tags: [...] }`.
            if (preg_match('/^\s*[\'"]?[A-Za-z_][A-Za-z0-9_\\\\]*\\\\[\'"]?\s*:\s*\{(.*)\}\s*$/', $line, $inline) === 1) {
                $exclude = preg_match('/\bexclude\s*:\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])/', $inline[1], $excluded) === 1 ? $values($excluded[1]) : [];
                $tags = preg_match('/\btags\s*:\s*(\[.*\])/', $inline[1], $tagged) === 1 ? $tagNames($tagged[1]) : [];
                $flush([
                    'resource' => preg_match('/\bresource\s*:\s*[\'"]?([^\'"\s,}]+)/', $inline[1], $resource) === 1 ? $resource[1] : null,
                    'exclude' => $exclude,
                    'tags' => $tags,
                ]);
                continue;
            }
            if ($block === null) {
                continue;
            }
            if ($block['list'] !== null && $indent > $block['listIndent']) {
                if ($block['list'] === 'exclude') {
                    $block['exclude'][] = trim(ltrim(trim($line), '- '), "'\"");
                } else {
                    array_push($block['tags'], ...$tagNames(ltrim(trim($line), '- ')));
                }
                continue;
            }
            $block['list'] = null;
            if (preg_match('/^\s*resource\s*:\s*[\'"]?([^\'"\s]+)/', $line, $resource) === 1) {
                $block['resource'] = $resource[1];
            } elseif (preg_match('/^\s*(exclude|tags)\s*:\s*(.*)$/', $line, $key) === 1) {
                if (trim($key[2]) === '') {
                    $block['list'] = $key[1];
                    $block['listIndent'] = $indent;
                } elseif ($key[1] === 'exclude') {
                    array_push($block['exclude'], ...$values($key[2]));
                } else {
                    array_push($block['tags'], ...$tagNames($key[2]));
                }
            }
        }
        $flush($block);

        return ['directories' => array_keys($directories), 'exclusions' => array_keys($exclusions)];
    }

    /**
     * The directories a YAML file loads every class from, and what it excludes.
     *
     * @return array{loaded_directories: list<string>, loaded_exclusions: list<string>}
     */
    private static function loadedDirectories(string $contents, string $configPath): array
    {
        $tagged = self::taggedResourceDirectories($contents, $configPath);

        return [
            'loaded_directories' => [...self::doctrineMigrationDirectories($contents), ...$tagged['directories']],
            'loaded_exclusions' => $tagged['exclusions'],
        ];
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
            $directory = self::manifestDirectory($unit->configPath);
            foreach ($unit->metadata['psr4'] as $namespace => $paths) {
                foreach (is_array($paths) ? $paths : [$paths] as $path) {
                    if (is_string($namespace) && is_string($path) && $namespace !== '') {
                        $prefixes[] = [$namespace, self::joinPath($directory, trim($path, '/'))];
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
                        $paths[self::joinPath($directory, $rest . '.php')] = true;
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
            $outDir = $unit->kind === 'typescript' ? self::layoutPath($unit->metadata['out_dir'] ?? null) : null;
            if ($outDir === null || $outDir === '') {
                continue;
            }
            $directory = self::manifestDirectory($unit->configPath);
            $rootDir = self::layoutPath($unit->metadata['root_dir'] ?? null);
            $layouts[] = [
                self::joinPath($directory, $outDir),
                $rootDir !== null ? [self::joinPath($directory, $rootDir)] : [$directory, self::joinPath($directory, 'src')],
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
                $directory = self::manifestDirectory($unit->configPath);
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
                        $paths[self::joinPath($sourceDir, $stem . '.' . $sourceExtension)] = true;
                    }
                }
            }
        }
        $paths = array_map(strval(...), array_keys($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** A tsconfig directory option as a clean relative path, or null when it is absent or leaves its directory. */
    private static function layoutPath(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $path = trim(str_replace('\\', '/', trim($value)), '/');
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        if ($path === '.') {
            return '';
        }
        if (str_starts_with($value, '/') || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return $path;
    }

    /** Two project-relative path parts joined, either of which may be the root `''`. */
    private static function joinPath(string $directory, string $path): string
    {
        return trim($directory === '' ? $path : ($path === '' ? $directory : $directory . '/' . $path), '/');
    }

    /**
     * The directories a Composer library publishes: its `autoload` PSR-4
     * roots, when the manifest says `"type": "library"`. Its public classes
     * and methods are called by the code that installs it, not by anything
     * in the repository. Composer's own default type is library, but an
     * application that never named its type is the common case, so only an
     * explicit one counts; `autoload-dev` publishes nothing.
     *
     * @param array<string, mixed> $composer
     * @return list<string>
     */
    private static function composerLibraryRoots(array $composer, string $relative): array
    {
        if (($composer['type'] ?? null) !== 'library') {
            return [];
        }
        $psr4 = $composer['autoload']['psr-4'] ?? null;
        $roots = [];
        foreach (is_array($psr4) ? $psr4 : [] as $paths) {
            foreach (is_array($paths) ? $paths : [$paths] as $path) {
                $clean = self::layoutPath($path);
                if ($clean !== null) {
                    $roots[self::joinPath(self::manifestDirectory($relative), $clean)] = true;
                }
            }
        }
        $roots = array_map(strval(...), array_keys($roots));
        sort($roots, SORT_STRING);

        return $roots;
    }

    /**
     * The directory a Python library publishes: its pyproject's own, when the
     * project has something to build (`[build-system]` beside `[project]` or
     * Poetry's table, and not `package-mode = false`) and installs no command. A package that installs a
     * command is an application, whose functions nothing outside calls.
     *
     * @return list<string>
     */
    private static function pythonLibraryRoots(string $contents, string $relative): array
    {
        $buildable = preg_match('/^\[build-system\]/m', $contents) === 1
            && preg_match('/^\[(?:project|tool\.poetry)\]/m', $contents) === 1;
        $installsCommand = preg_match('/^\[(?:project\.(?:gui-)?scripts|tool\.poetry\.scripts)\]/m', $contents) === 1;
        // Poetry writes a build system for every project; one that is no
        // package says so with `package-mode = false`.
        $notAPackage = preg_match('/^\s*package-mode\s*=\s*false\b/m', $contents) === 1;

        return $buildable && !$installsCommand && !$notAPackage ? [self::manifestDirectory($relative)] : [];
    }

    /**
     * PSR-4 roots from composer.json, which is how a namespace maps to a directory.
     *
     * @param array<string, mixed> $composer @return array<string, string|list<string>>
     */
    private static function composerPsr4(array $composer): array
    {
        $mappings = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $psr4 = $composer[$section]['psr-4'] ?? null;
            if (!is_array($psr4)) {
                continue;
            }
            foreach ($psr4 as $namespace => $paths) {
                if (!is_string($namespace) || (!is_string($paths) && !is_array($paths))) {
                    continue;
                }
                if (is_array($paths) && !array_is_list($paths)) {
                    continue;
                }
                $mappings[$namespace] = $paths;
            }
        }

        ksort($mappings, SORT_STRING);
        return $mappings;
    }

    /**
     * Declared Composer dependencies, used to detect which frameworks to enrich.
     *
     * @param array<string, mixed> $composer @return array<string, string>
     */
    private static function composerRequirements(array $composer): array
    {
        $requirements = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach (is_array($composer[$section] ?? null) ? $composer[$section] : [] as $package => $constraint) {
                if (is_string($package) && is_string($constraint)) {
                    $requirements[strtolower($package)] = $constraint;
                }
            }
        }
        ksort($requirements, SORT_STRING);
        return $requirements;
    }

    /**
     * Workspace globs from package.json, so a monorepo's packages are discovered.
     *
     * @return list<string>
     */
    private static function workspaces(mixed $workspaces): array
    {
        if (is_array($workspaces) && isset($workspaces['packages'])) {
            $workspaces = $workspaces['packages'];
        }
        if (!is_array($workspaces) || !array_is_list($workspaces)) {
            return [];
        }

        return array_values(array_filter($workspaces, 'is_string'));
    }

    /**
     * tsconfig details the TypeScript worker needs to build a program.
     *
     * @param array<string, mixed> $config @return array<string, mixed>
     */
    private static function typescriptMetadata(array $config): array
    {
        $compiler = is_array($config['compilerOptions'] ?? null) ? $config['compilerOptions'] : [];
        $references = [];
        if (is_array($config['references'] ?? null)) {
            foreach ($config['references'] as $reference) {
                if (is_array($reference) && is_string($reference['path'] ?? null)) {
                    $references[] = $reference['path'];
                }
            }
        }

        return [
            'extends' => is_string($config['extends'] ?? null) ? $config['extends'] : null,
            'allow_js' => ($compiler['allowJs'] ?? false) === true,
            'base_url' => is_string($compiler['baseUrl'] ?? null) ? $compiler['baseUrl'] : null,
            'out_dir' => is_string($compiler['outDir'] ?? null) ? $compiler['outDir'] : null,
            'root_dir' => is_string($compiler['rootDir'] ?? null) ? $compiler['rootDir'] : null,
            'paths' => is_array($compiler['paths'] ?? null) ? $compiler['paths'] : [],
            'references' => $references,
        ];
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
