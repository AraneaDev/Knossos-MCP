<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads `package.json`: the package name and module type, its workspaces,
 * the TypeScript range it pins, whether it uses Vue, and its entry points.
 */
final class NodeManifest implements JsonManifestReader
{
    /** The name, type, workspaces, TypeScript range, Vue use and entry points of a `package.json`. */
    public function read(string $relative, string $absolute, array $decoded): array
    {
        return [
            'name' => is_string($decoded['name'] ?? null) ? $decoded['name'] : null,
            'type' => is_string($decoded['type'] ?? null) ? $decoded['type'] : null,
            'workspaces' => self::workspaces($decoded['workspaces'] ?? []),
            'typescript_range' => self::typescriptRange($decoded),
            'vue' => self::dependsOn($decoded, 'vue'),
            'entry_points' => [
                ...ManifestPaths::manifestEntryPoints($decoded, $relative, ['bin', 'main', 'module']),
                ...self::createReactAppEntryPoints($decoded, $relative, dirname($absolute)),
            ],
            'public_entry_points' => self::publicEntryPoints($decoded, $relative),
        ];
    }

    /** The order react-scripts resolves `src/index` in; the first that exists is built. */
    private const CREATE_REACT_APP_EXTENSIONS = ['web.mjs', 'mjs', 'web.js', 'js', 'web.ts', 'ts', 'web.tsx', 'tsx', 'web.jsx', 'jsx'];

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
        $directory = ManifestPaths::manifestDirectory($configPath);
        foreach (self::CREATE_REACT_APP_EXTENSIONS as $extension) {
            if (is_file($absoluteDirectory . '/src/index.' . $extension)) {
                return [($directory === '' ? '' : $directory . '/') . 'src/index.' . $extension];
            }
        }

        return [];
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
        $directory = ManifestPaths::manifestDirectory($configPath);
        return ManifestPaths::entryPoints($candidates, $directory);
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
}
