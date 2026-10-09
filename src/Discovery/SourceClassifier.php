<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use Knossos\Classification\ToolConfigModuleRule;

/**
 * Says what a path is from its name, and for an extensionless file from its
 * shebang: a source language, a unit kind, a configuration file, a manifest,
 * a name a stable id can hold, or `tsc` output beside its source.
 *
 * Static and stateless, so discovery, the drift probe and the watcher's
 * fingerprint answer every path the same way.
 */
final class SourceClassifier
{
    /** Bytes read when probing an extensionless file's shebang; one short line is enough. */
    private const SHEBANG_PROBE_BYTES = 256;

    /**
     * The unit kinds that make their directory a manifest root: each declares a
     * package or a build, which is what puts build output (`dist`, `coverage`)
     * beside it. Entry-point configs (YAML, HTML, agent and tool configs) name
     * files to run, not a build root, so they are not on the list.
     */
    public const MANIFEST_UNIT_KINDS = ['cargo', 'composer', 'node', 'python', 'requirements', 'typescript'];

    /** Whether a path names a package or build manifest, which makes its directory a manifest root. */
    public static function isManifest(string $relativePath): bool
    {
        return in_array(self::unitKindFor($relativePath), self::MANIFEST_UNIT_KINDS, true);
    }

    /**
     * The files, less JavaScript `tsc` compiled beside its TypeScript source.
     *
     * Without an `outDir` the compiler writes `errors.js` next to `errors.ts`,
     * and every import resolves to the `.ts`, so the `.js` read as a module
     * nothing uses. It is build output: a same-named `.ts` or `.tsx` sits
     * beside it and it ends with the source-map comment the compiler writes.
     * Both are facts discovery already holds (the path list and the bytes it
     * hashed), so the decision changes only when they do.
     *
     * @param list<DiscoveredFile> $files
     * @return list<DiscoveredFile>
     */
    public static function withoutCompiledSiblings(array $files): array
    {
        $paths = [];
        foreach ($files as $file) {
            $paths[$file->relativePath] = true;
        }

        return array_values(array_filter(
            $files,
            static fn(DiscoveredFile $file): bool => !self::isCompiledSibling(
                $file->relativePath,
                $file->absolutePath,
                static fn(string $sibling): bool => isset($paths[$sibling]),
            ),
        ));
    }

    /**
     * Whether a JavaScript file is `tsc` output beside its TypeScript source:
     * a same-named `.ts` or `.tsx` (`.mts` for `.mjs`, `.cts` for `.cjs`)
     * exists, and the file ends with the source-map comment the compiler
     * writes. Public because the drift probe must skip exactly what discovery
     * skips; each caller says how a sibling's existence is known.
     *
     * @param callable(string): bool $exists whether a project-relative path exists
     */
    public static function isCompiledSibling(string $relativePath, string $absolutePath, callable $exists): bool
    {
        if (preg_match('/^(.*)\.(js|jsx|mjs|cjs)$/', $relativePath, $stem) !== 1) {
            return false;
        }
        $sources = match ($stem[2]) {
            'mjs' => ['mts'],
            'cjs' => ['cts'],
            default => ['ts', 'tsx'],
        };
        $sibling = false;
        foreach ($sources as $source) {
            $sibling = $sibling || $exists($stem[1] . '.' . $source);
        }
        if (!$sibling) {
            return false;
        }
        $size = @filesize($absolutePath);
        $tail = $size === false ? false : @file_get_contents($absolutePath, false, null, max(0, $size - 512));

        return is_string($tail) && preg_match('~//# sourceMappingURL=\S+\s*$~', $tail) === 1;
    }

    /**
     * Whether a path is the project's own Knossos configuration, which the
     * walk reads whatever the ignores say about it.
     *
     * The exception exists because a project that ignores its own settings
     * file would be configuring a scan that never reads the configuration. It
     * is public for the same reason {@see self::languageFor()} is: the drift
     * oracles decide the same question about a path they were handed, and a
     * second copy of this list would let a probe count a path discovery
     * exempts, reporting drift no rescan can clear.
     */
    public static function isConfigurationFile(string $relativePath): bool
    {
        return in_array(strtolower(basename($relativePath)), ['knossos.json', 'knossos.jsonc'], true);
    }

    /**
     * The language a file belongs to, or null when it is not source.
     *
     * Extension first, then a shebang for extensionless files. Executable entry
     * points routinely have no extension — `artisan`, `bin/console`, this project's
     * own `workers/php/bin/worker` — and skipping them makes whatever they invoke
     * look unreferenced, so dead-code detection reports a live entry point as a
     * deletion candidate.
     *
     * Public because the drift oracles have to answer the same question this
     * loop answers, about a path they were handed rather than one they walked
     * to: whether a file appearing beside the graph is source the scanner would
     * have tracked, or a README the graph was never going to hold. Two
     * definitions of "source" would let a probe report drift a rescan cannot
     * clear.
     *
     * @param string|null $absolutePath needed only to read a shebang; omit and
     *        extensionless files are simply not classified
     */
    public static function languageFor(string $relativePath, ?string $absolutePath = null): ?string
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $byExtension = match ($extension) {
            'php' => 'php',
            'ts', 'tsx', 'mts', 'cts', 'vue', 'svelte', 'astro' => 'typescript',
            'js', 'jsx', 'mjs', 'cjs' => 'javascript',
            'py', 'pyi' => 'python',
            'rs' => 'rust',
            default => null,
        };
        if ($byExtension !== null || $extension !== '' || $absolutePath === null) {
            return $byExtension;
        }

        return self::languageFromShebang($absolutePath);
    }

    /**
     * The language named by a script's shebang, or null.
     *
     * Only the first line is read, and only for a file with no extension, so the
     * cost is one bounded read of the handful of extensionless files in a tree
     * (LICENSE, Dockerfile, Makefile) rather than of every file.
     */
    private static function languageFromShebang(string $absolutePath): ?string
    {
        $handle = @fopen($absolutePath, 'rb');
        if (!is_resource($handle)) {
            return null;
        }
        try {
            $first = (string) fgets($handle, self::SHEBANG_PROBE_BYTES);
        } finally {
            fclose($handle);
        }
        if (!str_starts_with($first, '#!')) {
            return null;
        }

        // Matches both `#!/usr/bin/php` and `#!/usr/bin/env php`, and tolerates a
        // version suffix such as `php8.3`. Anchored to a word boundary so a path
        // like /opt/phpstorm/bin/foo cannot be read as a PHP script.
        return match (true) {
            preg_match('#\b(php)[0-9.]*\b#i', $first) === 1 => 'php',
            preg_match('#\b(node|nodejs|bun|deno)[0-9.]*\b#i', $first) === 1 => 'javascript',
            preg_match('#\b(python)[0-9.]*\b#i', $first) === 1 => 'python',
            default => null,
        };
    }

    /**
     * Which manifest kind a filename is, or null when it is not one.
     *
     * Public because it is half of what "an input this scanner reads" means,
     * and the drift oracles need the same half: a path that is a unit here but
     * not a language file has no `files` row, and a probe that asked only
     * {@see self::languageFor()} treated editing composer.json as nothing at
     * all. {@see \Knossos\Query\Drift\ScannedPaths} asks both.
     */
    public static function unitKindFor(string $relativePath): ?string
    {
        $basename = strtolower(basename($relativePath));
        if ($basename === '.gitignore') {
            return 'gitignore';
        }
        // A README writes down the command that runs a one-off script.
        if (preg_match('/^readme(?:\.[a-z]+)?\.md$/', $basename) === 1) {
            return 'readme';
        }
        // A shell script starts the programs it runs, which nothing imports.
        if (str_ends_with($basename, '.sh') || str_ends_with($basename, '.bash')) {
            return 'shell';
        }
        // A container's CMD and ENTRYPOINT start a script nothing imports.
        if ($basename === 'dockerfile' || str_starts_with($basename, 'dockerfile.') || str_ends_with($basename, '.dockerfile')) {
            return 'dockerfile';
        }
        if ($basename === 'composer.json') {
            return 'composer';
        }
        if ($basename === 'knossos.json' || $basename === 'knossos.jsonc') {
            return 'knossos';
        }
        if ($basename === 'package.json') {
            return 'node';
        }
        // An Azure Functions binding manifest. The basename is generic enough
        // that another tool could own it, so the reader below asks for the
        // `scriptFile` key rather than assuming the shape; a function.json that
        // is something else contributes no entry points and costs one unit.
        if ($basename === 'function.json') {
            return 'azure_function';
        }
        // An HTML shell is the only thing that reaches a single-page
        // application's entry module, and nothing in the project imports it.
        // Read as a unit rather than as a file: it contributes entry points,
        // not nodes, and no scanner parses HTML.
        if (str_ends_with($basename, '.html') || str_ends_with($basename, '.htm')) {
            return 'html';
        }
        // Compose files, CI workflows and deployment manifests all name source
        // files by path. Read for those paths only; no YAML parser is involved
        // and none is needed, for the same reason the Composer script reader
        // tokenises shell commands crudely.
        if (str_ends_with($basename, '.yml') || str_ends_with($basename, '.yaml')) {
            return 'yaml';
        }
        // NEON, PHPStan's config format, is YAML's shape: the rules and
        // extensions it registers are class names nothing in PHP references.
        if (str_ends_with($basename, '.neon') || str_ends_with($basename, '.neon.dist')) {
            return 'yaml';
        }
        // A Claude Code plugin runs its hooks and MCP servers from commands in
        // these files, which name the scripts by path; nothing imports them.
        $normalized = str_replace('\\', '/', $relativePath);
        if (in_array($basename, ['hooks.json', '.mcp.json', 'plugin.json'], true)
            || preg_match('#(?:^|/)\.claude/settings(?:\.[a-z]+)?\.json$#', strtolower($normalized)) === 1) {
            return 'agent_config';
        }
        if (in_array($basename, ['knip.json', 'knip.jsonc', '.knip.json', '.knip.jsonc'], true)) {
            return 'knip';
        }
        if ($basename === 'pyproject.toml') {
            return 'python';
        }
        // requirements.txt and its per-environment siblings (requirements-dev.txt,
        // requirements-prod.txt) are the legacy Python dependency manifest — the
        // pyproject.toml of projects that never migrated to PEP 621. Recorded as
        // their own unit so an edit invalidates the analyzer cache the way a
        // composer.json edit does.
        if ($basename === 'requirements.txt' || (str_starts_with($basename, 'requirements-') && str_ends_with($basename, '.txt'))) {
            return 'requirements';
        }
        if ($basename === 'tsconfig.json' || (str_starts_with($basename, 'tsconfig.') && str_ends_with($basename, '.json'))) {
            return 'typescript';
        }
        if ($basename === 'cargo.toml') {
            return 'cargo';
        }

        // A tool config is read TWICE: as an ordinary source module by the
        // language worker, and as a unit here for the files it tells its tool
        // to load. The two `if` blocks in discover() are independent, so one
        // file may be both — which is why this needs no scanner change.
        if (ToolConfigModuleRule::isToolConfigPath($relativePath) || $basename === 'cypress.json') {
            return 'tool_config';
        }

        return null;
    }

    /**
     * Whether a project-relative path can be carried through ids, protocol
     * messages and JSON results. Shared with the drift oracles so a name the
     * walk skips is never reported as an addition.
     */
    public static function isSupportedPath(string $relative): bool
    {
        return mb_check_encoding($relative, 'UTF-8') && preg_match('/[\x00-\x1f\x7f]/', $relative) !== 1;
    }
}
