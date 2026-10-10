<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use Knossos\Query\Drift\DriftCounts;
use Knossos\Scan\ProjectPathResolver;
use PDO;

/**
 * The project-wide picture the architecture pane draws.
 *
 * Read-only: it never scans. Every section is bounded so a large graph
 * cannot make the pane slow to refresh: hubs and hotspots to the top 50, the
 * largest cycles to 3 out of a search that stops at 50 cycles (or at its time
 * and edge limits), the trend to 20 snapshots and the fan-in map to 500 files.
 * A bound never reads as an exact figure: `cycles.truncated` with its
 * `truncation_reasons`, `hubs_truncated` with `hubs_truncation_reasons` (the
 * degree walk stopped at its node, edge or time limit, so hubs and hotspots
 * rank only what it reached), `dead_code_truncated` (the candidate search ran
 * out of time, so the total is a floor), `fan_in_truncated` and
 * `freshness.drifted_truncated` (the drift oracle names only the first 20
 * drifted files) say when a number or list was cut short.
 *
 * Hubs and hotspots are always the top 50, enough to fill a tall pane; that
 * page is the design, not a truncation, so it never sets `hubs_truncated`.
 * Each carries its degrees, how many other files depend on it
 * (`dependent_files`) and the one boundary the pane labels it with (see
 * {@see BoundaryLabels}). The same holds for the other pages: the 10 largest
 * cycles (each listing at most 40 members as `nodes`, `nodes_truncated` when
 * it has more), the first 50 dead-code candidates with their file and line,
 * and the counts and short
 * lists of {@see ProjectFindings} (summary, boundaries, diagnostics, largest
 * files and policy violations), whose own flags say when a figure is a floor,
 * and the {@see BoundaryMatrix} over the boundaries that label components.
 *
 * For the pane's charts: `in_degree` buckets every component the hub ranking
 * could hold by how many depend on it (five buckets, `truncated` with the
 * hubs), each trend point carries dead code, diagnostics and components
 * beside cycles and the largest degree, and `deltas` says how the newest
 * snapshot moved since the one retained before it.
 */
final readonly class DashboardService
{
    /** Hubs, hotspots and dead-code candidates listed: as many as a tall pane shows. */
    private const TOP = 50;
    private const LARGEST_CYCLES = 10;
    /** Members listed per cycle as `nodes`; `members` keeps every name the cycle search returned. */
    private const CYCLE_NODES = 40;
    private const TREND_POINTS = 20;
    /** The files that depend on a listed component or file most, named per entry: what the pane's hover card shows. */
    private const TOP_DEPENDENTS = 3;
    /** Boundaries listed with their member counts. */
    private const BOUNDARIES = 12;

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param int $cycleLimit how many cycles the search returns before it reports truncation
     * @param int $fanInCap how many fan-in entries are returned before truncation is reported
     * @param Closure|null $clock nanosecond clock handed to the query services, so time limits are testable
     * @param int $healthTimeoutMs the degree walk's time budget; the health query's own default of one second
     *                             cut a cold first dashboard of a large project short
     */
    public function __construct(
        private PDO $pdo,
        private int $cycleLimit = 50,
        private int $fanInCap = 500,
        private ?Closure $clock = null,
        private int $healthTimeoutMs = 5000,
    ) {}

    /**
     * The dashboard for the project that owns `$path`, or an all-empty
     * `unscanned` picture when no scanned project contains it.
     *
     * @return array<string, mixed>
     */
    public function dashboard(string $path, int $fanInThreshold = 20): array
    {
        $absolute = realpath($path) ?: $path;
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        // A project row without an active scan has no graph to draw.
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return self::unscanned($absolute);
        }
        $id = (string) $project['id'];
        $root = (string) $project['root_realpath'];
        $queries = ArchitectureQueryService::forDatabase($this->pdo, $this->clock);
        $staleness = (new StalenessProbe($this->pdo))->snapshot($id);
        $probe = $staleness->staleness ?? [];
        $healthResult = $queries->architectureHealth($id, limit: self::TOP, timeoutMs: $this->healthTimeoutMs);
        $health = $healthResult->data;
        $hubLimits = array_values(array_diff($health['bounds']['truncation_reasons'], ['result_limit']));
        // Already ordered largest first by the cycle search.
        $cycleSearch = $queries->dependencyCycles($id, limit: $this->cycleLimit);
        $cycles = $cycleSearch->data['cycles'];
        $fanIn = (new FileFanInQuery($this->pdo))->aboveThreshold($id, $fanInThreshold, $this->fanInCap + 1);
        $series = $queries->architectureTrends($id, self::TREND_POINTS)->data['series'];
        $trend = self::trend($series);
        $labels = BoundaryLabels::load($this->pdo, $id);
        $findings = new ProjectFindings($this->pdo, $this->clock);
        $ranked = [...array_column($health['hubs'], 'component'), ...array_column($health['static_hotspots'], 'component')];
        $places = $this->places($ranked);
        $dependents = $this->dependentFiles($ranked);
        $nearest = $this->topDependents($ranked);
        $fanInListed = array_slice($fanIn, 0, $this->fanInCap);
        $fanInTop = (new FileFanInQuery($this->pdo))->topDependents($id, array_column(array_slice($fanInListed, 0, self::TOP), 'path'), self::TOP_DEPENDENTS);

        return [
            'status' => 'ok',
            'path' => $absolute,
            'project_root' => $root,
            'project_id' => $id,
            'snapshot_id' => $project['active_scan_id'],
            'freshness' => [
                'state' => (string) ($probe['state'] ?? 'missing'),
                'age_seconds' => isset($probe['age_seconds']) ? (int) $probe['age_seconds'] : null,
                'drift_files' => (int) ($probe['changed_files_since'] ?? 0)
                    + (int) ($probe['added_files_since'] ?? 0)
                    + (int) ($probe['deleted_files_since'] ?? 0),
            ] + self::drifted($staleness->drift, $labels, $id),
            'hubs' => array_map(static fn(array $h): array => self::listed($h['component'], $h['metrics'], $labels, $places, $dependents, $nearest), $health['hubs']),
            'hubs_truncated' => $hubLimits !== [],
            'hubs_truncation_reasons' => $hubLimits,
            'hotspots' => array_map(
                static fn(array $h): array => self::listed($h['component'], $h['factors'], $labels, $places, $dependents, $nearest) + ['score' => $h['score']],
                $health['static_hotspots'],
            ),
            // The listed candidates are paged by the health limit, so the
            // total is the honest count; the list length would read as the page.
            'dead_code_candidates' => $health['bounds']['candidates_total'],
            'dead_code_truncated' => in_array('time_limit', $health['bounds']['candidate_truncation_reasons'], true),
            'dead_code' => self::deadCode($health['dead_code_candidates'], $healthResult->evidence, $labels),
            'cycles' => [
                'count' => count($cycles),
                'truncated' => $cycleSearch->truncated,
                'truncation_reasons' => $cycleSearch->data['bounds']['truncation_reasons'],
                'largest' => array_map(static fn(array $c): array => self::cycle($c, $labels), array_slice($cycles, 0, self::LARGEST_CYCLES)),
            ],
            'trend' => $trend,
            'deltas' => self::deltas($trend),
            'in_degree' => ['buckets' => $health['in_degree_histogram'], 'truncated' => $hubLimits !== []],
            'fan_in' => array_map(static fn(array $f): array => isset($fanInTop[$f['path']]) ? $f + ['top_dependents' => $fanInTop[$f['path']]] : $f, $fanInListed),
            'fan_in_truncated' => count($fanIn) > $this->fanInCap,
            'summary' => $findings->summary($id),
            'boundaries' => $labels->listed(self::BOUNDARIES),
            'boundary_matrix' => (new BoundaryMatrix($this->pdo, $this->clock))->build($id, $labels, FileViolationQuery::policies($root, null)),
            'diagnostics' => $findings->diagnostics($id),
            'complexity_hotspots' => $findings->complexityHotspots($id),
            'over_budget' => $findings->overBudget($id, $root),
            'policy' => $findings->policy($id, $root, $labels),
        ];
    }

    /**
     * Which files drifted since the snapshot: `drifted` the first few the
     * drift oracle named, by path, each with how it drifted and its own
     * boundary label (null for a file the graph does not hold yet), and
     * `drifted_truncated` when the oracle counted more than it named or
     * stopped counting additions, so the list is not all of them.
     *
     * @return array{drifted: list<array{path: string, change: string, boundary: string|null}>, drifted_truncated: bool}
     */
    private static function drifted(?DriftCounts $drift, BoundaryLabels $labels, string $projectId): array
    {
        $named = $drift === null ? [] : $drift->paths;
        usort($named, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        $boundaries = $named === [] ? [] : $labels->forFiles($projectId, array_column($named, 'path'));

        return [
            'drifted' => array_map(static fn(array $p): array => $p + ['boundary' => $boundaries[$p['path']] ?? null], $named),
            'drifted_truncated' => $drift !== null && ($drift->additionsTruncated || $drift->total() > count($named)),
        ];
    }

    /**
     * The ids of `$components`, each once, without the empty ones.
     *
     * @param list<array<string, mixed>> $components
     * @return list<string>
     */
    private static function idsOf(array $components): array
    {
        return array_values(array_unique(array_filter(array_map(static fn(array $c): string => (string) ($c['id'] ?? ''), $components), static fn(string $id): bool => $id !== '')));
    }

    /**
     * How many files other than its own reference each component, by id: one
     * indexed count per page of ranked components (at most {@see self::TOP}
     * twice over), so it stays cheap on a large graph. A component nothing
     * outside its own file references is absent, read as zero.
     *
     * @param list<array<string, mixed>> $components
     * @return array<string, int>
     */
    private function dependentFiles(array $components): array
    {
        $ids = self::idsOf($components);
        if ($ids === []) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT e.target_id, COUNT(DISTINCT e.file_id) AS files FROM edges e JOIN nodes n ON n.id = e.target_id ' .
            'WHERE e.file_id IS NOT NULL AND e.file_id IS NOT n.file_id AND e.target_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY e.target_id',
        );
        $statement->execute($ids);
        $counts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['target_id']] = (int) $row['files'];
        }
        return $counts;
    }

    /**
     * Where each component is declared, by id: its file relative to the
     * project root and its first line. A stand-in for something outside the
     * project (`external_*`) is filed under the first file that names it,
     * which is not where it lives, so it has no place.
     *
     * @param list<array<string, mixed>> $components
     * @return array<string, array{path: string, line: int|null}>
     */
    private function places(array $components): array
    {
        $ids = self::idsOf($components);
        if ($ids === []) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT n.id, f.relative_path, n.start_line FROM nodes n JOIN files f ON f.id = n.file_id ' .
            "WHERE n.kind NOT LIKE 'external%' AND n.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
        );
        $statement->execute($ids);
        $places = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $places[(string) $row['id']] = ['path' => (string) $row['relative_path'], 'line' => $row['start_line'] === null ? null : (int) $row['start_line']];
        }
        return $places;
    }

    /**
     * One ranked component as the pane lists it: names, kind, degrees, how
     * many other files depend on it, boundary, and where it is declared
     * (`path` and `line`, null when the graph places it nowhere), so the pane
     * can open its file.
     *
     * @param array<string, mixed> $component
     * @param array<string, mixed> $metrics the degree walk's in, out and cross-boundary degrees
     * @param array<string, array{path: string, line: int|null}> $places
     * @param array<string, int> $dependents files referencing each component, by id
     * @param array<string, list<string>> $nearest the files referencing each component most, by id
     * @return array<string, mixed>
     */
    private static function listed(array $component, array $metrics, BoundaryLabels $labels, array $places = [], array $dependents = [], array $nearest = []): array
    {
        $id = (string) ($component['id'] ?? '');
        $place = $places[$id] ?? null;
        return [
            'name' => $component['display_name'],
            'canonical_name' => $component['canonical_name'],
            'kind' => $component['kind'],
            'boundary' => $labels->of($component['boundaries']),
            'in_degree' => $metrics['in_degree'],
            'out_degree' => $metrics['out_degree'],
            'cross_boundary_degree' => $metrics['cross_boundary_degree'],
            'dependent_files' => $dependents[$id] ?? 0,
            'top_dependents' => $nearest[$id] ?? [],
            'path' => $place['path'] ?? null,
            'line' => $place['line'] ?? null,
        ];
    }

    /**
     * One cycle: its size, every member name the search returned (as before),
     * and the first members as nodes the pane colours by boundary.
     *
     * @param array<string, mixed> $cycle
     * @return array<string, mixed>
     */
    private static function cycle(array $cycle, BoundaryLabels $labels): array
    {
        return [
            'size' => $cycle['size'],
            'members' => array_map(
                static fn(array $m): string => (string) ($m['display_name'] ?? $m['canonical_name'] ?? ''),
                $cycle['members'],
            ),
            'nodes' => array_map(static fn(array $m): array => [
                'name' => (string) ($m['display_name'] ?? $m['canonical_name'] ?? ''),
                'canonical_name' => (string) ($m['canonical_name'] ?? ''),
                'kind' => (string) ($m['kind'] ?? ''),
                'boundary' => $labels->of($m['boundaries'] ?? []),
            ], array_slice($cycle['members'], 0, self::CYCLE_NODES)),
            'nodes_truncated' => $cycle['size'] > min(count($cycle['members']), self::CYCLE_NODES),
        ];
    }

    /**
     * The listed dead-code candidates, each with the file and line it is
     * declared at; unreferenced ones come before those only tests reach.
     *
     * @param list<array<string, mixed>> $candidates the health query's page
     * @param list<array<string, mixed>> $evidence its evidence, by component id
     * @return list<array<string, mixed>>
     */
    private static function deadCode(array $candidates, array $evidence, BoundaryLabels $labels): array
    {
        $places = [];
        foreach ($evidence as $place) {
            if (isset($place['component_id'])) {
                $places[(string) $place['component_id']] ??= $place;
            }
        }

        return array_map(static function (array $candidate) use ($places, $labels): array {
            $component = $candidate['component'];
            $place = $places[(string) $component['id']] ?? null;
            return [
                'name' => $component['display_name'],
                'canonical_name' => $component['canonical_name'],
                'kind' => $component['kind'],
                'boundary' => $labels->of($component['boundaries']),
                'reachability' => $candidate['reachability'],
                'confidence' => $candidate['confidence'],
                'path' => $place === null ? null : (string) $place['path'],
                'line' => isset($place['start_line']) ? (int) $place['start_line'] : null,
            ];
        }, $candidates);
    }

    /**
     * The files that reference each component most, by id, at most
     * {@see self::TOP_DEPENDENTS} each, most edges first (ties by path); the
     * component's own file left out, as {@see self::dependentFiles()} counts
     * them. One windowed query over the same indexed edges as that count.
     *
     * @param list<array<string, mixed>> $components
     * @return array<string, list<string>>
     */
    private function topDependents(array $components): array
    {
        $ids = self::idsOf($components);
        if ($ids === []) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT target_id, relative_path FROM (SELECT e.target_id, f.relative_path, ' .
            'ROW_NUMBER() OVER (PARTITION BY e.target_id ORDER BY COUNT(*) DESC, f.relative_path) AS nth ' .
            'FROM edges e JOIN nodes n ON n.id = e.target_id JOIN files f ON f.id = e.file_id ' .
            'WHERE e.file_id IS NOT NULL AND e.file_id IS NOT n.file_id AND e.target_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ' .
            'GROUP BY e.target_id, e.file_id) WHERE nth <= ? ORDER BY target_id, nth',
        );
        foreach ([...$ids, self::TOP_DEPENDENTS] as $position => $value) {
            $statement->bindValue($position + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
        $top = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $top[(string) $row['target_id']][] = (string) $row['relative_path'];
        }
        return $top;
    }

    /**
     * Oldest first, so a sparkline reads left to right. The series is already
     * ordered that way; snapshots whose archive is incomplete carry no metrics
     * and are left out rather than drawn as zero.
     *
     * Each point carries the figures the pane's health rows draw: cycles, the
     * largest degree, the dead-code candidates nothing references
     * (`dead_code`), the error and warning diagnostics (`diagnostics`), and
     * how many components the snapshot held.
     *
     * @param list<array<string, mixed>> $series
     * @return list<array{snapshot_id: string, cycles: int, max_degree: int, dead_code: int, diagnostics: int, components: int}>
     */
    private static function trend(array $series): array
    {
        $points = [];
        foreach ($series as $entry) {
            if (!isset($entry['metrics'])) {
                continue;
            }
            $metrics = $entry['metrics'];
            $points[] = [
                'snapshot_id' => $entry['scan_id'],
                'cycles' => $metrics['cycles'],
                'max_degree' => $metrics['max_degree'],
                'dead_code' => (int) ($metrics['unreferenced_candidates'] ?? 0),
                'diagnostics' => (int) ($metrics['error_diagnostics'] ?? 0) + (int) ($metrics['warning_diagnostics'] ?? 0),
                'components' => (int) ($entry['counts']['components'] ?? 0),
            ];
        }

        return $points;
    }

    /**
     * How the newest snapshot's figures moved since the one retained before
     * it, each as the newer less the older, and which snapshot that is
     * (`against`); null with fewer than two snapshots to compare. Both come
     * from the same trend figures, so a delta never mixes two ways of
     * counting.
     *
     * @param list<array{snapshot_id: string, cycles: int, max_degree: int, dead_code: int, diagnostics: int, components: int}> $trend
     * @return array{against: string, components: int, cycles: int, max_degree: int, dead_code: int, diagnostics: int}|null
     */
    private static function deltas(array $trend): ?array
    {
        $count = count($trend);
        if ($count < 2) {
            return null;
        }
        [$before, $after] = [$trend[$count - 2], $trend[$count - 1]];
        $moved = ['against' => $before['snapshot_id']];
        foreach (['components', 'cycles', 'max_degree', 'dead_code', 'diagnostics'] as $figure) {
            $moved[$figure] = $after[$figure] - $before[$figure];
        }

        return $moved;
    }

    /**
     * The picture for a path no scanned project contains.
     *
     * @return array<string, mixed>
     */
    private static function unscanned(string $path): array
    {
        return [
            'status' => 'unscanned', 'path' => $path, 'project_root' => null, 'project_id' => null,
            'snapshot_id' => null, 'freshness' => ['state' => 'unscanned', 'age_seconds' => null, 'drift_files' => 0, 'drifted' => [], 'drifted_truncated' => false],
            'hubs' => [], 'hubs_truncated' => false, 'hubs_truncation_reasons' => [], 'hotspots' => [], 'dead_code_candidates' => 0, 'dead_code_truncated' => false,
            'cycles' => ['count' => 0, 'truncated' => false, 'truncation_reasons' => [], 'largest' => []],
            'trend' => [], 'deltas' => null, 'in_degree' => ['buckets' => [], 'truncated' => false], 'fan_in' => [], 'fan_in_truncated' => false, 'dead_code' => [],
            'boundary_matrix' => ['boundaries' => [], 'members' => [], 'labelled' => 0, 'boundaries_truncated' => false, 'cells' => [], 'forbidden' => [], 'flows' => [], 'edges' => 0, 'truncated' => false, 'truncation_reasons' => []],
        ] + ProjectFindings::none();
    }
}
