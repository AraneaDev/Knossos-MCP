<?php

declare(strict_types=1);

namespace Knossos\Query;

use PDO;

/**
 * A component's blast radius as rings: what depends on it directly (one
 * hop), through one other component (two hops), and further (three hops and
 * more), each ring with how many of its components a test reaches and the
 * test files that do.
 *
 * The dependents are found hop by hop over the impact edges, as
 * {@see ArchitectureQueryService::impactAnalysis()} finds them but read a
 * hop at a time and with far more of them, bounded in depth
 * ({@see self::DEPTH}), count and time; a test component is no member of a
 * ring but the tests' own. A component is reached by a
 * test when a test depends on it, directly or through others the search
 * found: worked out over the impact edges among everything found, so a
 * route the depth cut off does not count. Read-only, never scans.
 */
final readonly class BlastRadiusService
{
    /** How many hops the search goes out. */
    public const DEPTH = 6;

    /** The most dependents read. */
    private const DEPENDENTS = 1500;

    /** How long the search may take. */
    private const TIMEOUT_MS = 2000;

    /** Components and tests named per ring. */
    public const NAMED = 12;

    /** @param PDO $pdo an existing, migrated graph database */
    public function __construct(private PDO $pdo) {}

    /**
     * The rings around `$component` in the project owning `$path`; `unscanned`,
     * or `not-found` when no one component goes by that name.
     *
     * @return array<string, mixed>
     */
    public function rings(string $path, string $component): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = ['path' => $absolute, 'project_id' => null, 'snapshot_id' => null, 'component' => null, 'rings' => [], 'truncated' => false];
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return ['status' => 'unscanned'] + $envelope;
        }
        $id = (string) $project['id'];
        $envelope = ['project_id' => $id, 'snapshot_id' => $project['active_scan_id']] + $envelope;
        $target = $this->component($id, $component);
        if ($target === null) {
            return ['status' => 'not-found'] + $envelope;
        }
        [$hop, $out, $truncated] = $this->dependents($id, (string) $target['id']);
        $ids = array_map('strval', array_keys($hop));
        $roles = $this->roles($ids);
        $tests = array_flip(array_filter($ids, static fn(string $node): bool => ReportableComponent::isTest($roles[$node] ?? [])));
        $reached = self::reachedByTests(array_keys($tests), $out);
        // Everything some test reaches.
        $covered = $reached === [] ? [] : array_replace(...array_values($reached));
        $labels = BoundaryLabels::load($this->pdo, $id)->forNodes([(string) $target['id'], ...$ids]);
        $places = $this->places($ids);
        $named = fn(string $node): array => ['name' => (string) $places[$node]['display_name'], 'canonical_name' => (string) $places[$node]['canonical_name'], 'kind' => (string) $places[$node]['kind'],
            'path' => $places[$node]['path'], 'line' => $places[$node]['line'], 'boundary' => $labels[$node] ?? null];
        $rings = [];
        foreach ([1, 2, 3] as $ring) {
            $in = static fn(string $node): bool => $ring < 3 ? $hop[$node] === $ring : $hop[$node] >= 3;
            $members = array_values(array_filter($ids, static fn(string $node): bool => $in($node) && !isset($tests[$node])));
            // Built once per ring, not once per test it is checked against.
            $memberSet = array_flip($members);
            $covering = array_values(array_filter(array_keys($tests), static fn(string $test): bool => array_intersect_key($reached[$test], $memberSet) !== []));
            $testedSet = array_intersect_key($covered, $memberSet);
            // The untested first: they are what a change there would break unseen.
            usort($members, static fn(string $a, string $b): int => [isset($testedSet[$a]) ? 1 : 0, $places[$a]['canonical_name']] <=> [isset($testedSet[$b]) ? 1 : 0, $places[$b]['canonical_name']]);
            // A test is its file: the nearest of its components says how far out it stands.
            $files = [];
            foreach ($covering as $test) {
                $file = $places[$test]['path'] ?? $places[$test]['canonical_name'];
                $files[$file] = min($files[$file] ?? PHP_INT_MAX, $hop[$test]);
            }
            uksort($files, static fn(string $a, string $b): int => [$files[$a], $a] <=> [$files[$b], $b]);
            $rings[] = [
                'hop' => $ring,
                'count' => count($members),
                'tested' => count($testedSet),
                'items' => array_map(static fn(string $node): array => $named($node) + ['tested' => isset($testedSet[$node])], array_slice($members, 0, self::NAMED)),
                'tests' => ['count' => count($files), 'items' => array_map(static fn(string $file, int $at): array => ['path' => $file, 'hop' => $at], array_keys(array_slice($files, 0, self::NAMED, true)), array_slice($files, 0, self::NAMED, true))],
            ];
        }

        return [
            'status' => 'ok',
            'component' => ['name' => (string) $target['display_name'], 'canonical_name' => (string) $target['canonical_name'], 'kind' => (string) $target['kind'], 'boundary' => $labels[(string) $target['id']] ?? null],
            'rings' => $rings,
            'truncated' => $truncated,
        ] + $envelope;
    }

    /**
     * Each node's roles, by id.
     *
     * @param list<string> $ids
     * @return array<string, list<string>>
     */
    private function roles(array $ids): array
    {
        $roles = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $statement = $this->pdo->prepare('SELECT node_id, role FROM classifications WHERE node_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $statement->execute($chunk);
            foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$node, $role]) {
                $roles[(string) $node][] = (string) $role;
            }
        }

        return $roles;
    }

    /**
     * The one component `$query` names (its full name, else its short name
     * when only one goes by it), or null.
     *
     * @return array<string, mixed>|null
     */
    private function component(string $projectId, string $query): ?array
    {
        foreach (['canonical_name', 'display_name'] as $column) {
            $statement = $this->pdo->prepare(sprintf('SELECT id, kind, canonical_name, display_name FROM nodes WHERE project_id = :project AND %s = :query LIMIT 2', $column));
            $statement->execute(['project' => $projectId, 'query' => $query]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 1) {
                return $rows[0];
            }
            if ($rows !== []) {
                return null;
            }
        }

        return null;
    }

    /**
     * Everything that depends on `$target`, hop by hop over the impact
     * edges, up to {@see self::DEPTH} hops, {@see self::DEPENDENTS} components
     * and {@see self::TIMEOUT_MS}: each one's hop, the edges read between
     * them (source to its targets), and whether a bound cut the search.
     *
     * @return array{0: array<string, int>, 1: array<string, list<string>>, 2: bool}
     */
    private function dependents(string $projectId, string $target): array
    {
        $deadline = hrtime(true) + self::TIMEOUT_MS * 1_000_000;
        $kinds = AbstractArchitectureQueryService::IMPACT_EDGE_KINDS;
        $hop = [];
        $out = [];
        $frontier = [$target];
        $seen = [$target => true];
        $truncated = false;
        for ($distance = 1; $distance <= self::DEPTH && $frontier !== []; ++$distance) {
            $next = [];
            foreach (array_chunk($frontier, 400) as $chunk) {
                if (hrtime(true) > $deadline) {
                    $truncated = true;
                    break 2;
                }
                $statement = $this->pdo->prepare(
                    'SELECT DISTINCT source_id, target_id FROM edges WHERE project_id = ? AND target_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') AND kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')',
                );
                $statement->execute([$projectId, ...$chunk, ...$kinds]);
                foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$source, $to]) {
                    [$source, $to] = [(string) $source, (string) $to];
                    if ($source === $target) {
                        continue;
                    }
                    if ($to !== $target) {
                        $out[$source][] = $to;
                    }
                    if (isset($seen[$source])) {
                        continue;
                    }
                    if (count($hop) >= self::DEPENDENTS) {
                        $truncated = true;
                        continue;
                    }
                    $seen[$source] = true;
                    $hop[$source] = $distance;
                    $next[] = $source;
                }
            }
            $frontier = $next;
        }
        // Past the last hop there may be more: a frontier left unread is a cut.
        $truncated = $truncated || ($frontier !== [] && $this->hasDependents($projectId, $frontier, $kinds));

        return [$hop, $out, $truncated];
    }

    /**
     * Whether anything depends on one of `$ids`.
     *
     * @param list<string> $ids
     * @param list<string> $kinds
     */
    private function hasDependents(string $projectId, array $ids, array $kinds): bool
    {
        foreach (array_chunk($ids, 400) as $chunk) {
            $statement = $this->pdo->prepare(
                'SELECT 1 FROM edges WHERE project_id = ? AND target_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') AND kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ') LIMIT 1',
            );
            $statement->execute([$projectId, ...$chunk, ...$kinds]);
            if ($statement->fetchColumn() !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * What each test reaches among the components found, by the edges read
     * between them, from the test outwards, by id.
     *
     * @param list<string> $tests
     * @param array<string, list<string>> $out each found component's targets among them
     * @return array<string, array<string, true>>
     */
    private static function reachedByTests(array $tests, array $out): array
    {
        $reached = [];
        foreach ($tests as $test) {
            $seen = [];
            $queue = $out[$test] ?? [];
            while ($queue !== []) {
                $node = array_pop($queue);
                if (isset($seen[$node])) {
                    continue;
                }
                $seen[$node] = true;
                array_push($queue, ...($out[$node] ?? []));
            }
            $reached[$test] = $seen;
        }

        return $reached;
    }

    /**
     * Each node's names, kind and place (file and first line), by id.
     *
     * @param list<string> $ids
     * @return array<string, array{display_name: string, canonical_name: string, kind: string, path: string|null, line: int|null}>
     */
    private function places(array $ids): array
    {
        $places = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $statement = $this->pdo->prepare(
                'SELECT n.id, n.display_name, n.canonical_name, n.kind, f.relative_path, n.start_line FROM nodes n LEFT JOIN files f ON f.id = n.file_id WHERE n.id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
            );
            $statement->execute($chunk);
            foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$node, $display, $canonical, $kind, $file, $line]) {
                $places[(string) $node] = ['display_name' => (string) $display, 'canonical_name' => (string) $canonical, 'kind' => (string) $kind, 'path' => $file === null ? null : (string) $file, 'line' => $line === null ? null : (int) $line];
            }
        }

        return $places;
    }
}
