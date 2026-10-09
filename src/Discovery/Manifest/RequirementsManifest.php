<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads `requirements.txt` and its per-environment siblings for the
 * packages they install.
 */
final class RequirementsManifest implements ManifestReader
{
    /** The requirements a pip requirements file names. */
    public function read(string $relative, string $absolute, string $contents): array
    {
        return [
            'requires' => self::pipRequirements($contents),
        ];
    }

    /**
     * Requirement names from a pip requirements file.
     *
     * One name per non-comment, non-option line, before any version operator,
     * extras, environment marker, or `--hash` value. `-r other.txt` includes
     * are not followed (the file is analysed standalone); `-e` editable
     * installs resolve to a path and are skipped.
     *
     * @return array<string, string>
     */
    private static function pipRequirements(string $contents): array
    {
        $result = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim(preg_replace('/\s+#.*$/', '', $line) ?? $line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '-') || str_starts_with($line, '--')) {
                continue;
            }
            // Requirement specifiers: name, optional extras, version, marker.
            if (preg_match('/^([A-Za-z0-9_.-]+)/', $line, $m) !== 1) {
                continue;
            }
            $name = strtolower($m[1]);
            if ($name !== '') {
                $result[$name] = $line;
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }
}
