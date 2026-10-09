<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads `Cargo.toml`: the crate name, its dependencies and the binaries it
 * builds.
 */
final class CargoManifest implements ManifestReader
{
    /** The crate name, requirements and entry points of a `Cargo.toml`. */
    public function read(string $relative, string $absolute, string $contents): array
    {
        // TOML, exactly like pyproject.toml, and read the same way: targeted
        // regexes over the specific tables rather than a parser.
        return [
            'name' => self::cargoPackageName($contents),
            'requires' => self::cargoRequirements($contents),
            'entry_points' => self::cargoEntryPoints($contents, $relative, dirname($absolute)),
        ];
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
        $directory = ManifestPaths::manifestDirectory($configPath);
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
        return ManifestPaths::entryPoints($candidates, $directory);
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
}
