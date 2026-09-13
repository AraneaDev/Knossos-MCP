<?php

declare(strict_types=1);

namespace Knossos\Git;

/**
 * The tracked paths that differed from a scan's recorded commit when the scan
 * read them.
 *
 * A scan can read a tracked file that is modified relative to HEAD, storing a
 * hash of the working-tree bytes while recording an unchanged commit. Restore
 * that file afterwards and it becomes invisible to every listing the drift
 * oracle asks git for: `diff <head>` no longer names it (it matches HEAD
 * again), `ls-files --others` never did (it is tracked), and the index
 * cross-check finds it exactly where it belongs. The stored hash is then of
 * content that no longer exists on disk, and the graph reports fresh.
 *
 * Recording the set closes that: a path in it cannot be assumed to hash equal
 * to HEAD, so it is a candidate on every later probe whatever git says about
 * it now. It is derived from the bytes discovery read rather than from a later
 * question to git — see {@see DirtyPathResolver} — because a question asked at
 * any other moment describes another moment. Deciding it still costs one file hash against the graph's own
 * stored value, exactly like any other candidate, so a path that has not
 * actually moved reports no drift.
 *
 * {@see self::MAX_PATHS} bounds what is persisted. Past it the set is marked
 * incomplete rather than silently trimmed, because a trimmed set would read
 * as a complete one and put the invisible-restore hole straight back.
 */
final readonly class DirtyPathSet
{
    /**
     * Paths persisted before the set is declared incomplete.
     *
     * Ordinary uncommitted work is tens of paths; a repository with thousands
     * dirty at once is mid-rebase or mid-reformat, which is not a tree anyone
     * should be answering architecture queries from. The bound keeps a scan
     * row from carrying a megabyte of paths, and the incomplete marker keeps
     * that trade honest: an oracle handed one declines.
     */
    public const MAX_PATHS = 5000;

    /** @param list<string> $paths */
    private function __construct(public array $paths, public bool $complete) {}

    /**
     * A set from the paths git named, marked incomplete when there are more of
     * them than {@see self::MAX_PATHS}.
     *
     * @param list<string> $paths
     */
    public static function of(array $paths): self
    {
        $unique = array_values(array_unique(array_filter($paths, static fn(string $path): bool => $path !== '')));
        sort($unique, SORT_STRING);
        if (count($unique) > self::MAX_PATHS) {
            return new self(array_slice($unique, 0, self::MAX_PATHS), false);
        }

        return new self($unique, true);
    }

    /** A clean working tree, which is a complete answer and not an absent one. */
    public static function clean(): self
    {
        return new self([], true);
    }

    /**
     * The set a scan persisted, or null when it recorded none it can be
     * trusted on.
     *
     * Null covers every shape the caller must decline for rather than decide
     * from: the column never written (a gitless project, or a scan predating
     * the column), a value that no longer parses, and a set the scan itself
     * marked incomplete. All three mean the same thing to the oracle — the
     * invisible-restore hole cannot be ruled out — so they are collapsed here
     * rather than at each call site.
     */
    public static function decode(?string $json): ?self
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || ($decoded['complete'] ?? null) !== true || !is_array($decoded['paths'] ?? null)) {
            return null;
        }
        $paths = array_values(array_filter($decoded['paths'], is_string(...)));
        if (count($paths) !== count($decoded['paths'])) {
            return null;
        }

        return new self($paths, true);
    }

    /** The persisted form, carrying the completeness flag the decoder refuses to guess at. */
    public function encode(): string
    {
        return (string) json_encode(['paths' => $this->paths, 'complete' => $this->complete], JSON_THROW_ON_ERROR);
    }

    /**
     * The paths as a set, which is how both the oracle's candidate listings
     * are held so a path named twice is not counted twice.
     *
     * @return array<string, true>
     */
    public function asKeys(): array
    {
        return $this->paths === [] ? [] : array_fill_keys($this->paths, true);
    }
}
