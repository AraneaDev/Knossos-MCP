<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use Closure;

/**
 * The patterns of a tree's `.gitignore` files, applied the way git applies them.
 *
 * A file git ignores is not the project's source: a framework cache, a local
 * tool's state, a generated client, a second checkout parked under the tree.
 * Walking them graphed thousands of files per project that no one wrote, and
 * dead-code analysis reported every one of them as unreferenced.
 *
 * The semantics are git's own. Each file's patterns are relative to the
 * directory holding it. Within one file the last matching pattern wins and a
 * leading `!` re-includes; across files the deepest one with a matching pattern
 * decides. A pattern with a slash at its start or middle is anchored to its
 * file's directory, and one without matches a name at any depth below it. A
 * trailing slash matches only directories, and `**` spans segments. A pattern
 * that does not compile is skipped, as git skips it, rather than failing a
 * scan over a line in somebody else's tooling file.
 *
 * Only files inside the tree count. The global excludes file and
 * `.git/info/exclude` belong to one machine's checkout, and a graph has to
 * come out the same wherever the tree is scanned.
 */
final class GitIgnoreRules
{
    /** @var array<string, list<array{regex: string, negated: bool, directoryOnly: bool}>> directory => rules, in file order */
    private array $rules = [];

    /** @var array<string, true> directories whose file has been asked for */
    private array $loaded = [];

    /**
     * @param ?Closure(string): ?string $loader the contents of `<directory>/.gitignore`,
     *        or null when there is none. Given, ancestors are loaded on demand, which is
     *        what a caller asking about single paths needs; the walk adds each file
     *        itself, because it hashes the same bytes.
     */
    public function __construct(private readonly ?Closure $loader = null) {}

    /** Take a `.gitignore`'s contents as the rules for the project-relative directory holding it. */
    public function add(string $directory, string $contents): void
    {
        $directory = trim($directory, '/');
        $this->loaded[$directory] = true;
        $rules = [];
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $rule = self::parse($line);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }
        if ($rules !== []) {
            $this->rules[$directory] = $rules;
        }
    }

    /**
     * Whether the rules ignore this path itself, leaving its ancestors aside.
     *
     * The walk asks this, since it never enters an ignored directory.
     */
    public function ignores(string $relativePath, bool $isDirectory): bool
    {
        $path = trim($relativePath, '/');
        if ($path === '') {
            return false;
        }
        $directory = $path;
        do {
            $directory = self::parent($directory);
            $this->load($directory);
            $decision = $this->decide($directory, $path, $isDirectory);
            if ($decision !== null) {
                return $decision;
            }
        } while ($directory !== '');

        return false;
    }

    /**
     * Whether the path, or any directory above it, is ignored.
     *
     * Git never looks inside an ignored directory, so nothing below one can be
     * re-included. A caller asking about a single path rather than walking to
     * it has to check each ancestor itself.
     */
    public function ignoresWithAncestors(string $relativePath, bool $isDirectory): bool
    {
        $path = trim($relativePath, '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $prefix = '';
        foreach (array_slice($segments, 0, -1) as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
            if ($this->ignores($prefix, true)) {
                return true;
            }
        }

        return $this->ignores($path, $isDirectory);
    }

    /** Ask the loader, once, for a directory's `.gitignore`. */
    private function load(string $directory): void
    {
        if ($this->loader === null || isset($this->loaded[$directory])) {
            return;
        }
        $this->loaded[$directory] = true;
        $contents = ($this->loader)($directory);
        if (is_string($contents)) {
            $this->add($directory, $contents);
        }
    }

    /** What one directory's file says about a path below it: ignored, re-included, or nothing. */
    private function decide(string $directory, string $path, bool $isDirectory): ?bool
    {
        $rules = $this->rules[$directory] ?? [];
        if ($rules === []) {
            return null;
        }
        $relative = $directory === '' ? $path : substr($path, strlen($directory) + 1);
        $decision = null;
        foreach ($rules as $rule) {
            if ($rule['directoryOnly'] && !$isDirectory) {
                continue;
            }
            if (preg_match($rule['regex'], $relative) === 1) {
                $decision = !$rule['negated'];
            }
        }

        return $decision;
    }

    /** The directory holding a project-relative path, `''` for the root. */
    private static function parent(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? '' : substr($path, 0, $slash);
    }

    /**
     * One line of a `.gitignore` as a rule, or null for a blank line, a comment, or a pattern that does not compile.
     *
     * @return ?array{regex: string, negated: bool, directoryOnly: bool}
     */
    private static function parse(string $line): ?array
    {
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }
        // Trailing spaces are dropped unless a backslash escapes the last one.
        while (str_ends_with($line, ' ') && !str_ends_with($line, '\\ ')) {
            $line = substr($line, 0, -1);
        }
        $negated = str_starts_with($line, '!');
        if ($negated) {
            $line = substr($line, 1);
        }
        $directoryOnly = str_ends_with($line, '/');
        $line = rtrim($line, '/');
        $anchored = str_contains($line, '/');
        $line = ltrim($line, '/');
        if ($line === '') {
            return null;
        }
        $body = self::patternRegex($line);
        if ($body === null) {
            return null;
        }
        $regex = '#' . ($anchored ? '^' : '(?:^|/)') . $body . '$#s';
        if (@preg_match($regex, '') === false) {
            return null;
        }

        return ['regex' => $regex, 'negated' => $negated, 'directoryOnly' => $directoryOnly];
    }

    /** A slash-separated glob as a regex body, or null when it does not parse. */
    private static function patternRegex(string $pattern): ?string
    {
        if ($pattern === '**') {
            return '.*';
        }
        $segments = explode('/', $pattern);
        $last = count($segments) - 1;
        $regex = '';
        $separator = false;
        foreach ($segments as $index => $segment) {
            if ($segment === '**') {
                if ($index === 0) {
                    $regex .= '(?:.*/)?';
                } elseif ($index === $last) {
                    $regex .= '/.*';
                } else {
                    $regex .= '/(?:.*/)?';
                }
                $separator = false;
                continue;
            }
            $part = self::segmentRegex($segment);
            if ($part === null) {
                return null;
            }
            $regex .= ($separator ? '/' : '') . $part;
            $separator = true;
        }

        return $regex;
    }

    /** One path segment's glob as a regex body, or null for an unterminated class. */
    private static function segmentRegex(string $segment): ?string
    {
        $regex = '';
        $length = strlen($segment);
        for ($i = 0; $i < $length; ++$i) {
            $character = $segment[$i];
            if ($character === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($segment[++$i], '#');
            } elseif ($character === '*') {
                $regex .= '[^/]*';
            } elseif ($character === '?') {
                $regex .= '[^/]';
            } elseif ($character === '[') {
                $end = $i + 1;
                if ($end < $length && ($segment[$end] === '!' || $segment[$end] === '^')) {
                    ++$end;
                }
                if ($end < $length && $segment[$end] === ']') {
                    ++$end;
                }
                $end = strpos($segment, ']', $end);
                if ($end === false) {
                    return null;
                }
                $class = substr($segment, $i + 1, $end - $i - 1);
                $negated = $class !== '' && ($class[0] === '!' || $class[0] === '^');
                if ($negated) {
                    $class = substr($class, 1);
                }
                $members = '';
                foreach (str_split($class) as $member) {
                    $members .= $member === '-' ? '-' : preg_quote($member, '#');
                }
                $regex .= '[' . ($negated ? '^' : '') . $members . ']';
                $i = $end;
            } else {
                $regex .= preg_quote($character, '#');
            }
        }

        return $regex;
    }
}
