<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use Knossos\Discovery\Manifest\ManifestReaders;

/**
 * Turns the bytes of one manifest into a project unit: decodes JSON where the
 * kind is JSON, hands the rest to the kind's reader, and files a diagnostic
 * instead of a unit when the bytes are missing or do not decode.
 */
final class UnitReader
{
    /**
     * Parse one project manifest from the bytes the caller already read,
     * recording a diagnostic rather than failing when there are none.
     *
     * Takes the content rather than the path on purpose: $contentHash is the
     * hash of exactly these bytes, and reading the file again here is what let
     * the two describe different content.
     *
     * @param ?string $contents the bytes this unit's hash was taken over, or null when the read failed
     * @param list<DiscoveryDiagnostic> $diagnostics
     */
    public static function read(
        string $kind,
        string $relative,
        string $absolute,
        string $contentHash,
        ?string $contents,
        array &$diagnostics,
    ): ?ProjectUnit {
        if ($contents === null) {
            $diagnostics[] = new DiscoveryDiagnostic(
                'warning',
                'DISCOVERY_CONFIG_UNREADABLE',
                'Configuration file could not be read.',
                $relative,
            );
            return null;
        }

        // TOML, plain text and text read for paths: none of them is JSON, and
        // feeding one to JsonConfig::decode() below would drop the unit as
        // DISCOVERY_CONFIG_INVALID.
        $text = ManifestReaders::text($kind);
        if ($text !== null) {
            return new ProjectUnit($kind, $relative, $contentHash, $text->read($relative, $absolute, $contents));
        }

        try {
            $decoded = JsonConfig::decode(
                $contents,
                in_array($kind, ['typescript', 'knossos'], true) || str_ends_with(strtolower($relative), '.jsonc'),
            );
        } catch (DiscoveryException $error) {
            $diagnostics[] = new DiscoveryDiagnostic(
                'warning',
                'DISCOVERY_CONFIG_INVALID',
                $error->getMessage(),
                $relative,
            );
            return null;
        }

        return new ProjectUnit($kind, $relative, $contentHash, ManifestReaders::json($kind)?->read($relative, $absolute, $decoded) ?? []);
    }
}
