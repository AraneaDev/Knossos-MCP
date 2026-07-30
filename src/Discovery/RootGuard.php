<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * The filesystem boundary: resolves a requested path or refuses it.
 *
 * The only thing standing between the server and the rest of the disk, so it
 * canonicalises with realpath before comparing — a `..` traversal or a symlink
 * would otherwise pass a naive prefix check. A stale entry in the allow-list is
 * skipped rather than vetoing every later root, and a rejection explains which
 * roots are in force and where to add another, because a caller told only "no" can
 * do nothing but guess.
 */
final readonly class RootGuard
{
    private AllowedRoots $roots;

    /** @param AllowedRoots|list<string> $allowedRoots */
    public function __construct(AllowedRoots|array $allowedRoots)
    {
        $this->roots = AllowedRoots::of($allowedRoots);
    }

    public function resolve(string $requestedRoot): string
    {
        $root = realpath($requestedRoot);
        if ($root === false || !is_dir($root)) {
            throw new DiscoveryException(sprintf('Project root does not exist or is not a directory: %s', $requestedRoot));
        }

        $root = self::normalize($root);
        $allowedRoots = $this->roots->current();
        foreach ($allowedRoots as $allowedRoot) {
            $allowed = realpath($allowedRoot);
            if ($allowed === false || !is_dir($allowed)) {
                // A single stale/removed allow-root must not veto every later
                // root; skip it and keep matching against the remaining entries.
                continue;
            }

            if (self::contains(self::normalize($allowed), $root)) {
                return $root;
            }
        }

        throw new DiscoveryException(self::rejection($requestedRoot, $allowedRoots, $this->roots->configPath()));
    }

    /**
     * Explain a rejection well enough to act on.
     *
     * The bare "outside the configured allowed roots" this replaces gave a
     * caller nothing to work from: not the roots in force, not where to add one,
     * and no hint that a containerised server sees mounted paths rather than
     * host paths. Each of those turns a dead end into a next step.
     *
     * @param list<string> $allowedRoots
     */
    private static function rejection(string $requestedRoot, array $allowedRoots, ?string $configPath): string
    {
        $message = sprintf('Project root is outside the configured allowed roots: %s', $requestedRoot);
        $message .= $allowedRoots === []
            ? "\nNo roots are configured."
            : "\nConfigured roots: " . implode(', ', $allowedRoots);
        if ($configPath !== null) {
            $message .= sprintf("\nAdd it to %s as {\"roots\": [\"%s\"]} — the file is re-read per request, so no restart is needed.", $configPath, $requestedRoot);
        }
        if (self::containerised()) {
            $message .= "\nThis server runs in a container and can only see mounted paths, not host paths. Call server_info for the roots it can actually reach.";
        }

        return $message;
    }

    /** Whether the server runs inside a container, where host paths are not its paths. */
    public static function containerised(): bool
    {
        return is_file('/.dockerenv') || getenv('KNOSSOS_CONTAINER') !== false;
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
