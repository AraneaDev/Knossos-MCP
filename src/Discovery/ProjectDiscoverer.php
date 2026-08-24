<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use DirectoryIterator;
use Knossos\Scan\CancellationToken;
use Throwable;

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

    public function __construct(private DiscoveryConfig $config)
    {
        $this->rootGuard = new RootGuard($config->allowedRoots);
        $this->ignoreMatcher = new IgnoreMatcher($config->ignorePatterns);
    }
    /** Walk the tree and select the files worth analysing, within the configured caps. */

    public function discover(string $requestedRoot, ?CancellationToken $cancellation = null): DiscoveryResult
    {
        $root = $this->rootGuard->resolve($requestedRoot);
        $files = [];
        $units = [];
        $diagnostics = [];
        $stack = [$root];
        $inputCount = 0;
        $seen = 0;

        while ($stack !== []) {
            $directory = array_pop($stack);
            try {
                $entries = new DirectoryIterator($directory);
            } catch (Throwable $error) {
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
                $configurationFile = in_array(strtolower(basename($relative)), ['knossos.json', 'knossos.jsonc'], true);
                if (!$configurationFile && $this->ignoreMatcher->matches($relative)) {
                    continue;
                }

                // Every stat-dependent probe below (isLink/isDir/isFile/getSize/getMTime)
                // throws RuntimeException when the entry vanishes mid-walk (a concurrent
                // build deleting a temp file). Treat that as an unreadable file — emit a
                // diagnostic and keep going rather than failing the whole scan.
                try {
                    if ($entry->isLink()) {
                        $target = realpath($absolute);
                        $escapes = $target === false || !RootGuard::contains($root, str_replace('\\', '/', $target));
                        $diagnostics[] = new DiscoveryDiagnostic(
                            'warning',
                            $escapes ? 'DISCOVERY_SYMLINK_ESCAPE' : 'DISCOVERY_SYMLINK_SKIPPED',
                            $escapes
                                ? 'Symlink target escapes the project root and was rejected.'
                                : 'Symlink was skipped because discovery does not follow symlinks.',
                            $relative,
                        );
                        continue;
                    }

                    if ($entry->isDir()) {
                        $stack[] = $absolute;
                        continue;
                    }
                    if (!$entry->isFile()) {
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
                } catch (Throwable $error) {
                    $diagnostics[] = new DiscoveryDiagnostic(
                        'warning',
                        'DISCOVERY_FILE_UNREADABLE',
                        sprintf('File could not be inspected: %s', $error->getMessage()),
                        $relative,
                    );
                    continue;
                }

                $fingerprint = FileFingerprint::compute($absolute);
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
                    );
                }

                if ($unitKind !== null) {
                    $unit = $this->readUnit($unitKind, $relative, $absolute, $contentHash, $diagnostics);
                    if ($unit !== null) {
                        $units[] = $unit;
                    }
                }
            }
        }

        usort($files, static fn(DiscoveredFile $left, DiscoveredFile $right): int =>
            $left->relativePath <=> $right->relativePath);
        usort($units, static fn(ProjectUnit $left, ProjectUnit $right): int =>
            [$left->kind, $left->configPath] <=> [$right->kind, $right->configPath]);

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
        );
    }

    /**
     * Read one project manifest, recording a diagnostic rather than failing when it is unreadable.
     *
     * @param list<DiscoveryDiagnostic> $diagnostics
     */
    private function readUnit(
        string $kind,
        string $relative,
        string $absolute,
        string $contentHash,
        array &$diagnostics,
    ): ?ProjectUnit {
        $contents = file_get_contents($absolute);
        if ($contents === false) {
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
                'entry_points' => self::pythonEntryPoints($contents),
            ]);
        }
        if ($kind === 'cargo') {
            return new ProjectUnit($kind, $relative, $contentHash, [
                'name' => self::cargoPackageName($contents),
                'requires' => self::cargoRequirements($contents),
                'entry_points' => self::cargoEntryPoints($contents),
            ]);
        }
        if ($kind === 'requirements') {
            return new ProjectUnit($kind, $relative, $contentHash, [
                'requires' => self::pipRequirements($contents),
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
                'requires' => self::composerRequirements($decoded),
                'entry_points' => self::manifestEntryPoints($decoded, $relative, ['bin']),
            ],
            'node' => [
                'name' => is_string($decoded['name'] ?? null) ? $decoded['name'] : null,
                'type' => is_string($decoded['type'] ?? null) ? $decoded['type'] : null,
                'workspaces' => self::workspaces($decoded['workspaces'] ?? []),
                'entry_points' => self::manifestEntryPoints($decoded, $relative, ['bin', 'main', 'module']),
            ],
            'typescript' => self::typescriptMetadata($decoded),
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
     * settings, so the body must not be read as a list of dependencies.
     *
     * @return list<string>
     */
    private static function cargoDependencySubTableNames(string $contents): array
    {
        $names = [];
        foreach (self::tomlHeaders($contents) as $header) {
            if (preg_match('/(?:^|[-.])(?:dependencies|dev-dependencies|build-dependencies)\.["\']?([A-Za-z0-9_-]+)["\']?$/', $header, $m) === 1) {
                $names[] = strtolower($m[1]);
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
    private static function pythonEntryPoints(string $contents): array
    {
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
                    $scripts[] = str_replace('.', '/', $module) . '.py';
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
    private static function cargoEntryPoints(string $contents): array
    {
        $bins = self::arrayTables($contents, '[[bin]]');
        $paths = [];
        foreach ($bins as $bin) {
            if ($bin['path'] !== '') {
                $paths[$bin['path']] = true;
            } elseif ($bin['name'] !== '') {
                $paths['src/bin/' . $bin['name'] . '.rs'] = true;
            }
        }
        if ($bins === [] && self::tableBlock($contents, '[package]') !== null) {
            $paths['src/main.rs'] = true;
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);

        return $paths;
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
        'php', 'js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'mts', 'cts', 'py', 'pyi', 'rs',
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
        // `dirname()` answers '.' for a manifest at the root. Only that exact
        // answer means "no directory" — trimming dots off the string instead
        // turned `.github/actions/setup` into `github/actions/setup`, a path
        // that matches no emitted node, so the entry point was lost silently.
        $directory = dirname($configPath);
        $directory = in_array($directory, ['.', '', DIRECTORY_SEPARATOR, '/'], true) ? '' : $directory;
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
            'paths' => is_array($compiler['paths'] ?? null) ? $compiler['paths'] : [],
            'references' => $references,
        ];
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
     * @param string|null $absolutePath needed only to read a shebang; omit and
     *        extensionless files are simply not classified
     */
    private static function languageFor(string $relativePath, ?string $absolutePath = null): ?string
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $byExtension = match ($extension) {
            'php' => 'php',
            'ts', 'tsx', 'mts', 'cts' => 'typescript',
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
    /** Which manifest kind a filename is, or null when it is not one. */

    private static function unitKindFor(string $relativePath): ?string
    {
        $basename = strtolower(basename($relativePath));
        if ($basename === 'composer.json') {
            return 'composer';
        }
        if ($basename === 'knossos.json' || $basename === 'knossos.jsonc') {
            return 'knossos';
        }
        if ($basename === 'package.json') {
            return 'node';
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

        return null;
    }
    /** A path expressed relative to the project root, which is the only form facts carry. */

    private function relative(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);

        return ltrim(substr($path, strlen($root)), '/');
    }
}
