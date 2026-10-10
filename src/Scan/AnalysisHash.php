<?php

declare(strict_types=1);

namespace Knossos\Scan;

/**
 * A fingerprint of the files that decide what a worker emits.
 *
 * A cached contribution is only as good as the analysis that produced it, so
 * the cache key follows the worker's own files instead of a version somebody
 * has to remember to bump. A pattern is a path relative to the installation
 * root; a trailing `/**` means every regular file below that directory,
 * skipping any directory named `__tests__`, `tests`, `node_modules` or
 * `__pycache__`. The last holds bytecode an interpreter may write beside a
 * worker's own modules, which follows from them and decides nothing.
 */
final class AnalysisHash
{
    private const SKIPPED_DIRECTORIES = ['__tests__', 'tests', 'node_modules', '__pycache__'];

    private function __construct() {}

    /**
     * The hash of the named files as they are now.
     *
     * Read afresh on every call rather than remembered for the life of the
     * process: a long-running server whose worker is updated in place must
     * see the new files on its next scan. The scan runner asks once per
     * language per scan, and the files are few.
     *
     * @param list<string> $patterns
     * @throws \InvalidArgumentException when patterns are given without an installation root, since
     *         every path would then read as missing and the hash could never change
     */
    public static function of(string $installationRoot, array $patterns): string
    {
        if ($installationRoot === '' && $patterns !== []) {
            throw new \InvalidArgumentException('An analysis hash needs the installation root its patterns are relative to.');
        }

        return self::compute(rtrim($installationRoot, '/'), $patterns);
    }

    /**
     * Hash the sorted per-file lines of every pattern.
     *
     * @param list<string> $patterns
     */
    private static function compute(string $root, array $patterns): string
    {
        $lines = [];
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '/**')) {
                $directory = substr($pattern, 0, -3);
                $found = self::filesBelow($root, $directory);
                foreach ($found as $relative) {
                    $lines[$relative] = self::line($root, $relative);
                }
                if ($found === []) {
                    $lines[$directory . '/**'] = $directory . '/**' . "\0missing";
                }
                continue;
            }
            $lines[$pattern] = self::line($root, $pattern);
        }
        ksort($lines, SORT_STRING);

        return hash('sha256', implode("\n", $lines));
    }

    /** The line one file contributes: its path and the hash of its bytes, or that it is missing. */
    private static function line(string $root, string $relative): string
    {
        $hash = is_file($root . '/' . $relative) ? hash_file('sha256', $root . '/' . $relative) : false;

        return $relative . "\0" . ($hash === false ? 'missing' : $hash);
    }

    /**
     * Every regular file below a directory, as paths relative to the root.
     *
     * @return list<string>
     */
    private static function filesBelow(string $root, string $directory): array
    {
        $base = $root . '/' . $directory;
        if (!is_dir($base)) {
            return [];
        }
        $tree = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $entry): bool => !$entry->isDir() || !in_array($entry->getFilename(), self::SKIPPED_DIRECTORIES, true),
        ));
        $found = [];
        foreach ($tree as $entry) {
            if ($entry->isFile()) {
                $found[] = substr($entry->getPathname(), strlen($root) + 1);
            }
        }

        return $found;
    }
}
