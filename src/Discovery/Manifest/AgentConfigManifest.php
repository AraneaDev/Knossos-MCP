<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\JsonConfig;

/**
 * Reads a Claude Code plugin's hooks, MCP server and settings files for the
 * scripts their commands run.
 */
final class AgentConfigManifest implements ManifestReader
{
    /** The entry points the commands in an agent config run. */
    public function read(string $relative, string $absolute, string $contents): array
    {
        // Read as text for paths, as a YAML file is: the commands are shell
        // lines, and `$CLAUDE_PLUGIN_ROOT/src/x.ts` leaves a token the path
        // reader anchors at the project root.
        return [
            'entry_points' => YamlPaths::entryPoints(
                // `$CLAUDE_PLUGIN_ROOT/src/x.ts` names `src/x.ts` in the
                // plugin; left in, the variable's name reads as a directory.
                preg_replace('#\$\{?[A-Za-z_][A-Za-z0-9_]*\}?/#', '/', self::jsonStrings($contents)) ?? '',
                $relative,
            ),
        ];
    }

    /**
     * Every string value in a JSON document, one per line, or the raw text when it does not parse.
     *
     * JSON may escape a slash as `\/`, which hides a path from a reader of
     * the raw text; the decoded strings carry it plainly.
     */
    private static function jsonStrings(string $contents): string
    {
        try {
            $decoded = JsonConfig::decode($contents, true);
        } catch (DiscoveryException) {
            return $contents;
        }
        $strings = [];
        array_walk_recursive($decoded, static function (mixed $value) use (&$strings): void {
            if (is_string($value)) {
                $strings[] = $value;
            }
        });

        return implode("\n", $strings);
    }
}
