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
 *
 * The two ways a path can be refused are separate types, not one message a
 * caller would have to match on: a path that is not there at all raises
 * {@see RootNotFoundException}, a path outside every root raises
 * {@see DiscoveryException} itself. Only the second is fixed by granting a
 * root, so anything that offers that remedy has to be able to tell them apart.
 */
final readonly class RootGuard
{
    private AllowedRoots $roots;

    /** @param AllowedRoots|list<string> $allowedRoots */
    public function __construct(AllowedRoots|array $allowedRoots)
    {
        $this->roots = AllowedRoots::of($allowedRoots);
    }
    /** Canonicalise a requested root and confirm it lies inside an allowed one. */

    public function resolve(string $requestedRoot): string
    {
        // Resolved once. Checking existence and then resolving again left a
        // window in which the directory could go away: the second realpath()
        // returned false, the cast made it an empty string, and the caller was
        // told its root was outside the allow-list rather than missing.
        $resolved = realpath($requestedRoot);
        if ($resolved === false || !is_dir($resolved)) {
            throw new RootNotFoundException(sprintf('Project root does not exist or is not a directory: %s', $requestedRoot));
        }

        $root = self::normalize($resolved);
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

    /**
     * Whether a requested root is a directory this process can reach at all.
     *
     * The same test {@see resolve()} applies before it considers containment,
     * exposed so a caller with no allow-list to consult can still tell "no such
     * directory" from "outside every root" without writing its own
     * realpath()-plus-is_dir() and drifting from this one. resolve() calls it
     * rather than repeating it, so the two cannot disagree.
     */
    public static function exists(string $requestedRoot): bool
    {
        $root = realpath($requestedRoot);

        return $root !== false && is_dir($root);
    }

    /** Whether the server runs inside a container, where host paths are not its paths. */
    public static function containerised(): bool
    {
        return is_file('/.dockerenv') || getenv('KNOSSOS_CONTAINER') !== false;
    }
    /** Whether a canonical candidate lies within a canonical root, on segment boundaries. */

    public static function contains(string $root, string $candidate): bool
    {
        $root = rtrim(self::normalize($root), '/');
        $candidate = self::normalize($candidate);

        return $candidate === $root || str_starts_with($candidate, $root . '/');
    }
    /** Normalise separators so comparison is platform-independent. */

    private static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
