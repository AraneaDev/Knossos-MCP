<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * The reader for each unit kind: a text reader for the kinds that are not
 * JSON, a JSON reader for the ones discovery decodes first.
 *
 * A kind with neither is still a unit, with no metadata, once its JSON
 * decodes.
 */
final class ManifestReaders
{
    /** @var array<string, class-string<ManifestReader>> */
    private const TEXT = [
        'python' => PyprojectManifest::class,
        'cargo' => CargoManifest::class,
        'gitignore' => GitIgnoreManifest::class,
        'requirements' => RequirementsManifest::class,
        'html' => HtmlManifest::class,
        'yaml' => YamlManifest::class,
        'dockerfile' => DockerfileManifest::class,
        'readme' => ReadmeManifest::class,
        'shell' => ShellManifest::class,
        'agent_config' => AgentConfigManifest::class,
        'tool_config' => ToolConfigManifest::class,
    ];

    /** @var array<string, class-string<JsonManifestReader>> */
    private const JSON = [
        'composer' => ComposerManifest::class,
        'node' => NodeManifest::class,
        'azure_function' => AzureFunctionManifest::class,
        'typescript' => TsconfigManifest::class,
        'knip' => KnipManifest::class,
        'knossos' => KnossosManifest::class,
    ];

    /** The reader for a kind that is not JSON, or null when the kind is JSON. */
    public static function text(string $kind): ?ManifestReader
    {
        $class = self::TEXT[$kind] ?? null;

        return $class === null ? null : new $class();
    }

    /** The reader for a JSON kind, or null when the kind carries no metadata. */
    public static function json(string $kind): ?JsonManifestReader
    {
        $class = self::JSON[$kind] ?? null;

        return $class === null ? null : new $class();
    }
}
