<?php

declare(strict_types=1);

namespace Knossos\Discovery;

final readonly class IgnoreMatcher
{
    private const EXCLUDED_SEGMENTS = [
        '.git',
        '.knossos',
        'vendor',
        'node_modules',
        'coverage',
        '.next',
        '.nuxt',
        '.venv',
        'venv',
        '__pycache__',
        '.tox',
        '.mypy_cache',
        '.pytest_cache',
        // Generated build output and mutation-testing sandboxes are not source.
        // '.stryker-tmp' in particular holds one full project copy per sandbox
        // (each with its own tsconfig), which would otherwise multiply the
        // TypeScript program count and make scans slow or time out.
        '.stryker-tmp',
        'build',
        'dist',
    ];

    private const EXCLUDED_PREFIXES = [
        'public/build',
        'storage/framework',
    ];

    /** @param list<string> $patterns */
    public function __construct(private array $patterns) {}

    public function matches(string $relativePath): bool
    {
        $path = trim(str_replace('\\', '/', $relativePath), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        foreach ($segments as $segment) {
            if (in_array($segment, self::EXCLUDED_SEGMENTS, true)) {
                return true;
            }
        }

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        // User patterns follow gitignore semantics: last matching pattern wins, a
        // leading '!' re-includes, a slash-free pattern matches a basename at any
        // depth, and '**' spans directory segments. Built-in excludes above are
        // absolute and cannot be negated.
        $ignored = false;
        foreach ($this->patterns as $pattern) {
            $normalized = str_replace('\\', '/', $pattern);
            $negated = str_starts_with($normalized, '!');
            if ($negated) {
                $normalized = substr($normalized, 1);
            }
            $normalized = trim($normalized);
            $anchored = str_starts_with($normalized, '/') || str_contains(trim($normalized, '/'), '/');
            $normalized = trim($normalized, '/');
            if ($normalized === '') {
                continue;
            }

            if ($this->patternMatches($normalized, $anchored, $path, $segments)) {
                $ignored = !$negated;
            }
        }

        return $ignored;
    }

    /** @param list<string> $segments */
    private function patternMatches(string $pattern, bool $anchored, string $path, array $segments): bool
    {
        // Trailing '/**' ignores the directory itself and everything under it, so
        // reduce it to its base and let the descendant suffix below cover contents.
        if (str_ends_with($pattern, '/**')) {
            $pattern = substr($pattern, 0, -3);
            $anchored = true;
        }
        $regex = self::toRegex($pattern);

        if ($anchored) {
            // Anchor to the project root; the '(?:/.*)?' suffix ignores descendants
            // when the pattern names a directory (gitignore directory semantics).
            return preg_match('#^' . $regex . '(?:/.*)?$#', $path) === 1;
        }

        // A slash-free pattern matches a file or directory of that name at any depth;
        // matching any path segment covers both the file itself and ignored contents.
        foreach ($segments as $segment) {
            if (preg_match('#^' . $regex . '$#', $segment) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Translate a gitignore glob fragment into a PCRE body (delimiter '#'). */
    private static function toRegex(string $pattern): string
    {
        $out = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; ++$i) {
            $char = $pattern[$i];
            if ($char === '*') {
                if ($i + 1 < $length && $pattern[$i + 1] === '*') {
                    ++$i;
                    if ($i + 1 < $length && $pattern[$i + 1] === '/') {
                        ++$i;
                        $out .= '(?:.*/)?';
                    } else {
                        $out .= '.*';
                    }
                } else {
                    $out .= '[^/]*';
                }
            } elseif ($char === '?') {
                $out .= '[^/]';
            } elseif ($char === '[') {
                $close = self::characterClassEnd($pattern, $i, $length);
                if ($close === null) {
                    $out .= '\\[';
                } else {
                    $class = substr($pattern, $i, $close - $i + 1);
                    // gitignore negates a class with a leading '!'; PCRE uses '^'.
                    $class = preg_replace('/^\[!/', '[^', $class);
                    $out .= $class;
                    $i = $close;
                }
            } else {
                $out .= preg_quote($char, '#');
            }
        }

        return $out;
    }

    private static function characterClassEnd(string $pattern, int $start, int $length): ?int
    {
        $j = $start + 1;
        if ($j < $length && $pattern[$j] === '!') {
            ++$j;
        }
        if ($j < $length && $pattern[$j] === ']') {
            ++$j;
        }
        while ($j < $length && $pattern[$j] !== ']') {
            ++$j;
        }

        return $j < $length ? $j : null;
    }
}
