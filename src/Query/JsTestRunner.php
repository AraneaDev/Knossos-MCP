<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * Which runner runs a JavaScript or TypeScript test file, so the command an
 * agent is handed is one the project can run.
 *
 * Read from the nearest package.json above the test, up to the project root:
 * one whose dependencies name Vitest or Jest alone answers with it; one that
 * names both, or neither, answers by its `test` script when that names one
 * alone. A package.json that names no runner and has no `test` script (a
 * workspace member leaning on the root's) leaves it to the next one up; one
 * that is ambiguous, or tests with another runner, answers null, and so does
 * a tree with no package.json at all. Null means unknown: the test is
 * listed, never put in a command that would run the wrong runner.
 */
final readonly class JsTestRunner
{
    /** A test file Vitest or Jest would pick up by its name. */
    private const TEST_FILE = '/\.(spec|test)\.[cm]?[jt]sx?$/';

    /** The runners told apart. */
    private const RUNNERS = ['vitest', 'jest'];

    /**
     * `$tests` with `js_runner` set on each JavaScript test: `vitest`,
     * `jest`, or null when unknown. Other tests are returned as they are.
     *
     * @param string $root the project root the paths are relative to
     * @param list<array{path: string, distance: int}> $tests
     * @return list<array<string, mixed>>
     */
    public static function annotate(string $root, array $tests): array
    {
        $answers = [];

        return array_map(static function (array $test) use ($root, &$answers): array {
            if (preg_match(self::TEST_FILE, $test['path']) !== 1) {
                return $test;
            }
            $directory = dirname($test['path']);
            if (!array_key_exists($directory, $answers)) {
                $answers[$directory] = self::of($root, $directory);
            }

            return $test + ['js_runner' => $answers[$directory]];
        }, $tests);
    }

    /**
     * The runner the nearest deciding package.json at or above `$directory`
     * names, or null; `$directory` is relative to `$root`.
     */
    private static function of(string $root, string $directory): ?string
    {
        $base = rtrim($root, '/');
        $relative = trim($directory === '.' ? '' : $directory, '/');
        while (true) {
            $verdict = self::verdict(($relative === '' ? $base : $base . '/' . $relative) . '/package.json');
            if ($verdict !== false) {
                return $verdict;
            }
            if ($relative === '') {
                return null;
            }
            $parent = dirname($relative);
            $relative = $parent === '.' ? '' : $parent;
        }
    }

    /**
     * What one package.json says: a runner, null for ambiguous or another
     * runner (or a file that cannot be read as JSON), false when it says
     * nothing and the next one up decides.
     */
    private static function verdict(string $file): string|false|null
    {
        if (!is_file($file)) {
            return false;
        }
        $manifest = json_decode((string) @file_get_contents($file), true);
        if (!is_array($manifest)) {
            return null;
        }
        $dependencies = [];
        foreach (['devDependencies', 'dependencies'] as $key) {
            if (is_array($manifest[$key] ?? null)) {
                $dependencies += $manifest[$key];
            }
        }
        $named = array_values(array_filter(self::RUNNERS, static fn(string $runner): bool => array_key_exists($runner, $dependencies)));
        if (count($named) === 1) {
            return $named[0];
        }
        $script = is_array($manifest['scripts'] ?? null) && is_string($manifest['scripts']['test'] ?? null) ? $manifest['scripts']['test'] : null;
        if ($script === null) {
            return $named === [] ? false : null;
        }
        $scripted = array_values(array_filter(self::RUNNERS, static fn(string $runner): bool => preg_match('/\b' . $runner . '\b/', $script) === 1));

        return count($scripted) === 1 ? $scripted[0] : null;
    }
}
