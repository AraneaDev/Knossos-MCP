<?php

declare(strict_types=1);

namespace Knossos\Query;

use PDO;

/**
 * The project-wide picture the architecture pane draws.
 *
 * Read-only: it never scans. Every list is bounded so a large graph
 * cannot make the pane slow to refresh.
 */
final readonly class DashboardService
{
    private const TOP = 10;
    private const LARGEST_CYCLES = 3;
    private const CYCLE_SCAN_LIMIT = 50;
    private const TREND_POINTS = 20;
    private const FAN_IN_CAP = 500;

    /** @param PDO $pdo an existing, migrated graph database */
    public function __construct(private PDO $pdo) {}

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
        if ($project === null) {
            return self::unscanned($absolute);
        }
        $id = (string) $project['id'];
        $queries = new ArchitectureQueryService($this->pdo);
        $probe = (new StalenessProbe($this->pdo))->probe($id) ?? [];
        $health = $queries->architectureHealth($id, limit: self::TOP)->data;
        // Already ordered largest first by the cycle search.
        $cycles = $queries->dependencyCycles($id, limit: self::CYCLE_SCAN_LIMIT)->data['cycles'];
        $series = $queries->architectureTrends($id, self::TREND_POINTS)->data['series'];

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
            'hubs' => array_map(static fn(array $h): array => [
                'name' => $h['component']['display_name'],
                'kind' => $h['component']['kind'],
                'in_degree' => $h['metrics']['in_degree'],
                'out_degree' => $h['metrics']['out_degree'],
                'cross_boundary_degree' => $h['metrics']['cross_boundary_degree'],
            ], $health['hubs']),
            'hotspots' => array_map(static fn(array $h): array => [
                'name' => $h['component']['display_name'],
                'kind' => $h['component']['kind'],
                'score' => $h['score'],
            ], $health['static_hotspots']),
            // The listed candidates are paged by the health limit, so the
            // total is the honest count; the list length would read as ten.
            'dead_code_candidates' => $health['bounds']['candidates_total'],
            'cycles' => [
                'count' => count($cycles),
                'largest' => array_map(static fn(array $c): array => [
                    'size' => $c['size'],
                    'members' => array_map(
                        static fn(array $m): string => (string) ($m['display_name'] ?? $m['canonical_name'] ?? ''),
                        $c['members'],
                    ),
                ], array_slice($cycles, 0, self::LARGEST_CYCLES)),
            ],
            'trend' => self::trend($series),
            'fan_in' => (new FileFanInQuery($this->pdo))->aboveThreshold($id, $fanInThreshold, self::FAN_IN_CAP),
        ];
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
            'hubs' => [], 'hotspots' => [], 'dead_code_candidates' => 0,
            'cycles' => ['count' => 0, 'largest' => []], 'trend' => [], 'fan_in' => [],
        ];
    }
}
