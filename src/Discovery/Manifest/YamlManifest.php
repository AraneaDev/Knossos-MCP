<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads a YAML or NEON file for the source paths, class names and loaded
 * directories it names.
 */
final class YamlManifest implements ManifestReader
{
    /** The entry points, class names and loaded directories a YAML or NEON file names. */
    public function read(string $relative, string $absolute, string $contents): array
    {
        return [
            'entry_points' => YamlPaths::entryPoints($contents, $relative),
            'class_names' => self::yamlClassNames($contents),
            ...self::loadedDirectories($contents, $relative),
        ];
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
        $base = ManifestPaths::manifestDirectory($configPath);
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
}
