<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads a tool config (a bundler, test runner or linter config) for the
 * files it tells its tool to load.
 */
final class ToolConfigManifest implements ManifestReader
{
    /** The entry points and load globs a tool config names. */
    public function read(string $relative, string $absolute, string $contents): array
    {
        return self::toolConfigEntryPoints($contents, $relative);
    }

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

    /** Config keys whose files a tool leaves out rather than loads. */
    private const DROPPING_KEYS = ['exclude', 'excludes', 'ignore', 'ignores', 'ignored', 'external', 'externals'];

    /** The globs one brace pattern may name before it is dropped as hostile. */
    private const BRACE_EXPANSION_LIMIT = 256;

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
     * Unlike {@see YamlPaths::entryPoints()} this does NOT tokenise the
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
        $directory = ManifestPaths::manifestDirectory($configPath);
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
            $path = $resolved === null ? null : ManifestPaths::entryPointPath($resolved, '');
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
                $path = ManifestPaths::entryPointPath($word, $directory);
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
     * is keyed too, but names a directory, which {@see ManifestPaths::entryPointPath()}
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
            || !in_array(strtolower(pathinfo($glob, PATHINFO_EXTENSION)), ManifestPaths::ENTRY_POINT_EXTENSIONS, true)) {
            return null;
        }

        return $directory === '' ? $glob : $directory . '/' . $glob;
    }
}
