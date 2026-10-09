<?php

declare(strict_types=1);

namespace Knossos\Bundle;

/**
 * Maps every discovered path in a graph to a salted token, for one export.
 *
 * A path reaches a bundle in more places than the files table: a TypeScript
 * name is `path#Symbol`, an owner key is `scanner:file:path`, a boundary
 * matcher names a directory, a Python module id is the path spelled with dots.
 * The keys are every file path, every directory with at least two segments
 * that holds a file, every directory a path boundary names, and every Python
 * module id that came from a file. A key is replaced only where it stands as a
 * whole token, so `app` never matches inside `apps` or `django.app`.
 *
 * The salt is the caller's, and lives only as long as this map: without it a
 * token is an unsalted hash of a guessable path, which is the path.
 */
final readonly class RedactionMap
{
    /** A key may start at the start of a string or after one of these. */
    private const BEFORE = '/["\':#(=,\s]/';

    /** A key may end at the end of a string or before one of these. */
    private const AFTER = '/["\'#:)\/.,\s]/';

    /**
     * Build the map from its keys and their replacements.
     *
     * @param array<string, string> $replacements key to token
     */
    private function __construct(private string $salt, private array $replacements, private int $longest) {}

    /**
     * Build the map from a bundle's files, nodes and boundaries, in that order of precedence.
     *
     * @param array<string, list<array<string, mixed>>> $tables
     */
    public static function fromPayload(array $tables, string $salt): self
    {
        $replacements = [];
        $directories = [];
        foreach ($tables['files'] ?? [] as $file) {
            $path = $file['relative_path'] ?? null;
            if (!is_string($path) || $path === '') {
                continue;
            }
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $replacements[$path] ??= 'redacted/' . self::digest($path, $salt, 24) . ($extension === '' ? '' : '.' . strtolower($extension));
            $directory = $path;
            while (($slash = strrpos($directory, '/')) !== false) {
                $directory = substr($directory, 0, $slash);
                if (!str_contains($directory, '/')) {
                    break;
                }
                $directories[$directory] = true;
            }
        }
        foreach ($tables['nodes'] ?? [] as $node) {
            $name = $node['canonical_name'] ?? null;
            if (($node['language'] ?? null) === 'py' && ($node['kind'] ?? null) === 'module' && ($node['file_id'] ?? null) !== null && is_string($name) && $name !== '') {
                $replacements[$name] ??= 'redacted_' . self::digest($name, $salt, 24);
            }
        }
        foreach ($tables['boundaries'] ?? [] as $boundary) {
            $directory = self::namedDirectory($boundary['matcher_json'] ?? null);
            if ($directory !== null) {
                $directories[$directory] = true;
            }
        }
        foreach (array_keys($directories) as $directory) {
            $directory = (string) $directory;
            $replacements[$directory] ??= 'redacted-dir/' . self::digest($directory, $salt, 24);
        }
        $longest = 0;
        foreach (array_keys($replacements) as $key) {
            $longest = max($longest, strlen((string) $key));
        }
        return new self($salt, $replacements, $longest);
    }

    /** The token for a key, or null when it is not a discovered path. */
    public function token(string $path): ?string
    {
        return $this->replacements[$path] ?? null;
    }

    /**
     * Replace every key that stands as a whole token in the text, longest first.
     *
     * One pass: each candidate start is tried only against the candidate ends
     * no further away than the longest key, so the cost follows the text, not
     * the number of keys.
     */
    public function scrub(string $text): string
    {
        $length = strlen($text);
        preg_match_all(self::BEFORE, $text, $before, PREG_OFFSET_CAPTURE);
        preg_match_all(self::AFTER, $text, $after, PREG_OFFSET_CAPTURE);
        $starts = [0, ...array_map(static fn(array $match): int => $match[1] + 1, $before[0])];
        $ends = [...array_column($after[0], 1), $length];
        $endCount = count($ends);
        $result = '';
        $cursor = 0;
        $first = 0;
        foreach ($starts as $start) {
            if ($start < $cursor || $start >= $length) {
                continue;
            }
            while ($ends[$first] <= $start) {
                ++$first;
            }
            $match = null;
            for ($index = $first; $index < $endCount && $ends[$index] - $start <= $this->longest; ++$index) {
                $candidate = substr($text, $start, $ends[$index] - $start);
                if (isset($this->replacements[$candidate])) {
                    $match = $candidate;
                }
            }
            if ($match !== null) {
                $result .= substr($text, $cursor, $start - $cursor) . $this->replacements[$match];
                $cursor = $start + strlen($match);
            }
        }
        return $result . substr($text, $cursor);
    }

    /** A salted id with the original's kind prefix (`symbol`, `file`, ...) kept. */
    public function id(string $id): string
    {
        $prefix = preg_match('/^([A-Za-z][A-Za-z0-9-]*)_/', $id, $match) === 1 ? $match[1] : 'id';
        return $prefix . '_' . self::digest($id, $this->salt, 48);
    }

    /** A salted content hash: still one value per content, no longer the content's well-known hash. */
    public function hashContent(string $hash): string
    {
        return hash_hmac('sha256', $hash, $this->salt);
    }

    /** The directory a path boundary's matcher names, without its trailing slash. */
    private static function namedDirectory(mixed $matcherJson): ?string
    {
        $matcher = is_string($matcherJson) ? json_decode($matcherJson, true) : null;
        if (!is_array($matcher) || ($matcher['type'] ?? null) !== 'path_prefix' || !is_string($matcher['value'] ?? null)) {
            return null;
        }
        $directory = rtrim($matcher['value'], '/');
        return $directory === '' ? null : $directory;
    }

    /** The first `$length` hex characters of the salted digest of a value. */
    private static function digest(string $value, string $salt, int $length): string
    {
        return substr(hash_hmac('sha256', $value, $salt), 0, $length);
    }
}
