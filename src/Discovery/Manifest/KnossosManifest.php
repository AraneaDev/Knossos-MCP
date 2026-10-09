<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads the project's own `knossos.json` for its schema version.
 */
final class KnossosManifest implements JsonManifestReader
{
    /** The schema version of a `knossos.json`. */
    public function read(string $relative, string $absolute, array $decoded): array
    {
        return ['version' => $decoded['version'] ?? null];
    }

}
