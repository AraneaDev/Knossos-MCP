<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads an HTML shell for the scripts it loads: the only thing that reaches
 * a single-page application's entry module.
 */
final class HtmlManifest implements ManifestReader
{
    /** The entry points an HTML file loads with a script tag. */
    public function read(string $relative, string $absolute, string $contents): array
    {
        return [
            'entry_points' => self::htmlScriptEntryPoints($contents, $relative),
        ];
    }

    /**
     * The scripts an HTML shell loads, in every project-relative form the
     * reference could mean.
     *
     * A single-page application is entered through this tag and through nothing
     * else: no module in the project imports `main.tsx`, so its in-degree is
     * zero however live it is, and the same is true of a plain `<script>` that
     * publishes runtime configuration onto `window`.
     *
     * A root-absolute `src` is resolved against the WEB root rather than the
     * project root, and every common bundler serves a directory of untouched
     * assets there — `public/` for Vite, Create React App, Next and Astro,
     * `static/` for SvelteKit and Hugo. Which one applies cannot be known from
     * the HTML, so all three readings are offered. Matching downstream is by
     * exact path against something a scanner emitted, so the two that name no
     * file cost nothing; guessing wrong in the other direction would lose the
     * entry point silently.
     *
     * Deliberately only `<script src>`. A stylesheet or an image is not code
     * and could not be a dead-code candidate anyway, and scraping every `href`
     * would put ordinary prose links through the same suppression.
     *
     * @return list<string>
     */
    private static function htmlScriptEntryPoints(string $contents, string $configPath): array
    {
        $directory = ManifestPaths::manifestDirectory($configPath);
        if (preg_match_all('/<script\b[^>]*?\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $contents, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        $paths = [];
        foreach ($matches as $match) {
            $source = $match[3] ?? '';
            if (($match[1] ?? '') !== '') {
                $source = $match[1];
            } elseif (($match[2] ?? '') !== '') {
                $source = $match[2];
            }
            foreach (self::webRootReadings($source) as $candidate) {
                $path = ManifestPaths::entryPointPath($candidate, $directory);
                if ($path !== null) {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
    }

    /**
     * The ways one web-root-absolute reference could name a file on disk.
     *
     * A relative source is already project-relative once anchored and gets a
     * single reading. A leading slash means the web root, so the asset
     * directories bundlers serve there are prefixed as well.
     *
     * @return list<string>
     */
    private static function webRootReadings(string $source): array
    {
        $source = trim($source);
        if ($source === '' || !str_starts_with($source, '/')) {
            return [$source];
        }
        $bare = ltrim($source, '/');

        return [$bare, 'public/' . $bare, 'static/' . $bare];
    }
}
