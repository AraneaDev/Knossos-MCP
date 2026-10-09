<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads a README for the commands that run a one-off script.
 */
final class ReadmeManifest implements ManifestReader
{
    /** The entry points the commands in a README run. */
    public function read(string $relative, string $absolute, string $contents): array
    {
        return [
            'entry_points' => self::readmeRunnerEntryPoints($contents, $relative),
        ];
    }

    /**
     * The files a README runs: a path given straight to an interpreter
     * (`node scripts/screenshot.mjs`, `npx tsx src/seed.ts`, `python3 x.py`).
     *
     * Prose mentions files for every reason, so a path only counts after a
     * runner. Read from the README's directory and from the project root, as
     * a YAML file's paths are.
     *
     * @return list<string>
     */
    private static function readmeRunnerEntryPoints(string $contents, string $configPath): array
    {
        $extensions = implode('|', array_map(preg_quote(...), ManifestPaths::ENTRY_POINT_EXTENSIONS));
        preg_match_all(
            sprintf('#\b(?:node|nodejs|deno(?:\s+run)?|bun(?:\s+run)?|tsx|ts-node|python3?|php)\s+(?:-{1,2}[a-z][\w-]*(?:=\S+)?\s+)*([A-Za-z0-9_./-]+\.(?:%s))\b#', $extensions),
            $contents,
            $matches,
        );

        return YamlPaths::entryPoints(implode("\n", $matches[1]), $configPath);
    }
}
