<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads `composer.json`: the package name, its PSR-4 map, library roots,
 * requirements and the binaries it installs.
 */
final class ComposerManifest implements JsonManifestReader
{
    /** The name, PSR-4 map, library roots, requirements and entry points of a `composer.json`. */
    public function read(string $relative, string $absolute, array $decoded): array
    {
        return [
            'name' => is_string($decoded['name'] ?? null) ? $decoded['name'] : null,
            'psr4' => self::composerPsr4($decoded),
            'library_roots' => self::composerLibraryRoots($decoded, $relative),
            'requires' => self::composerRequirements($decoded),
            'entry_points' => ManifestPaths::manifestEntryPoints($decoded, $relative, ['bin']),
        ];
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
                $clean = ManifestPaths::layoutPath($path);
                if ($clean !== null) {
                    $roots[ManifestPaths::joinPath(ManifestPaths::manifestDirectory($relative), $clean)] = true;
                }
            }
        }
        $roots = array_map(strval(...), array_keys($roots));
        sort($roots, SORT_STRING);

        return $roots;
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
}
