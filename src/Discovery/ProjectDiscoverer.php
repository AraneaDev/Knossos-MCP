<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use DirectoryIterator;
use Knossos\Classification\ToolConfigModuleRule;
use Knossos\Scan\CancellationToken;
use RuntimeException;

/**
 * Walks a project tree and selects the files worth analysing.
 *
 * Enforces the allow-list, applies ignore rules, and stops at the configured
 * caps. Unreadable entries become diagnostics rather than exceptions, so one
 * permission problem does not deny a graph of everything else.
 */
final readonly class ProjectDiscoverer
{
    /** Bytes read when probing an extensionless file's shebang; one short line is enough. */
    private const SHEBANG_PROBE_BYTES = 256;
    private RootGuard $rootGuard;
    private IgnoreMatcher $ignoreMatcher;
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
        $this->ignoreMatcher = new IgnoreMatcher($config->ignorePatterns);
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

        while ($stack !== []) {
            $directory = array_pop($stack);
            $this->readGitIgnore($root, $directory, $gitIgnore, $gitIgnoreReads);
            try {
                // UnexpectedValueException, which is a RuntimeException, is what
                // DirectoryIterator throws for a directory it cannot open. Caught
                // by that name rather than as Throwable so a programming error in
                // the walk is not filed as an unreadable directory.
                $entries = new DirectoryIterator($directory);
            } catch (RuntimeException $error) {
                $diagnostics[] = new DiscoveryDiagnostic(
                    'warning',
                    'DISCOVERY_DIRECTORY_UNREADABLE',
                    $error->getMessage(),
                    $this->relative($root, $directory),
                );
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry->isDot()) {
                    continue;
                }

                // Discovery walks and hashes up to maxFiles entries — the longest
                // non-worker stage. Poll cancellation periodically so a client's
                // notifications/cancelled is observable here, not just around RPCs.
                if ($cancellation !== null && (++$seen % 512) === 0) {
                    $cancellation->throwIfCancelled();
                }

                $absolute = str_replace('\\', '/', $entry->getPathname());
                $relative = $this->relative($root, $absolute);
                if (!self::isConfigurationFile($relative) && $this->ignoreMatcher->matches($relative)) {
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
                    if (!self::isConfigurationFile($relative) && $gitIgnore->ignores($relative, false)) {
                        continue;
                    }

                    $language = self::languageFor($relative, $absolute);
                    $unitKind = self::unitKindFor($relative);
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

        return self::result($root, $files, $units, $diagnostics, $unparsedManifestHashes);
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
     */
    private static function result(string $root, array $files, array $units, array $diagnostics, array $unparsedManifestHashes): DiscoveryResult
    {
        $files = self::withoutCompiledSiblings($files);
        usort($files, static fn(DiscoveredFile $left, DiscoveredFile $right): int =>
            $left->relativePath <=> $right->relativePath);
        usort($units, static fn(ProjectUnit $left, ProjectUnit $right): int =>
            [$left->kind, $left->configPath] <=> [$right->kind, $right->configPath]);
        $units = self::withBuildOutputSources($units);
        $units = self::withClassNameEntryPoints($units);
        $units = self::withMigrationEntryPoints($units, $files);

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
                'name' => self::tableString($contents, '[project]') ?? self::tableString($contents, '[tool.poetry]'),
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
                'migration_directories' => self::doctrineMigrationDirectories($contents),
            ]);
        }
        if ($kind === 'dockerfile') {
            // Read as text for paths, as a YAML file is: a build context is the
            // project root, so a path resolves there as well as beside the file.
            return new ProjectUnit($kind, $relative, $contentHash, [
                'entry_points' => self::yamlPathEntryPoints($contents, $relative),
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
            return new ProjectUnit($kind, $relative, $contentHash, [
                'entry_points' => self::toolConfigEntryPoints($contents, $relative),
            ]);
        }

        try {
            $decoded = JsonConfig::decode($contents, in_array($kind, ['typescript', 'knossos'], true));
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
                'entry_points' => self::manifestEntryPoints($decoded, $relative, ['bin', 'main', 'module']),
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
        return self::tableString($contents, '[package]');
    }

    /**
     * A string key from a `[header]` table's scope, or null.
     */
    private static function tableString(string $contents, string $header): ?string
    {
        if (preg_match('/^[ \t]*' . preg_quote($header, '/') . '[ \t]*(?:#.*)?\r?$/m', $contents, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $rest = substr($contents, $m[0][1] + strlen($m[0][0]));
        $end = preg_match('/^[ \t]*\[/m', $rest, $next, PREG_OFFSET_CAPTURE) === 1
            ? $next[0][1]
            : strlen($rest);
        $table = substr($rest, 0, $end);
        if (preg_match('/^\s*name\s*=\s*["\']([^"\']+)["\']/m', $table, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract [[bin]]/[[test]]/[[example]] array-of-table blocks from a TOML.
     *
     * @return list<array{name: string, path: string}>
     */
    private static function arrayTables(string $contents, string $header): array
    {
        $found = [];
        $offset = 0;
        $pattern = '/^[ \t]*' . preg_quote($header, '/') . '[ \t]*(?:#.*)?\r?$/m';
        while (preg_match($pattern, $contents, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1] + strlen($m[0][0]);
            $next = preg_match('/^[ \t]*\[/m', $contents, $n, PREG_OFFSET_CAPTURE, $start) === 1
                ? $n[0][1]
                : strlen($contents);
            $block = substr($contents, $start, $next - $start);
            $name = $path = '';
            if (preg_match('/^\s*name\s*=\s*["\']([^"\']+)["\']/m', $block, $nm) === 1) {
                $name = $nm[1];
            }
            if (preg_match('/^\s*path\s*=\s*["\']([^"\']+)["\']/m', $block, $pm) === 1) {
                $path = $pm[1];
            }
            $found[] = ['name' => $name, 'path' => $path];
            $offset = $next;
        }

        return $found;
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
        $block = self::tableBlock($contents, '[project]');
        if ($block !== null) {
            $lists = array_merge($lists, self::tomlStringLists($block, ['dependencies']));
        }
        $block = self::tableBlock($contents, '[project.optional-dependencies]');
        if ($block !== null) {
            $lists = array_merge($lists, self::tomlStringLists($block, null));
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
        foreach (self::tomlHeaders($contents) as $header) {
            $names = [];
            if (preg_match('/^tool\.poetry\.' . $tail . '$/', $header) === 1) {
                $block = self::tableBlock($contents, '[' . $header . ']');
                $names = $block === null ? [] : self::tomlTableKeys($block);
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
            $block = self::tableBlock($contents, $header);
            if ($block === null) {
                continue;
            }
            foreach (self::tomlTableKeys($block) as $dep) {
                $result[$dep] = '1';
            }
            foreach (self::tomlInlinePackageNames($block) as $dep) {
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
        foreach (self::tomlHeaders($contents) as $header) {
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
        foreach (self::tomlHeaders($contents) as $header) {
            if (preg_match('/(?:^|[-.])(?:dependencies|dev-dependencies|build-dependencies)\.["\']?([A-Za-z0-9_-]+)["\']?$/', $header, $m) !== 1) {
                continue;
            }
            $names[] = strtolower($m[1]);
            $block = self::tableBlock($contents, '[' . $header . ']');
            if ($block !== null && preg_match('/^[ \t]*package[ \t]*=[ \t]*["\']([^"\']+)["\']/m', $block, $renamed) === 1) {
                $names[] = strtolower($renamed[1]);
            }
        }

        return $names;
    }

    /**
     * Crate names an inline dependency table renames with a `package` key.
     *
     * `web = { package = "actix-web", version = "4" }` depends on actix-web
     * under the local name `web`. Both are recorded: the alias is what source
     * code writes in a `use`, and the real crate name is what framework
     * detection in ScanPlanner matches on, so keeping only the alias hides the
     * dependency and silently gates worker enrichment off.
     *
     * @return list<string>
     */
    private static function tomlInlinePackageNames(string $block): array
    {
        $names = [];
        $pattern = '/^[ \t]*["\']?[A-Za-z0-9_.-]+["\']?[ \t]*=[ \t]*\{[^}]*\bpackage[ \t]*=[ \t]*["\']([^"\']+)["\']/m';
        if (preg_match_all($pattern, $block, $m) > 0) {
            foreach ($m[1] as $name) {
                $names[] = strtolower($name);
            }
        }

        return $names;
    }

    /**
     * Every table header in a TOML document, without its brackets.
     *
     * @return list<string>
     */
    private static function tomlHeaders(string $contents): array
    {
        if (preg_match_all('/^[ \t]*\[([^\]]+)\][ \t]*(?:#.*)?\r?$/m', $contents, $m) < 1) {
            return [];
        }

        return array_values(array_map(trim(...), $m[1]));
    }

    /**
     * The lowercased `key = ...` names at the top level of a table block.
     *
     * @return list<string>
     */
    private static function tomlTableKeys(string $block): array
    {
        $keys = [];
        if (preg_match_all('/^[ \t]*["\']?([A-Za-z0-9_.-]+)["\']?[ \t]*=/m', $block, $m) > 0) {
            foreach ($m[1] as $key) {
                $keys[] = strtolower($key);
            }
        }

        return $keys;
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
            $block = self::tableBlock($contents, $header);
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
        foreach (self::arrayTables($contents, '[[bin]]') as $bin) {
            if ($bin['path'] !== '') {
                $candidates[] = $bin['path'];
            } elseif ($bin['name'] !== '') {
                $candidates[] = 'src/bin/' . $bin['name'] . '.rs';
            }
        }
        // Auto-discovery finds src/main.rs and everything under src/bin/, and
        // it runs alongside any explicit [[bin]] rather than instead of it.
        // A virtual workspace has no [package] and so no binary of its own.
        if (self::cargoAutobins($contents) && self::tableBlock($contents, '[package]') !== null) {
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
        $block = self::tableBlock($contents, '[package]');
        if ($block === null) {
            return false;
        }
        if (preg_match('/^[ \t]*autobins[ \t]*=[ \t]*(true|false)\b/m', $block, $explicit) === 1) {
            return $explicit[1] === 'true';
        }
        $edition = preg_match('/^[ \t]*edition[ \t]*=[ \t]*["\']([^"\']+)["\']/m', $block, $m) === 1 ? $m[1] : '2015';

        return $edition !== '2015' || self::arrayTables($contents, '[[bin]]') === [];
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

    /**
     * The raw text after a `[header]` line until the next table header.
     *
     * @return non-empty-string|null
     */
    private static function tableBlock(string $contents, string $header): ?string
    {
        $pattern = '/^[ \t]*' . preg_quote($header, '/') . '[ \t]*(?:#.*)?\r?$/m';
        if (preg_match($pattern, $contents, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $end = preg_match('/^[ \t]*\[/m', $contents, $n, PREG_OFFSET_CAPTURE, $start) === 1
            ? $n[0][1]
            : strlen($contents);

        return substr($contents, $start, $end - $start) . "\n";
    }

    /**
     * The quoted strings inside `key = [...]` lists in a table block.
     *
     * Brackets are counted outside strings only, so a dependency like
     * `fastapi[all]` does not end the list early. A null `$keys` accepts
     * every list key in the block (needed for optional-dependency groups
     * and script tables, whose group names are arbitrary).
     *
     * @param list<string>|null $keys
     * @return list<string>
     */
    private static function tomlStringLists(string $block, ?array $keys): array
    {
        $result = [];
        $offset = 0;
        $length = strlen($block);
        while ($offset < $length) {
            $pattern = '/^[ \t]*([A-Za-z0-9_.-]+)[ \t]*=[ \t]*\[/m';
            if (preg_match($pattern, $block, $m, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                break;
            }
            $key = $m[1][0];
            if ($keys !== null && !in_array($key, $keys, true)) {
                $offset = $m[0][1] + strlen($m[0][0]);
                continue;
            }
            $open = $m[0][1] + strlen($m[0][0]) - 1; // position of '['
            $depth = 0;
            $inString = false;
            $i = $open;
            for (; $i < $length; ++$i) {
                $c = $block[$i];
                if ($c === '"' || $c === "'") {
                    if ($i === 0 || $block[$i - 1] !== '\\') {
                        $inString = !$inString;
                    }
                } elseif (!$inString) {
                    if ($c === '[') {
                        ++$depth;
                    } elseif ($c === ']') {
                        --$depth;
                        if ($depth === 0) {
                            ++$i; // stop just past the closing bracket
                            break;
                        }
                    }
                }
            }
            $list = substr($block, $open + 1, max(0, $i - $open - 2));
            if (preg_match_all('/["\']([^"\']+)["\']/', $list, $pms) > 0) {
                array_push($result, ...$pms[1]);
            }
            $offset = $i;
        }

        return $result;
    }

    /** Extensions a scanner emits nodes for (or anything else cannot be matched later). */
    private const ENTRY_POINT_EXTENSIONS = [
        'php', 'js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'mts', 'cts', 'vue', 'svelte', 'astro', 'py', 'pyi', 'rs',
    ];

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
     * @return list<string>
     */
    private static function toolConfigEntryPoints(string $contents, string $configPath): array
    {
        $directory = self::manifestDirectory($configPath);
        $keys = implode('|', array_map(preg_quote(...), self::CONFIG_REFERENCE_KEYS));
        $matched = preg_match_all(
            sprintf('/\b(?:%s)\s*:\s*(\[[^\]]*\]|[\'"`][^\'"`]*[\'"`])/', $keys),
            $contents,
            $matches,
            PREG_SET_ORDER,
        );
        if ($matched === false) {
            return [];
        }
        $paths = [];
        foreach ($matches as $match) {
            if (preg_match_all('/[\'"`]([^\'"`]+)[\'"`]/', $match[1], $tokens) === false) {
                continue;
            }
            foreach ($tokens[1] as $token) {
                // A `command` is a shell line; any other value is one path,
                // which a split on whitespace leaves whole.
                foreach (preg_split('/\s+/', trim($token)) ?: [] as $word) {
                    $path = self::entryPointPath($word, $directory);
                    if ($path !== null) {
                        $paths[$path] = true;
                    }
                }
            }
        }

        return array_keys($paths);
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
     * The files, less JavaScript `tsc` compiled beside its TypeScript source.
     *
     * Without an `outDir` the compiler writes `errors.js` next to `errors.ts`,
     * and every import resolves to the `.ts`, so the `.js` read as a module
     * nothing uses. It is build output: a same-named `.ts` or `.tsx` sits
     * beside it and it ends with the source-map comment the compiler writes.
     * Both are facts discovery already holds (the path list and the bytes it
     * hashed), so the decision changes only when they do.
     *
     * @param list<DiscoveredFile> $files
     * @return list<DiscoveredFile>
     */
    private static function withoutCompiledSiblings(array $files): array
    {
        $paths = [];
        foreach ($files as $file) {
            $paths[$file->relativePath] = true;
        }

        return array_values(array_filter($files, static function (DiscoveredFile $file) use ($paths): bool {
            if (preg_match('/^(.*)\.(?:js|jsx|mjs|cjs)$/', $file->relativePath, $stem) !== 1) {
                return true;
            }
            $extension = substr($file->relativePath, strlen($stem[1]) + 1);
            $sources = match ($extension) {
                'mjs' => ['mts'],
                'cjs' => ['cts'],
                default => ['ts', 'tsx'],
            };
            $sibling = false;
            foreach ($sources as $source) {
                $sibling = $sibling || isset($paths[$stem[1] . '.' . $source]);
            }
            if (!$sibling) {
                return true;
            }
            $size = @filesize($file->absolutePath);
            $tail = $size === false ? false : @file_get_contents($file->absolutePath, false, null, max(0, $size - 512));

            return !is_string($tail) || preg_match('~//# sourceMappingURL=\S+\s*$~', $tail) !== 1;
        }));
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
     * Add, to each YAML unit, the PHP files below the migration directories it names.
     *
     * @param list<ProjectUnit> $units
     * @param list<DiscoveredFile> $files
     * @return list<ProjectUnit>
     */
    private static function withMigrationEntryPoints(array $units, array $files): array
    {
        return array_map(static function (ProjectUnit $unit) use ($files): ProjectUnit {
            $directories = $unit->metadata['migration_directories'] ?? [];
            if ($unit->kind !== 'yaml' || !is_array($directories) || $directories === []) {
                return $unit;
            }
            $paths = array_fill_keys($unit->metadata['entry_points'] ?? [], true);
            foreach ($files as $file) {
                foreach ($directories as $directory) {
                    if (is_string($directory) && str_starts_with($file->relativePath, $directory . '/') && str_ends_with($file->relativePath, '.php')) {
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
     * Whether a path is the project's own Knossos configuration, which the
     * walk reads whatever the ignores say about it.
     *
     * The exception exists because a project that ignores its own settings
     * file would be configuring a scan that never reads the configuration. It
     * is public for the same reason {@see self::languageFor()} is: the drift
     * oracles decide the same question about a path they were handed, and a
     * second copy of this list would let a probe count a path discovery
     * exempts, reporting drift no rescan can clear.
     */
    public static function isConfigurationFile(string $relativePath): bool
    {
        return in_array(strtolower(basename($relativePath)), ['knossos.json', 'knossos.jsonc'], true);
    }

    /**
     * The language a file belongs to, or null when it is not source.
     *
     * Extension first, then a shebang for extensionless files. Executable entry
     * points routinely have no extension — `artisan`, `bin/console`, this project's
     * own `workers/php/bin/worker` — and skipping them makes whatever they invoke
     * look unreferenced, so dead-code detection reports a live entry point as a
     * deletion candidate.
     *
     * Public because the drift oracles have to answer the same question this
     * loop answers, about a path they were handed rather than one they walked
     * to: whether a file appearing beside the graph is source the scanner would
     * have tracked, or a README the graph was never going to hold. Two
     * definitions of "source" would let a probe report drift a rescan cannot
     * clear.
     *
     * @param string|null $absolutePath needed only to read a shebang; omit and
     *        extensionless files are simply not classified
     */
    public static function languageFor(string $relativePath, ?string $absolutePath = null): ?string
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $byExtension = match ($extension) {
            'php' => 'php',
            'ts', 'tsx', 'mts', 'cts', 'vue', 'svelte', 'astro' => 'typescript',
            'js', 'jsx', 'mjs', 'cjs' => 'javascript',
            'py', 'pyi' => 'python',
            'rs' => 'rust',
            default => null,
        };
        if ($byExtension !== null || $extension !== '' || $absolutePath === null) {
            return $byExtension;
        }

        return self::languageFromShebang($absolutePath);
    }

    /**
     * The language named by a script's shebang, or null.
     *
     * Only the first line is read, and only for a file with no extension, so the
     * cost is one bounded read of the handful of extensionless files in a tree
     * (LICENSE, Dockerfile, Makefile) rather than of every file.
     */
    private static function languageFromShebang(string $absolutePath): ?string
    {
        $handle = @fopen($absolutePath, 'rb');
        if (!is_resource($handle)) {
            return null;
        }
        try {
            $first = (string) fgets($handle, self::SHEBANG_PROBE_BYTES);
        } finally {
            fclose($handle);
        }
        if (!str_starts_with($first, '#!')) {
            return null;
        }

        // Matches both `#!/usr/bin/php` and `#!/usr/bin/env php`, and tolerates a
        // version suffix such as `php8.3`. Anchored to a word boundary so a path
        // like /opt/phpstorm/bin/foo cannot be read as a PHP script.
        return match (true) {
            preg_match('#\b(php)[0-9.]*\b#i', $first) === 1 => 'php',
            preg_match('#\b(node|nodejs|bun|deno)[0-9.]*\b#i', $first) === 1 => 'javascript',
            preg_match('#\b(python)[0-9.]*\b#i', $first) === 1 => 'python',
            default => null,
        };
    }
    /**
     * Which manifest kind a filename is, or null when it is not one.
     *
     * Public because it is half of what "an input this scanner reads" means,
     * and the drift oracles need the same half: a path that is a unit here but
     * not a language file has no `files` row, and a probe that asked only
     * {@see self::languageFor()} treated editing composer.json as nothing at
     * all. {@see \Knossos\Query\Drift\ScannedPaths} asks both.
     */
    public static function unitKindFor(string $relativePath): ?string
    {
        $basename = strtolower(basename($relativePath));
        if ($basename === '.gitignore') {
            return 'gitignore';
        }
        // A shell script starts the programs it runs, which nothing imports.
        if (str_ends_with($basename, '.sh') || str_ends_with($basename, '.bash')) {
            return 'shell';
        }
        // A container's CMD and ENTRYPOINT start a script nothing imports.
        if ($basename === 'dockerfile' || str_starts_with($basename, 'dockerfile.') || str_ends_with($basename, '.dockerfile')) {
            return 'dockerfile';
        }
        if ($basename === 'composer.json') {
            return 'composer';
        }
        if ($basename === 'knossos.json' || $basename === 'knossos.jsonc') {
            return 'knossos';
        }
        if ($basename === 'package.json') {
            return 'node';
        }
        // An Azure Functions binding manifest. The basename is generic enough
        // that another tool could own it, so the reader below asks for the
        // `scriptFile` key rather than assuming the shape; a function.json that
        // is something else contributes no entry points and costs one unit.
        if ($basename === 'function.json') {
            return 'azure_function';
        }
        // An HTML shell is the only thing that reaches a single-page
        // application's entry module, and nothing in the project imports it.
        // Read as a unit rather than as a file: it contributes entry points,
        // not nodes, and no scanner parses HTML.
        if (str_ends_with($basename, '.html') || str_ends_with($basename, '.htm')) {
            return 'html';
        }
        // Compose files, CI workflows and deployment manifests all name source
        // files by path. Read for those paths only; no YAML parser is involved
        // and none is needed, for the same reason the Composer script reader
        // tokenises shell commands crudely.
        if (str_ends_with($basename, '.yml') || str_ends_with($basename, '.yaml')) {
            return 'yaml';
        }
        // NEON, PHPStan's config format, is YAML's shape: the rules and
        // extensions it registers are class names nothing in PHP references.
        if (str_ends_with($basename, '.neon') || str_ends_with($basename, '.neon.dist')) {
            return 'yaml';
        }
        // A Claude Code plugin runs its hooks and MCP servers from commands in
        // these files, which name the scripts by path; nothing imports them.
        $normalized = str_replace('\\', '/', $relativePath);
        if (in_array($basename, ['hooks.json', '.mcp.json', 'plugin.json'], true)
            || preg_match('#(?:^|/)\.claude/settings(?:\.[a-z]+)?\.json$#', strtolower($normalized)) === 1) {
            return 'agent_config';
        }
        if (in_array($basename, ['knip.json', 'knip.jsonc', '.knip.json', '.knip.jsonc'], true)) {
            return 'knip';
        }
        if ($basename === 'pyproject.toml') {
            return 'python';
        }
        // requirements.txt and its per-environment siblings (requirements-dev.txt,
        // requirements-prod.txt) are the legacy Python dependency manifest — the
        // pyproject.toml of projects that never migrated to PEP 621. Recorded as
        // their own unit so an edit invalidates the analyzer cache the way a
        // composer.json edit does.
        if ($basename === 'requirements.txt' || (str_starts_with($basename, 'requirements-') && str_ends_with($basename, '.txt'))) {
            return 'requirements';
        }
        if ($basename === 'tsconfig.json' || (str_starts_with($basename, 'tsconfig.') && str_ends_with($basename, '.json'))) {
            return 'typescript';
        }
        if ($basename === 'cargo.toml') {
            return 'cargo';
        }

        // A tool config is read TWICE: as an ordinary source module by the
        // language worker, and as a unit here for the files it tells its tool
        // to load. The two `if` blocks in discover() are independent, so one
        // file may be both — which is why this needs no scanner change.
        if (ToolConfigModuleRule::isToolConfigPath($relativePath)) {
            return 'tool_config';
        }

        return null;
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
