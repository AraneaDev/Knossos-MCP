<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Path arithmetic every manifest reader and the cross-unit entry-point
 * passes share: a manifest's directory, a candidate entry point resolved
 * against it, a layout path, and the entry points a JSON manifest's script
 * fields name.
 */
final class ManifestPaths
{
    /** Extensions a scanner emits nodes for (or anything else cannot be matched later). */
    public const ENTRY_POINT_EXTENSIONS = [
        'php', 'js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'mts', 'cts', 'vue', 'svelte', 'astro', 'py', 'pyi', 'rs',
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
    public static function manifestEntryPoints(array $manifest, string $configPath, array $fields): array
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

        return self::entryPoints($candidates, $directory);
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
    public static function manifestDirectory(string $configPath): string
    {
        $directory = dirname($configPath);

        return in_array($directory, ['.', '', DIRECTORY_SEPARATOR, '/'], true) ? '' : $directory;
    }

    /**
     * Normalise one manifest token to a root-relative source path, or null when
     * it is not one: a flag, a bare command, a glob, a dependency's binary, or
     * a path that climbs out of the project.
     */
    public static function entryPointPath(string $candidate, string $directory): ?string
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

    /** A tsconfig directory option as a clean relative path, or null when it is absent or leaves its directory. */
    public static function layoutPath(mixed $value): ?string
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
    public static function joinPath(string $directory, string $path): string
    {
        return trim($directory === '' ? $path : ($path === '' ? $directory : $directory . '/' . $path), '/');
    }

    /**
     * The candidates that name an entry point beside a manifest, resolved
     * against its directory, each once and sorted.
     *
     * @param list<string> $candidates
     * @return list<string>
     */
    public static function entryPoints(array $candidates, string $directory): array
    {
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
}
