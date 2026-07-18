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
        $segments = explode('/', $path);
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

        foreach ($this->patterns as $pattern) {
            $normalized = trim(str_replace('\\', '/', $pattern), '/');
            if ($normalized === '') {
                continue;
            }

            if (str_ends_with($normalized, '/**')) {
                $base = substr($normalized, 0, -3);
                if ($path === $base || str_starts_with($path, $base . '/')) {
                    return true;
                }
            }

            if (fnmatch($normalized, $path, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }
}
