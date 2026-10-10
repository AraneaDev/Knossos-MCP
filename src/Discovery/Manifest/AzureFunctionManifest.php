<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads an Azure Functions `function.json` for the script it binds.
 */
final class AzureFunctionManifest implements JsonManifestReader
{
    /** The entry points an Azure Functions binding manifest names. */
    public function read(string $relative, string $absolute, array $decoded): array
    {
        return [
            'entry_points' => self::azureFunctionEntryPoints($decoded, $relative),
        ];
    }

    /**
     * The handler an Azure Functions binding manifest points at, so it reaches
     * {@see ManifestEntryPointRule} like any other manifest's entry points.
     *
     * The host runs exactly the file `scriptFile` names, which is what makes
     * this worth reading: nothing in the project imports the handler, so its
     * in-degree is zero however live it is. A directory holding both `index.js`
     * and a stale `index.ts` made that visible — TypeScript's own module
     * resolution answers a sibling's `require('../management')` with the `.ts`,
     * leaving the `.js` the host actually executes looking like dead code.
     * Reading the manifest settles which of the two is the entry point on the
     * runtime's authority rather than the type checker's.
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private static function azureFunctionEntryPoints(array $manifest, string $configPath): array
    {
        $directory = ManifestPaths::manifestDirectory($configPath);
        $scriptFile = $manifest['scriptFile'] ?? null;
        if (is_string($scriptFile)) {
            $path = ManifestPaths::entryPointPath($scriptFile, $directory);

            return $path === null ? [] : [$path];
        }
        // `scriptFile` is optional, and most manifests leave it out: the host
        // then loads the conventional handler for the runtime from the
        // manifest's own directory. Two thirds of the 69 manifests in the
        // project that prompted this omit the key, so reading only the explicit
        // form would have covered a third of the handlers and left the rest
        // reported as reachable from nothing but their own tests.
        //
        // Gated on a non-empty `bindings` array, which is what makes a
        // `function.json` an Azure one rather than some other tool's file with
        // a generic name. Both conventional names are offered because matching
        // is by exact project-relative path: whichever the directory does not
        // hold matches nothing and costs nothing.
        if (!is_array($manifest['bindings'] ?? null) || $manifest['bindings'] === []) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(string $candidate): ?string => ManifestPaths::entryPointPath($candidate, $directory),
            ['index.js', '__init__.py'],
        ), static fn(?string $path): bool => $path !== null));
    }
}
