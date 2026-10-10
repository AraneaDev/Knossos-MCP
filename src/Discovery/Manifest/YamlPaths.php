<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads a text file for tokens shaped like paths to source files, the way a
 * YAML file is read; Dockerfiles, READMEs, shell scripts and agent configs
 * are read the same way.
 */
final class YamlPaths
{
    /**
     * Config keys under which a YAML value names files to EXCLUDE rather than
     * load: `ignore:` in codecov.yml, `exclude:` in a pre-commit config,
     * `paths-ignore:` in a GitHub Actions workflow trigger, PHPStan's
     * `excludePaths:` with its `analyse:` and `analyseAndScan:` lists. A path appearing
     * only under one of these is not read as an entry point — the same
     * reasoning {@see ToolConfigManifest::CONFIG_REFERENCE_KEYS} applies to a tool's own
     * config module, restated here as a deny-list because YAML's exclusion
     * keys, unlike a tool config's load keys, are not enumerable in advance.
     */
    private const EXCLUSION_KEYS = ['exclude', 'ignore', 'paths-ignore', 'skip', 'exclude_paths', 'excludes', 'excludePaths', 'analyse', 'analyseAndScan'];

    /**
     * Every token in a YAML file shaped like a path to a source file.
     *
     * A Compose file mounts a config into a container, a CI workflow runs a
     * script by name, a deployment manifest names an entry module. None of
     * those is an import, so the file they name has an in-degree of zero while
     * being the reason the thing runs at all.
     *
     * No YAML parser is used, and the file is scanned as text. That is the same
     * bargain {@see ManifestPaths::manifestEntryPoints()} strikes with Composer's shell
     * commands: what makes it safe is not the precision of the tokenising but
     * the exactness of the matching. {@see ManifestEntryPointRule} compares
     * against paths a scanner actually emitted, so a token naming nothing is
     * inert, and the source-extension guard inside {@see ManifestPaths::entryPointPath()}
     * keeps image tags, version strings and action references out.
     *
     * Two narrowings keep the blanket tokenising from reading too much in:
     *
     * - A `#`-comment tail is stripped from every line before tokenising. A
     *   `#` inside a quoted scalar is not really a comment, but treating every
     *   `#` as one is the conservative direction — it can only cause a real
     *   path to be missed, never a wrongful suppression to be added.
     * - A token on a line scoped by {@see self::EXCLUSION_KEYS} — see
     *   {@see self::exclusionLines()} — is dropped. YAML carries exclusion
     *   lists at least as often as it carries genuine references, and a path
     *   named only there is not loaded by anything; suppressing it would hide
     *   the finding this analysis exists to produce. This is the same call
     *   {@see ToolConfigManifest::toolConfigEntryPoints()} makes by being key-scoped outright.
     *
     * The character class stops at a colon, which is what splits a bind mount's
     * host path from its container path: both halves are offered and only the
     * half naming a real file can match.
     *
     * Two anchors, because YAML does not have one path convention. A Compose
     * bind mount is relative to the compose file's own directory; a CI
     * workflow's `run:` step executes with the repository root as its working
     * directory. Both readings are offered for every token and the one naming
     * no emitted file falls away, which is the same bargain {@see HtmlManifest::webRootReadings()}
     * strikes with a bundler's asset directories. A caller that knows of other
     * working directories, as a shell script's `cd` names them, adds them.
     *
     * @param list<string> $extraAnchors project-relative directories to read a path from as well
     * @return list<string>
     */
    public static function entryPoints(string $contents, string $configPath, array $extraAnchors = []): array
    {
        $directory = ManifestPaths::manifestDirectory($configPath);
        $extensions = implode('|', array_map(preg_quote(...), ManifestPaths::ENTRY_POINT_EXTENSIONS));
        $stripped = preg_replace('/#.*$/m', '', $contents) ?? $contents;
        if (preg_match_all(sprintf('#[A-Za-z0-9_./-]+\.(?:%s)\b#', $extensions), $stripped, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $excludedLines = self::exclusionLines($stripped);
        $paths = [];
        $anchors = array_unique([$directory, '', ...$extraAnchors]);
        // One pass over the newlines, so the line lookup below is a search
        // rather than a rescan of the prefix for every matched token.
        $lineStarts = [0];
        for ($at = strpos($stripped, "\n"); $at !== false; $at = strpos($stripped, "\n", $at + 1)) {
            $lineStarts[] = $at + 1;
        }
        foreach ($matches[0] as [$token, $offset]) {
            if (isset($excludedLines[self::lineAt($lineStarts, $offset)])) {
                continue;
            }
            foreach ($anchors as $anchor) {
                $path = ManifestPaths::entryPointPath($token, $anchor);
                if ($path !== null) {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
    }

    /**
     * The 0-indexed line an offset falls on, by binary search over the line starts.
     *
     * @param list<int> $lineStarts Byte offset of each line's first character.
     */
    private static function lineAt(array $lineStarts, int $offset): int
    {
        $low = 0;
        $high = count($lineStarts) - 1;
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($lineStarts[$middle] <= $offset) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }
        return $low;
    }

    /**
     * Line numbers, 0-indexed to match `explode("\n", $contents)`, that fall
     * under one of {@see self::EXCLUSION_KEYS}.
     *
     * Two shapes are recognised, and recognised is all this does: a single
     * line carrying an inline value (`ignore: src/legacy/old.php`), and a
     * block sequence that immediately follows a bare `key:` line, one level
     * more indented (`ignore:` then `  - src/legacy/old.php`). An exclusion
     * list nested under an unrelated parent key, or one that resumes after a
     * blank or comment-only line breaks the sequence, is not tracked — a full
     * YAML indentation model would cover those too, at a cost this reader,
     * which does not otherwise parse YAML at all, is not paying.
     *
     * @return array<int, true>
     */
    private static function exclusionLines(string $contents): array
    {
        $keys = implode('|', array_map(preg_quote(...), self::EXCLUSION_KEYS));
        $excluded = [];
        $blockIndent = null;
        foreach (explode("\n", $contents) as $index => $line) {
            if ($blockIndent !== null) {
                if (preg_match('/^(\s*)-\s/', $line, $item) === 1 && strlen($item[1]) > $blockIndent) {
                    $excluded[$index] = true;
                    continue;
                }
                $blockIndent = null;
            }
            if (preg_match('/^(\s*)(?:' . $keys . ')\s*:(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $excluded[$index] = true;
            if (trim($m[2]) === '') {
                $blockIndent = strlen($m[1]);
            }
        }

        return $excluded;
    }
}
