<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads the metadata of one kind of manifest that is not JSON (TOML, YAML,
 * HTML, shell, plain text), from the bytes discovery already read and hashed.
 */
interface ManifestReader
{
    /**
     * The unit's metadata.
     *
     * @param string $relative the manifest's project-relative path
     * @param string $absolute the manifest's absolute path, for readers that look beside it
     * @param string $contents the bytes the unit's hash was taken over
     * @return array<string, mixed>
     */
    public function read(string $relative, string $absolute, string $contents): array;
}
