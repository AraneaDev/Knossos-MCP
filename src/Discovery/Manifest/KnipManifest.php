<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads a knip config for the entry files it declares.
 */
final class KnipManifest implements JsonManifestReader
{
    /** The entry points a knip config declares. */
    public function read(string $relative, string $absolute, array $decoded): array
    {
        return ['entry_points' => self::knipEntryPoints($decoded, $relative)];
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
        $directory = ManifestPaths::manifestDirectory($configPath);
        $scopes = [[$directory, $config['entry'] ?? null]];
        foreach (is_array($config['workspaces'] ?? null) ? $config['workspaces'] : [] as $workspace => $settings) {
            if (is_string($workspace) && is_array($settings) && !str_contains($workspace, '*')) {
                $scopes[] = [ManifestPaths::joinPath($directory, trim($workspace, './')), $settings['entry'] ?? null];
            }
        }
        $paths = [];
        foreach ($scopes as [$anchor, $entries]) {
            foreach (is_array($entries) ? $entries : [$entries] as $entry) {
                $path = is_string($entry) ? ManifestPaths::entryPointPath($entry, $anchor) : null;
                if ($path !== null) {
                    $paths[$path] = true;
                }
            }
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);

        return $paths;
    }
}
