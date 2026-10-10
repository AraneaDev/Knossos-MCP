<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Discovery\AllowedRoots;
use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\RootGuard;
use Knossos\Discovery\RootNotFoundException;
use PDO;

/**
 * Which project a scan requested by path may write to, if any.
 *
 * Shared by the commands the Claude Code mod runs that scan: `turn-brief`
 * and `rescan`. Both scan only an existing project inside a root the
 * operator allowed, so both ask the same three questions in the same order:
 * is the path itself allowed, does a scanned project contain it, and is that
 * project's root (an ancestor, when the resolver walked up) allowed too.
 * The first "no" is the answer, as status fields the caller merges into its
 * own envelope.
 */
final readonly class ScanTarget
{
    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param string $databasePath where $pdo lives; locates `roots.json`
     */
    public function __construct(
        private PDO $pdo,
        private string $databasePath,
    ) {}

    /**
     * The allow-list, the project to scan, and the refusal when there is none.
     *
     * Exactly one of the project and the refusal is null.
     *
     * @return array{0: AllowedRoots, 1: array<string, mixed>|null, 2: array<string, mixed>|null}
     */
    public function resolve(string $absolute): array
    {
        $allowed = new AllowedRoots(AllowedRoots::fromEnvironment(), $this->rootsFile());
        $refusal = $this->refusal($allowed, $absolute);
        if ($refusal !== null) {
            return [$allowed, null, $refusal];
        }
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        if ($project === null) {
            return [$allowed, null, ['status' => 'unscanned']];
        }
        // The resolver may have walked up to an ancestor project: that root is what gets scanned, so it is what must be allowed.
        $refusal = $this->refusal($allowed, (string) $project['root_realpath']);

        return $refusal === null ? [$allowed, $project, null] : [$allowed, null, $refusal];
    }

    /**
     * The status fields for a path the allow-list refuses, or null when it is allowed.
     *
     * `refused_root` names what has to be allowed: the path itself, or the
     * ancestor project root the path resolved to, which is what gets scanned.
     *
     * @return array<string, mixed>|null
     */
    private function refusal(AllowedRoots $allowed, string $path): ?array
    {
        try {
            (new RootGuard($allowed))->resolve($path);
        } catch (RootNotFoundException) {
            return ['status' => 'missing'];
        } catch (DiscoveryException) {
            return ['status' => 'not-allowed', 'roots_file' => $this->rootsFile(), 'refused_root' => $path];
        }
        return null;
    }

    /** `roots.json` beside the database, or the override the environment names. */
    private function rootsFile(): string
    {
        return AllowedRoots::defaultConfigPath($this->databasePath);
    }
}
