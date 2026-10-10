<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads a shell script for the programs it runs, which nothing imports.
 */
final class ShellManifest implements ManifestReader
{
    /** The entry points a shell script runs. */
    public function read(string $relative, string $absolute, string $contents): array
    {
        return [
            'entry_points' => self::shellPathEntryPoints($contents, $relative),
        ];
    }

    /**
     * The files a shell script names by path, as a YAML file's are read.
     *
     * `cd server && npx tsx src/scripts/reset.ts` names a path relative to the
     * directory the line changed into, and a `cd` on a line of its own moves
     * the lines after it until its `case` branch or block ends. Each line is
     * read from the project root, the script's own directory and whatever
     * `cd` reaches it; a path no file answers to under any of them names
     * nothing.
     *
     * @return list<string>
     */
    private static function shellPathEntryPoints(string $contents, string $configPath): array
    {
        $directory = ManifestPaths::manifestDirectory($configPath);
        $paths = [];
        $current = [];
        foreach (explode("\n", $contents) as $line) {
            $line = preg_replace('/(?:^|\s)#.*$/', '', $line) ?? $line;
            $inline = self::shellCdTargets($line, $directory);
            $standalone = preg_match('/^\s*cd\s/', $line) === 1 && preg_match('/&&|;|\|/', $line) !== 1;
            foreach (YamlPaths::entryPoints($line, $configPath, [...$current, ...$inline]) as $path) {
                $paths[$path] = true;
            }
            if ($standalone) {
                $current = $inline;
            } elseif (preg_match('/;;|^\s*(?:(?:esac|fi|done)\b|\})/', $line) === 1) {
                $current = [];
            }
        }

        return array_keys($paths);
    }

    /**
     * The project-relative directories the `cd` commands on one line change into.
     *
     * @return list<string>
     */
    private static function shellCdTargets(string $line, string $directory): array
    {
        preg_match_all('#\bcd\s+[\'"]?([A-Za-z0-9_][A-Za-z0-9_./-]*)#', $line, $matches);
        $anchors = [];
        foreach ($matches[1] as $target) {
            $target = rtrim(str_starts_with($target, './') ? substr($target, 2) : $target, '/');
            if ($target === '' || in_array('..', explode('/', $target), true)) {
                continue;
            }
            $anchors[$target] = true;
            if ($directory !== '') {
                $anchors[$directory . '/' . $target] = true;
            }
        }

        return array_keys($anchors);
    }
}
