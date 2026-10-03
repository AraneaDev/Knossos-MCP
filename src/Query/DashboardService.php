<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use PDO;

/**
 * The project-wide picture the architecture pane draws.
 *
 * Read-only: it never scans. Every section is bounded so a large graph
 * cannot make the pane slow to refresh: hubs and hotspots to the top 10, the
 * largest cycles to 3 out of a search that stops at 50 cycles (or at its time
 * and edge limits), the trend to 20 snapshots and the fan-in map to 500 files.
 * A bound never reads as an exact figure: `cycles.truncated` with its
 * `truncation_reasons`, `hubs_truncated` with `hubs_truncation_reasons` (the
 * degree walk stopped at its node, edge or time limit, so hubs and hotspots
 * rank only what it reached), `dead_code_truncated` (the candidate search ran
 * out of time, so the total is a floor) and `fan_in_truncated` say when a
 * number or list was cut short.
 *
 * Hubs and hotspots are always the top 10; that page is the design, not a
 * truncation, so it never sets `hubs_truncated`. Each carries its degrees
 * and the one boundary the pane labels it with (see {@see self::boundaryOf()}).
 */
final readonly class DashboardService
{
    private const TOP = 10;
    private const LARGEST_CYCLES = 3;
    private const TREND_POINTS = 20;

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
        $queries = new ArchitectureQueryService($this->pdo, $this->clock);
        $probe = (new StalenessProbe($this->pdo))->probe($id) ?? [];
        $health = $queries->architectureHealth($id, limit: self::TOP, timeoutMs: $this->healthTimeoutMs)->data;
        $hubLimits = array_values(array_diff($health['bounds']['truncation_reasons'], ['result_limit']));
        // Already ordered largest first by the cycle search.
        $cycleSearch = $queries->dependencyCycles($id, limit: $this->cycleLimit);
        $cycles = $cycleSearch->data['cycles'];
        $fanIn = (new FileFanInQuery($this->pdo))->aboveThreshold($id, $fanInThreshold, $this->fanInCap + 1);
        $series = $queries->architectureTrends($id, self::TREND_POINTS)->data['series'];
        $specificity = $this->boundarySpecificity($id);

        return [
            'status' => 'ok',
            'path' => $absolute,
            'project_root' => (string) $project['root_realpath'],
            'project_id' => $id,
            'snapshot_id' => $project['active_scan_id'],
            'freshness' => [
                'state' => (string) ($probe['state'] ?? 'missing'),
                'age_seconds' => isset($probe['age_seconds']) ? (int) $probe['age_seconds'] : null,
                'drift_files' => (int) ($probe['changed_files_since'] ?? 0)
                    + (int) ($probe['added_files_since'] ?? 0)
                    + (int) ($probe['deleted_files_since'] ?? 0),
            ],
            'hubs' => array_map(static fn(array $h): array => self::listed($h['component'], $h['metrics'], $specificity), $health['hubs']),
            'hubs_truncated' => $hubLimits !== [],
            'hubs_truncation_reasons' => $hubLimits,
            'hotspots' => array_map(
                static fn(array $h): array => self::listed($h['component'], $h['factors'], $specificity) + ['score' => $h['score']],
                $health['static_hotspots'],
            ),
            // The listed candidates are paged by the health limit, so the
            // total is the honest count; the list length would read as ten.
            'dead_code_candidates' => $health['bounds']['candidates_total'],
            'dead_code_truncated' => in_array('time_limit', $health['bounds']['candidate_truncation_reasons'], true),
            'cycles' => [
                'count' => count($cycles),
                'truncated' => $cycleSearch->truncated,
                'truncation_reasons' => $cycleSearch->data['bounds']['truncation_reasons'],
                'largest' => array_map(static fn(array $c): array => [
                    'size' => $c['size'],
                    'members' => array_map(
                        static fn(array $m): string => (string) ($m['display_name'] ?? $m['canonical_name'] ?? ''),
                        $c['members'],
                    ),
                ], array_slice($cycles, 0, self::LARGEST_CYCLES)),
            ],
            'trend' => self::trend($series),
            'fan_in' => array_slice($fanIn, 0, $this->fanInCap),
            'fan_in_truncated' => count($fanIn) > $this->fanInCap,
        ];
    }

    /**
     * One ranked component as the pane lists it: names, kind, degrees and boundary.
     *
     * @param array<string, mixed> $component
     * @param array<string, mixed> $metrics the degree walk's in, out and cross-boundary degrees
     * @param array<string, array{int, int, int, string}> $specificity
     * @return array<string, mixed>
     */
    private static function listed(array $component, array $metrics, array $specificity): array
    {
        return [
            'name' => $component['display_name'],
            'canonical_name' => $component['canonical_name'],
            'kind' => $component['kind'],
            'boundary' => self::boundaryOf($component['boundaries'], $specificity),
            'in_degree' => $metrics['in_degree'],
            'out_degree' => $metrics['out_degree'],
            'cross_boundary_degree' => $metrics['cross_boundary_degree'],
        ];
    }

    /**
     * The one boundary a component is labelled with, or null when it has none.
     *
     * A component usually sits in several: a declared one, a package, a
     * namespace and the repository-wide package of a single-package project.
     * The label is the most telling of them: declared before inferred, then
     * anything before a boundary spanning the whole repository, then the
     * fewest members, then the name, so the choice is stable between loads.
     *
     * @param list<array<string, mixed>> $boundaries the component's memberships, `id` and `name` each
     * @param array<string, array{int, int, int, string}> $specificity
     */
    private static function boundaryOf(array $boundaries, array $specificity): ?string
    {
        $best = null;
        foreach ($boundaries as $boundary) {
            $rank = $specificity[$boundary['id']] ?? [2, 2, PHP_INT_MAX, (string) $boundary['name']];
            if ($best === null || $rank < $best) {
                $best = $rank;
            }
        }

        return $best === null ? null : $best[3];
    }

    /**
     * Each of the project's boundaries ranked for {@see self::boundaryOf()}:
     * declared first, not repository-wide first, fewest members, then name.
     * One query over the project's boundaries, which number in the tens.
     *
     * @return array<string, array{int, int, int, string}>
     */
    private function boundarySpecificity(string $projectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id, b.name, b.source, b.matcher_json, COUNT(bm.node_id) AS members FROM boundaries b '
            . 'LEFT JOIN boundary_memberships bm ON bm.boundary_id = b.id WHERE b.project_id = :project GROUP BY b.id',
        );
        $statement->execute(['project' => $projectId]);
        $ranks = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $matcher = json_decode((string) $row['matcher_json'], true);
            $wide = is_array($matcher) && ($matcher['type'] ?? null) === 'path_prefix' && ($matcher['value'] ?? null) === '';
            $ranks[(string) $row['id']] = [$row['source'] === 'explicit' ? 0 : 1, $wide ? 1 : 0, (int) $row['members'], (string) $row['name']];
        }

        return $ranks;
    }

    /**
     * Oldest first, so a sparkline reads left to right. The series is already
     * ordered that way; snapshots whose archive is incomplete carry no metrics
     * and are left out rather than drawn as zero.
     *
     * @param list<array<string, mixed>> $series
     * @return list<array{snapshot_id: string, cycles: int, max_degree: int}>
     */
    private static function trend(array $series): array
    {
        $points = [];
        foreach ($series as $entry) {
            if (!isset($entry['metrics'])) {
                continue;
            }
            $points[] = [
                'snapshot_id' => $entry['scan_id'],
                'cycles' => $entry['metrics']['cycles'],
                'max_degree' => $entry['metrics']['max_degree'],
            ];
        }

        return $points;
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
            'snapshot_id' => null, 'freshness' => ['state' => 'unscanned', 'age_seconds' => null, 'drift_files' => 0],
            'hubs' => [], 'hubs_truncated' => false, 'hubs_truncation_reasons' => [], 'hotspots' => [], 'dead_code_candidates' => 0, 'dead_code_truncated' => false,
            'cycles' => ['count' => 0, 'truncated' => false, 'truncation_reasons' => [], 'largest' => []],
            'trend' => [], 'fan_in' => [], 'fan_in_truncated' => false,
        ];
    }
}
