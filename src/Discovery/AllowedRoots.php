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
    /** Fingerprint (mtime:size) of the file behind $fileRoots, or null when it was absent. */
    private ?string $fingerprint = null;
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
        // Stat rather than parse: an unchanged file is the overwhelmingly common
        // case and costs one syscall here instead of a read plus a JSON decode.
        $fingerprint = null;
        if (is_file($this->configPath)) {
            $stat = @stat($this->configPath);
            $fingerprint = $stat === false ? null : $stat['mtime'] . ':' . $stat['size'];
        }
        if ($this->loaded && $fingerprint === $this->fingerprint) {
            return $this->fileRoots;
        }
        $this->loaded = true;
        $this->fingerprint = $fingerprint;
        $this->fileRoots = $fingerprint === null ? [] : self::parse($this->configPath);

        return $this->fileRoots;
    }

    /** @return list<string> */
    private static function parse(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return [];
        }
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
            if (is_string($root) && trim($root) !== '') {
                $resolved[] = rtrim(trim($root), '/');
            }
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
