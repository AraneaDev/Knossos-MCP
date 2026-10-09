<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads `pyproject.toml`: the project name, its requirements, the scripts
 * it installs and the directories it publishes as a library.
 */
final class PyprojectManifest implements ManifestReader
{
    /** The name, requirements, entry points and library roots of a `pyproject.toml`. */
    public function read(string $relative, string $absolute, string $contents): array
    {
        // TOML, not JSON: feeding it to JsonConfig::decode() would fail to
        // parse and drop the unit as DISCOVERY_CONFIG_INVALID. It gets no real
        // parser; targeted regexes over the specific tables keep it
        // self-contained.
        return [
            'name' => Toml::tableString($contents, '[project]') ?? Toml::tableString($contents, '[tool.poetry]'),
            'requires' => self::pythonRequirements($contents),
            'entry_points' => self::pythonEntryPoints($contents, $relative),
            'library_roots' => self::pythonLibraryRoots($contents, $relative),
        ];
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
        $directory = ManifestPaths::manifestDirectory($configPath);
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
                    $path = ManifestPaths::entryPointPath(str_replace('.', '/', $module) . '.py', $directory);
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
                $path = ManifestPaths::entryPointPath($file, $directory);
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

        return $buildable && !$installsCommand && !$notAPackage ? [ManifestPaths::manifestDirectory($relative)] : [];
    }
}
