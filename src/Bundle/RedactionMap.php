<?php

declare(strict_types=1);

namespace Knossos\Bundle;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Maps every discovered path in a graph to a salted token, for one export.
 *
 * A path reaches a bundle in more places than the files table: a TypeScript
 * name is `path#Symbol`, an owner key is `scanner:file:path`, a boundary
 * matcher names a directory, a Python module id is the path spelled with dots.
 * The keys are every file path, every directory with at least two segments
 * that holds a file, every directory a path boundary names, those
 * directories spelled with dots, and every Python name that came from a file
 * or spells a directory holding one (a namespace package has no file). A key
 * is replaced only where it stands as a whole token, so `app` never matches
 * inside `apps`, `my-app` or `django.app`.
 *
 * The salt is the caller's, and lives only as long as this map: without it a
 * token is an unsalted hash of a guessable path, which is the path.
 */
final readonly class RedactionMap
{
    /** The shortest salt accepted: anything less is guessable. */
    public const MIN_SALT_BYTES = 32;

    /**
     * A key may start at the start of a string or after any character that
     * cannot continue a name: `/` counts, so a key is found inside an
     * absolute or `./` path; `.` and `-` do not, so `app` is never found in
     * `django.app` or `my-app`.
     */
    private const BEFORE = '/[^A-Za-z0-9_.\-]/';

    /** A key may end at the end of a string or before any character that cannot continue a word. */
    private const AFTER = '/[^A-Za-z0-9_]/';

    /**
     * Build the map from its keys and their replacements.
     *
     * @param array<string, string> $replacements key to token
     * @param array<string, true> $paths the keys that are file or directory paths, not dotted names
     */
    private function __construct(#[SensitiveParameter] private string $salt, private array $replacements, private array $paths, private int $longest) {}

    /**
     * Build the map from a bundle's files, nodes and boundaries, in that order of precedence.
     *
     * @param array<string, list<array<string, mixed>>> $tables
     */
    public static function fromPayload(array $tables, #[SensitiveParameter] string $salt): self
    {
        if (strlen($salt) < self::MIN_SALT_BYTES) {
            throw new InvalidArgumentException('A redaction salt must be at least 32 bytes.');
        }
        $replacements = [];
        $paths = [];
        $directories = [];
        $ancestors = [];
        foreach ($tables['files'] ?? [] as $file) {
            $path = $file['relative_path'] ?? null;
            if (!is_string($path) || $path === '') {
                continue;
            }
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $replacements[$path] = 'redacted/' . self::digest($path, $salt, 24) . ($extension === '' ? '' : '.' . strtolower($extension));
            $paths[$path] = true;
            for ($directory = dirname($path); $directory !== '.'; $directory = dirname($directory)) {
                $ancestors[$directory] = true;
                if (str_contains($directory, '/')) {
                    $directories[$directory] = $directory;
                }
            }
        }
        foreach ($tables['nodes'] ?? [] as $node) {
            $name = $node['canonical_name'] ?? null;
            if (($node['language'] ?? null) !== 'py' || !is_string($name) || $name === '') {
                continue;
            }
            // A module read from a file, or any Python name that spells a
            // directory holding files: a namespace package has no file of
            // its own and reaches the graph only as the name of an import.
            if ((($node['kind'] ?? null) === 'module' && ($node['file_id'] ?? null) !== null) || isset($ancestors[str_replace('.', '/', $name)])) {
                $replacements[$name] ??= 'redacted_' . self::digest($name, $salt, 24);
            }
        }
        foreach ($tables['boundaries'] ?? [] as $boundary) {
            $directory = self::namedDirectory($boundary['matcher_json'] ?? null);
            if ($directory !== null) {
                $directories[$directory] = $directory;
            }
        }
        foreach ($directories as $directory) {
            if (!isset($replacements[$directory])) {
                $replacements[$directory] = 'redacted-dir/' . self::digest($directory, $salt, 24);
                $paths[$directory] = true;
            }
            $dotted = str_replace('/', '.', $directory);
            $replacements[$dotted] ??= 'redacted_' . self::digest($dotted, $salt, 24);
        }
        $longest = max([0, ...array_map(strlen(...), array_keys($replacements))]);
        return new self($salt, $replacements, $paths, $longest);
    }

    /** The token for a key, or null when it is not a discovered path. */
    public function token(string $path): ?string
    {
        return $this->replacements[$path] ?? null;
    }

    /**
     * Replace every key that stands as a whole token in the text, longest first.
     */
    public function scrub(string $text): string
    {
        return $this->scan($text, false);
    }

    /**
     * Replace file and directory paths only, never a dotted module name: for
     * a JSON object key, where a top-level module such as `main` or `config`
     * would otherwise rename an ordinary key.
     */
    public function scrubPaths(string $text): string
    {
        return $this->scan($text, true);
    }

    /**
     * Redact a qualified key, `<scanner>:<kind>:<rest>` (an owner key, a
     * scanner-local id), in its `<rest>` only, so a module that happens to
     * share a scanner's name (`knossos`) never rewrites the prefix. A value
     * without that shape names no path and is kept.
     */
    public function scrubQualified(string $text): string
    {
        $parts = explode(':', $text, 3);
        return count($parts) === 3 ? $parts[0] . ':' . $parts[1] . ':' . $this->scrub($parts[2]) : $text;
    }

    /**
     * One pass: each candidate start is tried only against the candidate ends
     * no further away than the longest key, so the cost follows the text, not
     * the number of keys.
     */
    private function scan(string $text, bool $pathsOnly): string
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
                if (isset($this->replacements[$candidate]) && (!$pathsOnly || isset($this->paths[$candidate]))) {
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
    private static function digest(string $value, #[SensitiveParameter] string $salt, int $length): string
    {
        return substr(hash_hmac('sha256', $value, $salt), 0, $length);
    }
}
