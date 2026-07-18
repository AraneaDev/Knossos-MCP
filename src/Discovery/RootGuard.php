<?php

declare(strict_types=1);

namespace Knossos\Discovery;

final readonly class RootGuard
{
    /** @param list<string> $allowedRoots */
    public function __construct(private array $allowedRoots) {}

    public function resolve(string $requestedRoot): string
    {
        $root = realpath($requestedRoot);
        if ($root === false || !is_dir($root)) {
            throw new DiscoveryException(sprintf('Project root does not exist or is not a directory: %s', $requestedRoot));
        }

        $root = self::normalize($root);
        foreach ($this->allowedRoots as $allowedRoot) {
            $allowed = realpath($allowedRoot);
            if ($allowed === false || !is_dir($allowed)) {
                throw new DiscoveryException(sprintf('Configured allowed root does not exist: %s', $allowedRoot));
            }

            if (self::contains(self::normalize($allowed), $root)) {
                return $root;
            }
        }

        throw new DiscoveryException('Project root is outside the configured allowed roots.');
    }

    public static function contains(string $root, string $candidate): bool
    {
        $root = rtrim(self::normalize($root), '/');
        $candidate = self::normalize($candidate);

        return $candidate === $root || str_starts_with($candidate, $root . '/');
    }

    private static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
