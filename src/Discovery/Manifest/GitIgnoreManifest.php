<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * A `.gitignore` as a unit: its patterns decide which files the walk takes,
 * so an edit to one changes what a rescan produces; it carries nothing else.
 */
final class GitIgnoreManifest implements ManifestReader
{
    /** No metadata: the unit exists for its hash. */
    public function read(string $relative, string $absolute, string $contents): array
    {
        return [];
    }

}
