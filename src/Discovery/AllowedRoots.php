<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use JsonException;

/**
 * The set of directory trees the server may read, resolved at call time.
 *
 * Resolving on use rather than at construction is what lets one installation
 * serve every project: a root added to the configuration file is honoured by the
 * next request instead of the next restart. MCP `2026-07-28` deprecated the
 * Roots capability, and its stated migration is server configuration — this is
 * that configuration.
 *
 * Knossos only ever reads this file. Nothing in the server writes it, so
 * widening the boundary stays a deliberate act recorded on disk rather than
 * something a tool call can do to itself.
 */
final class AllowedRoots
{
    /** Sources are reported by {@see describe()} so an agent can see where a root came from. */
    public const SOURCE_STATIC = 'configured';
    public const SOURCE_FILE = 'roots-file';

    /** @var list<string> */
    private array $fileRoots = [];
    /** Raw bytes behind $fileRoots, or null when the file was absent or unreadable. */
    private ?string $rawContents = null;
    private bool $loaded = false;

    /** @param list<string> $staticRoots roots fixed for the lifetime of the process (flags, environment) */
    public function __construct(private readonly array $staticRoots, private readonly ?string $configPath = null) {}

    /**
     * Accept either an already-built instance or a plain list.
     *
     * The plain-list form keeps every existing call site and test fixture valid;
     * only callers that want file-backed roots need to build one explicitly.
     *
     * @param self|list<string> $roots
     */
    public static function of(self|array $roots): self
    {
        return $roots instanceof self ? $roots : new self(array_values($roots));
    }

    /**
     * The roots permitted right now.
     *
     * @return list<string>
     */
    public function current(): array
    {
        $roots = $this->staticRoots;
        foreach ($this->fromFile() as $root) {
            if (!in_array($root, $roots, true)) {
                $roots[] = $root;
            }
        }

        return array_values($roots);
    }

    public function configPath(): ?string
    {
        return $this->configPath;
    }

    /**
     * Each root with its origin and whether it exists, for `server_info`.
     *
     * Existence is worth reporting: a root that was configured for a different
     * machine, or a host path handed to a container, looks identical to a
     * working one until something tries to scan it.
     *
     * @return list<array{path: string, source: string, exists: bool}>
     */
    public function describe(): array
    {
        $described = [];
        foreach ($this->current() as $root) {
            $described[] = [
                'path' => $root,
                'source' => in_array($root, $this->staticRoots, true) ? self::SOURCE_STATIC : self::SOURCE_FILE,
                'exists' => is_dir($root),
            ];
        }

        return $described;
    }

    /**
     * Roots declared by the configuration file, re-read only when it changed.
     *
     * A malformed or unreadable file yields no roots rather than an exception:
     * it must not be able to take down a server whose flag-supplied roots are
     * perfectly serviceable.
     *
     * @return list<string>
     */
    private function fromFile(): array
    {
        if ($this->configPath === null) {
            return [];
        }
        // Compare contents, not a stat fingerprint. Two independent reasons a
        // stat cannot be trusted here, both of which would leave a long-lived
        // server serving an allow-list that has since been narrowed:
        //   1. PHP caches stat results per path, so is_file()/stat() keep
        //      reporting the values from the first call unless the cache is
        //      explicitly cleared.
        //   2. stat()'s mtime has one-second granularity, so even with the cache
        //      cleared, two same-size edits within one second are
        //      indistinguishable — swapping one root for another of equal length
        //      is exactly that case.
        // Reading is also what a stat would have preceded anyway, and the file
        // holds a handful of paths.
        $contents = @file_get_contents($this->configPath);
        if ($contents === false) {
            $this->loaded = true;
            $this->rawContents = null;

            return $this->fileRoots = [];
        }
        if ($this->loaded && $contents === $this->rawContents) {
            return $this->fileRoots;
        }
        $this->loaded = true;
        $this->rawContents = $contents;

        return $this->fileRoots = self::parse($contents);
    }

    /** @return list<string> */
    private static function parse(string $contents): array
    {
        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        // Accept both {"roots": [...]} and a bare [...] so a hand-edited file in
        // the obvious shape works rather than silently granting nothing.
        $roots = is_array($decoded) ? ($decoded['roots'] ?? $decoded) : [];
        if (!is_array($roots)) {
            return [];
        }
        $resolved = [];
        foreach ($roots as $root) {
            if (!is_string($root) || trim($root) === '') {
                continue;
            }
            $trimmed = trim($root);
            // rtrim would reduce "/" to "", which realpath() then rejects — a
            // filesystem-root grant would silently grant nothing at all. Whether
            // granting "/" is wise is the operator's call; silently ignoring what
            // they wrote is not.
            $normalised = rtrim($trimmed, '/');
            $resolved[] = $normalised === '' ? '/' : $normalised;
        }

        return array_values(array_unique($resolved));
    }

    /**
     * The roots file for a database path, so the file sits beside the graph it
     * grants access to and a stable `KNOSSOS_DATA_DIR` stabilises both together.
     */
    public static function defaultConfigPath(string $databasePath): string
    {
        $override = getenv('KNOSSOS_ROOTS_FILE');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return dirname($databasePath) . '/roots.json';
    }
}
