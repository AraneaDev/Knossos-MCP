<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

use InvalidArgumentException;

final class RelativePath
{
    private function __construct() {}

    public static function assertValid(string $path, string $field = 'path'): void
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new InvalidArgumentException(sprintf('%s must be a normalized project-relative path.', $field));
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            throw new InvalidArgumentException(sprintf('%s must not be absolute.', $field));
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException(sprintf('%s contains an invalid path segment.', $field));
            }
        }
    }
}
