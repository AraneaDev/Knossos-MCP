<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads a Dockerfile for the scripts its CMD, ENTRYPOINT and RUN lines
 * start.
 */
final class DockerfileManifest implements ManifestReader
{
    /** The entry points a Dockerfile names, with copied files read under their source paths. */
    public function read(string $relative, string $absolute, string $contents): array
    {
        // Read as text for paths, as a YAML file is: a build context is the
        // project root, so a path resolves there as well as beside the file.
        return [
            'entry_points' => YamlPaths::entryPoints(self::withCopySources($contents), $relative),
        ];
    }

    /**
     * A Dockerfile with each path a `COPY` put in the image written as the
     * source it was copied from.
     *
     * `COPY scripts/probe /tmp/probe` then `RUN python3 /tmp/probe/check.py`
     * runs `scripts/probe/check.py`, but only the image path is written. Each
     * `COPY` line's destination is rewritten to its source on every other
     * line, the longest destination first; a `COPY` with several sources, or
     * from another stage, names no single source and is left alone.
     */
    private static function withCopySources(string $contents): string
    {
        $mappings = [];
        $lines = explode("\n", $contents);
        foreach ($lines as $line) {
            if (preg_match('#^\s*(?:COPY|ADD)\s+((?:--[a-z-]+=\S+\s+)*)(\S+)\s+(/\S+)\s*$#i', $line, $copy) !== 1
                || str_contains($copy[1], '--from=')
                || str_starts_with($copy[2], '/')
                || str_contains($copy[2], '..')) {
                continue;
            }
            $source = trim(str_starts_with($copy[2], './') ? substr($copy[2], 2) : $copy[2], '/');
            $destination = rtrim($copy[3], '/');
            // A file copied into a directory keeps its name there.
            if (str_ends_with($copy[3], '/') && str_contains(basename($source), '.')) {
                $destination .= '/' . basename($source);
            }
            if ($source !== '' && $source !== '.' && $destination !== '') {
                $mappings[$destination] = $source;
            }
        }
        if ($mappings === []) {
            return $contents;
        }
        uksort($mappings, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:COPY|ADD)\s/i', $line) === 1) {
                continue;
            }
            foreach ($mappings as $destination => $source) {
                $line = preg_replace('#(?<![\w./-])' . preg_quote($destination, '#') . '(?=/|\s|$|["\';|&])#', $source, $line) ?? $line;
            }
            $lines[$index] = $line;
        }

        return implode("\n", $lines);
    }
}
