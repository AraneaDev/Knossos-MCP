<?php

declare(strict_types=1);

namespace Knossos\Query\Drift;

use Knossos\Discovery\IgnoreMatcher;
use Knossos\Discovery\ProjectDiscoverer;
use PDO;

/**
 * Whether a path beside the graph is one the scanner would have put in it.
 *
 * Both oracles need this answer and must give the same one: a path that counts
 * as drift for one and not the other makes a graph's freshness depend on which
 * oracle happened to answer. It also decides whether drift is repairable at
 * all — a file discovery would skip cannot be absorbed by a rescan, so counting
 * it reports a staleness no amount of scanning can clear.
 */
final readonly class ScannedPaths
{
    public function __construct(private IgnoreMatcher $ignores) {}

    /** The project's own configured ignores on top of the defaults IgnoreMatcher already applies. */
    public static function forProject(PDO $pdo, string $projectId): self
    {
        $statement = $pdo->prepare('SELECT config_json FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        $raw = $statement->fetchColumn();
        $patterns = [];
        if (is_string($raw) && $raw !== '') {
            // A malformed config must not make a probe throw: the graph is
            // still answerable, and discovery reports the same fault properly.
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && is_array($decoded['ignores'] ?? null)) {
                $patterns = array_values(array_filter($decoded['ignores'], is_string(...)));
            }
        }

        return new self(new IgnoreMatcher($patterns));
    }

    /**
     * Whether discovery would have tracked this path.
     *
     * A directory answers yes on its own account rather than by extension:
     * nothing inside a directory the scan never saw is in the graph, and the
     * walk oracle only ever sees the new directory rather than the source
     * files under it.
     */
    public function tracks(string $relativePath, string $absolutePath): bool
    {
        if ($this->ignores->matches($relativePath)) {
            return false;
        }

        return is_dir($absolutePath) || ProjectDiscoverer::languageFor($relativePath, $absolutePath) !== null;
    }
}
