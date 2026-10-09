<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads `tsconfig.json` for the compiler options the TypeScript worker
 * resolves imports with.
 */
final class TsconfigManifest implements JsonManifestReader
{
    /** The compiler settings of a `tsconfig.json`. */
    public function read(string $relative, string $absolute, array $decoded): array
    {
        return self::typescriptMetadata($decoded);
    }

    /**
     * tsconfig details the TypeScript worker needs to build a program.
     *
     * @param array<string, mixed> $config @return array<string, mixed>
     */
    private static function typescriptMetadata(array $config): array
    {
        $compiler = is_array($config['compilerOptions'] ?? null) ? $config['compilerOptions'] : [];
        $references = [];
        if (is_array($config['references'] ?? null)) {
            foreach ($config['references'] as $reference) {
                if (is_array($reference) && is_string($reference['path'] ?? null)) {
                    $references[] = $reference['path'];
                }
            }
        }

        return [
            'extends' => is_string($config['extends'] ?? null) ? $config['extends'] : null,
            'allow_js' => ($compiler['allowJs'] ?? false) === true,
            'base_url' => is_string($compiler['baseUrl'] ?? null) ? $compiler['baseUrl'] : null,
            'out_dir' => is_string($compiler['outDir'] ?? null) ? $compiler['outDir'] : null,
            'root_dir' => is_string($compiler['rootDir'] ?? null) ? $compiler['rootDir'] : null,
            'paths' => is_array($compiler['paths'] ?? null) ? $compiler['paths'] : [],
            'references' => $references,
        ];
    }
}
