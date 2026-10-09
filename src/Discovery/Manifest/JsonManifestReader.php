<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads the metadata of one kind of JSON manifest, from the object discovery
 * already decoded (with comments allowed where the kind allows them).
 */
interface JsonManifestReader
{
    /**
     * The unit's metadata.
     *
     * @param string $relative the manifest's project-relative path
     * @param string $absolute the manifest's absolute path, for readers that look beside it
     * @param array<array-key, mixed> $decoded the decoded JSON object
     * @return array<string, mixed>
     */
    public function read(string $relative, string $absolute, array $decoded): array;
}
