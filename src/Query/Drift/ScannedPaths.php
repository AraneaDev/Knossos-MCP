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
 *
 * "Would have put in it" is wider than "holds a node for". Discovery reads
 * composer.json, package.json, tsconfig.json, pyproject.toml, workflow YAML
 * and the rest as project units: they never become `files` rows, but they
 * decide which frameworks are enriched, which analyzer configuration hash a
 * contribution is cached under, and which paths are entry points. A predicate
 * that asked only about languages answered "not an input" for every one of
 * them, so adding a manifest was invisible drift. Both halves are asked here,
 * and {@see \Knossos\Discovery\UnitInputSet} supplies the stored hashes that
 * make an *edited* manifest decidable by the same rule.
 */
final readonly class ScannedPaths implements TrackedPathPredicate
{
    public function __construct(private IgnoreMatcher $ignores) {}

    /**
     * The project's own configured ignores on top of the defaults IgnoreMatcher already applies.
     *
     * The patterns are the ones the scan itself recorded, so what the probe
     * excludes is what discovery excluded rather than a second guess at it. The
     * scan used to persist no ignores at all and this rebuilt the defaults
     * alone, so every path a project ignored on its own account counted as
     * drift here: a staleness a rescan could never clear, because the rescan
     * would skip the very files that produced it.
     */
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
     *
     * A manifest answers yes for the reason given in the class docblock: the
     * scan read it, and what it says changes what a rescan would produce.
     *
     * The project's own configuration file is exempt from the ignores here
     * because it is exempt from them in the walk. Asked through the walk's own
     * predicate rather than restated, so the two cannot come apart.
     */
    public function tracks(string $relativePath, string $absolutePath): bool
    {
        if (!ProjectDiscoverer::isConfigurationFile($relativePath) && $this->ignores->matches($relativePath)) {
            return false;
        }

        return is_dir($absolutePath)
            || ProjectDiscoverer::languageFor($relativePath, $absolutePath) !== null
            || ProjectDiscoverer::unitKindFor($relativePath) !== null;
    }
}
