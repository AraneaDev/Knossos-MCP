<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use PDO;

/**
 * The CI quality gate, the branch comparison, and the trend across snapshots.
 *
 * All three ask one question of two or more graphs: what moved. Each graph is
 * read one at a time and reduced to its {@see SnapshotMetrics} figures before
 * the next is read, so no answer here holds two graphs at once.
 */
final readonly class QualityGateQueryService extends AbstractArchitectureQueryService
{
    /** The fewest components depending on one for it to count as a hub that grew. */
    private const HUB_MIN = 10;

    private SnapshotResolver $snapshots;
    private SnapshotMetrics $metrics;

    /**
     * @param ArchitecturePolicyQueryService $policyQueries the policy check the gate and the comparison count violations with
     * @param ProjectCatalogQueryService $catalog the snapshot listing a trend walks
     * @param SnapshotDiffQuery $diffs the diff a trend's release notes come from
     */
    public function __construct(
        PDO $pdo,
        ?Closure $clock,
        private ArchitecturePolicyQueryService $policyQueries,
        private ProjectCatalogQueryService $catalog,
        private SnapshotDiffQuery $diffs,
    ) {
        parent::__construct($pdo, $clock);
        $this->snapshots = new SnapshotResolver($pdo);
        $this->metrics = new SnapshotMetrics($pdo, $clock);
    }

    /**
     * The rules a budget set must meet, checked without the database.
     *
     * Public so a caller can refuse a malformed set before doing any work (the
     * MCP layer runs it before refresh_if_stale rescans); qualityGate() checks
     * through this, so the rules exist once.
     *
     * @param array<mixed> $budgets @param array<mixed> $policies
     */
    public static function validateBudgets(array $budgets, array $policies): void
    {
        $allowed = ['new_cycles', 'boundary_violations', 'error_diagnostics', 'warning_diagnostics', 'hub_degree_growth', 'unreferenced_candidates', 'public_surface_changes'];
        if ($budgets === [] || array_diff(array_keys($budgets), $allowed) !== []) {
            throw new InvalidArgumentException('budgets must contain one or more supported quality limits.');
        }
        foreach ($budgets as $name => $limit) {
            if (!is_int($limit) || $limit < 0 || $limit > 100_000) {
                throw new InvalidArgumentException(sprintf('Budget %s must be an integer between 0 and 100000.', $name));
            }
        }
        if (isset($budgets['boundary_violations']) && $policies === []) {
            throw new InvalidArgumentException('policies are required when boundary_violations is budgeted.');
        }
    }

    /**
     * Budget evaluation against a baseline, optionally as SARIF for CI annotation.
     *
     * @param array<string, mixed> $budgets @param list<array<string, mixed>> $policies
     */
    public function qualityGate(string $projectId, string $baselineSnapshot, array $budgets, array $policies = [], bool $sarif = false, bool $proposeBaseline = false): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::validateBudgets($budgets, $policies);
        $activeScan = (string) ($project['active_scan_id'] ?? '');
        $baseline = $this->snapshots->resolve($projectId, $baselineSnapshot, $activeScan);
        $current = $this->snapshots->resolve($projectId, 'active', $activeScan);
        if ($baseline['scan_id'] === $current['scan_id']) {
            throw new InvalidArgumentException('baseline_snapshot must differ from the active snapshot.');
        }
        // One graph at a time, through the reader's column list: the baseline
        // is reduced to what the gate compares and let go before the active
        // graph is read. Reading both whole (`SELECT *` with attributes and
        // owners) and holding them to the end cost about 78 MB on a 20,001-edge
        // graph.
        $before = $this->metrics->gateFigures($this->snapshots->facts($projectId, $baseline));
        unset($baseline['archived']);
        $after = $this->metrics->gateFigures($this->snapshots->facts($projectId, $current), $before['cycle_of']);
        $actual = [
            'new_cycles' => $after['new_cycles'],
            'error_diagnostics' => $after['metrics']['error_diagnostics'],
            'warning_diagnostics' => $after['metrics']['warning_diagnostics'],
            'hub_degree_growth' => max(0, $after['metrics']['max_degree'] - $before['metrics']['max_degree']),
            'unreferenced_candidates' => $after['metrics']['unreferenced_candidates'],
            'public_surface_changes' => count(array_diff_key($before['surface'], $after['surface'])) + count(array_diff_key($after['surface'], $before['surface'])),
        ];
        unset($before, $after);
        $policyResult = null;
        $boundaryIndeterminate = false;
        if ($policies !== []) {
            // Scanned at the largest bounds the checker accepts, in both edges
            // and time. A gate exists to answer pass or fail, and it treats a
            // truncated scan as neither — so at checkArchitecture's own defaults
            // the budget was unpassable on any larger graph, with no argument to
            // raise either bound. Lifting only the edge ceiling would have left
            // the deadline to become the ceiling in its place.
            $policyResult = $this->policyQueries->checkArchitecture($projectId, $policies, limit: 100, maxEdges: ArchitecturePolicyQueryService::DEFAULT_MAX_EDGES, timeoutMs: 5000);
            $policyBounds = $policyResult->data['bounds'] ?? [];
            // Exact count past the collection limit; a budget of >=100 was
            // previously dead because the collected subset capped at 100.
            $actual['boundary_violations'] = $policyBounds['violation_count'] ?? count($policyResult->data['violations']);
            // Edge/time truncation means not every relationship was inspected,
            // so the count is only a lower bound: the gate cannot pass on it.
            $policyReasons = $policyBounds['truncation_reasons'] ?? [];
            $boundaryIndeterminate = in_array('edge_limit', $policyReasons, true) || in_array('time_limit', $policyReasons, true);
        }
        $checks = [];
        $passed = true;
        foreach ($budgets as $name => $limit) {
            $value = $actual[$name];
            $indeterminate = $name === 'boundary_violations' && $boundaryIndeterminate;
            $checkPassed = !$indeterminate && $value <= $limit;
            $check = ['metric' => $name, 'actual' => $value, 'limit' => $limit, 'passed' => $checkPassed];
            if ($indeterminate) {
                $check['indeterminate'] = true;
                $check['indeterminate_reason'] = 'boundary_violation_scan_truncated';
            }
            $checks[] = $check;
            $passed = $passed && $checkPassed;
        }
        $data = ['passed' => $passed, 'baseline_snapshot' => $baseline['scan_id'], 'active_snapshot' => $current['scan_id'],
            'checks' => $checks, 'metrics' => $actual];
        if ($proposeBaseline) {
            $data['proposed_baseline'] = ['budgets' => $actual, 'requires_review' => true, 'applied' => false];
        }
        if ($sarif) {
            $results = [];
            $policyEvidence = $policyResult === null ? [] : $policyResult->evidence;
            foreach ($policyEvidence as $evidence) {
                $results[] = ['ruleId' => 'knossos.boundary', 'level' => 'error', 'message' => ['text' => 'Architecture boundary policy violation.'],
                    'locations' => [['physicalLocation' => ['artifactLocation' => ['uri' => $evidence['path']], 'region' => ['startLine' => $evidence['start_line'] ?? 1]]]]];
            }
            // Read for SARIF alone, and only as many as the 200 results allow.
            $diagnostics = $this->pdo->prepare("SELECT severity, code, message FROM diagnostics WHERE project_id = ? AND severity IN ('error', 'warning') ORDER BY +id LIMIT ?");
            $diagnostics->bindValue(1, $projectId);
            $diagnostics->bindValue(2, max(0, 200 - count($results)), PDO::PARAM_INT);
            $diagnostics->execute();
            while (($diagnostic = $diagnostics->fetch(PDO::FETCH_ASSOC)) !== false) {
                $results[] = ['ruleId' => 'knossos.' . $diagnostic['code'], 'level' => $diagnostic['severity'],
                    'message' => ['text' => $diagnostic['message']]];
            }
            $data['sarif'] = ['$schema' => 'https://json.schemastore.org/sarif-2.1.0.json', 'version' => '2.1.0',
                'runs' => [['tool' => ['driver' => ['name' => 'Knossos', 'informationUri' => 'https://github.com/']], 'results' => $results]]];
        }
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'] ?? '',
            $passed ? 'Architecture quality budgets passed.' : 'Architecture quality budgets failed.',
            $data,
            warnings: ['Baseline proposals are never applied automatically and require review.'],
        );
    }

    /**
     * What a branch added to the architecture since `$baseSnapshot` (the
     * snapshot standing in for where the branch left its default branch), up
     * to the active graph: dependencies newly crossing from one boundary into
     * another, cycles that were not there, components more depended on than
     * they were, newly unreferenced components, and policy violations whose
     * dependency is new. Each list holds the first `$limit` and counts them
     * all.
     *
     * Both graphs are read whole, one after the other and with only the
     * columns the comparison uses ({@see SnapshotGraphReader}), and analysed
     * the way {@see self::qualityGate()} analyses them: the same reportable components, impact
     * edges, cycles and unreferenced candidates. A component is the same in
     * both graphs when its language, kind and full name are, the tuple its id
     * is a hash of; a full name alone is not unique. A dependency is new when no
     * impact edge joined the same two components before;
     * boundaries are the active graph's labels ({@see BoundaryLabels}); a
     * cycle is new unless all its members already formed one cycle; a hub
     * has grown when at least {@see self::HUB_MIN} components depend on it
     * now and more than before.
     *
     * @param list<array<string, mixed>> $policies the project's declared policies (none: no violations are checked)
     * @return array<string, mixed>
     */
    public function branchComparison(string $projectId, string $baseSnapshot, array $policies, int $limit = 8): array
    {
        $project = $this->project($projectId);
        $active = (string) ($project['active_scan_id'] ?? '');
        // The base first, reduced to what the comparison asks of it (keyed by identity) and let go,
        // so the two graphs are never held at once.
        $resolved = $this->snapshots->resolve($projectId, $baseSnapshot, $active);
        $reader = new SnapshotGraphReader($this->pdo);
        $was = $this->metrics->baseFigures($resolved['is_active'] ? $reader->active($projectId, $active) : $reader->archivedById($resolved['scan_id']));
        unset($resolved['archived']);
        $facts = $reader->active($projectId, $active);
        $after = $this->metrics->snapshotAnalysis($facts);
        $now = SnapshotMetrics::identityKeys($facts);
        $nodes = SnapshotMetrics::placedNodes($facts);
        unset($facts);
        $labels = BoundaryLabels::load($this->pdo, $projectId)->forProject($projectId);
        $item = static fn(string $id): array => $nodes[$id] + ['boundary' => $labels[$id] ?? null];

        $crossing = [];
        foreach ($after['adjacency'] as $source => $targets) {
            foreach (array_unique($targets) as $target) {
                $from = $labels[$source] ?? null;
                $to = $labels[$target] ?? null;
                if ($from === null || $to === null || $from === $to || !isset($after['reportable'][$source], $after['reportable'][$target])
                    || isset($was['edges'][$now[$source] . "\0" . $now[$target]])) {
                    continue;
                }
                $crossing[] = ['source' => $item($source), 'target' => $item($target)];
            }
        }
        usort($crossing, static fn(array $a, array $b): int => [$a['source']['boundary'], $a['target']['boundary'], $a['source']['canonical_name'], $a['target']['canonical_name']]
            <=> [$b['source']['boundary'], $b['target']['boundary'], $b['source']['canonical_name'], $b['target']['canonical_name']]);

        $cycles = [];
        foreach (SnapshotMetrics::newCycles($was['cycle_of'], $after['sccs'], $now) as $members) {
            $sorted = $members;
            usort($sorted, static fn(string $a, string $b): int => [$nodes[$a]['canonical_name'], $now[$a]] <=> [$nodes[$b]['canonical_name'], $now[$b]]);
            $cycles[] = ['size' => count($members), 'members' => array_map($item, array_slice($sorted, 0, $limit))];
        }
        usort($cycles, static fn(array $a, array $b): int => [$b['size'], $a['members'][0]['canonical_name']] <=> [$a['size'], $b['members'][0]['canonical_name']]);

        $idOf = array_flip($now);
        $grown = [];
        foreach (SnapshotMetrics::inDegrees($after, $now) as $key => $degree) {
            $previous = $was['in'][$key] ?? 0;
            if ($degree >= self::HUB_MIN && $degree > $previous) {
                $grown[] = ['component' => $item((string) $idOf[$key]), 'before' => $previous, 'after' => $degree];
            }
        }
        usort($grown, static fn(array $a, array $b): int => [$b['after'] - $b['before'], $b['after'], $a['component']['canonical_name']] <=> [$a['after'] - $a['before'], $a['after'], $b['component']['canonical_name']]);

        $dead = array_values(array_filter($after['unreferenced'], static fn(string $id): bool => !isset($was['dead'][$now[$id]])));
        usort($dead, static fn(string $a, string $b): int => [$nodes[$a]['canonical_name'], $now[$a]] <=> [$nodes[$b]['canonical_name'], $now[$b]]);

        $violations = $this->newViolations($projectId, $policies, $was['edges'], $now, $limit);
        $listed = static fn(array $all): array => ['count' => count($all), 'items' => array_slice($all, 0, $limit)];

        return [
            'base' => $resolved['metadata'],
            'crossing' => $listed($crossing),
            'cycles' => $listed($cycles),
            'hubs' => $listed($grown),
            'dead_code' => $listed(array_map($item, $dead)),
            'violations' => $violations,
        ];
    }

    /** How metrics moved across recent snapshots, plus release-note material. */

    public function architectureTrends(string $projectId, int $limit = 10, ?string $releaseFrom = null): ResultEnvelope
    {
        $project = $this->project($projectId);
        if ($limit < 2 || $limit > 20) {
            throw new InvalidArgumentException('limit must be between 2 and 20.');
        }
        $listed = $this->catalog->listSnapshots($projectId, $limit);
        $series = [];
        $cache = new SnapshotMetricsCache($this->pdo);
        foreach (array_reverse($listed->data['snapshots']) as $snapshot) {
            if ($snapshot['retained'] && $snapshot['complete_archive'] === false) {
                $series[] = ['scan_id' => $snapshot['scan_id'], 'captured_at' => $snapshot['captured_at'], 'complete' => false];
                continue;
            }
            // A complete archive never changes, so its figures are computed once (see SnapshotMetricsCache);
            // the active snapshot is the live tables, computed every time.
            $archived = !$snapshot['active'] && $snapshot['complete_archive'] === true;
            $figures = $archived ? $cache->get($snapshot['scan_id'], (string) $snapshot['captured_at'], (int) $snapshot['byte_size']) : null;
            if ($figures === null) {
                $figures = $this->metrics->snapshotFigures($this->snapshots->facts($projectId, $this->snapshots->resolve($projectId, $snapshot['scan_id'], $project['active_scan_id'] ?? '')));
                if ($archived) {
                    $cache->put($snapshot['scan_id'], (string) $snapshot['captured_at'], (int) $snapshot['byte_size'], $figures);
                }
            }
            $series[] = ['scan_id' => $snapshot['scan_id'], 'active' => $snapshot['active'], 'complete' => true,
                'finished_at' => $snapshot['finished_at'], 'scanner_set_hash' => $snapshot['scanner_set_hash'],
                'config_hash' => $snapshot['config_hash'], 'counts' => $figures['counts'], 'metrics' => $figures['metrics']];
        }
        $releaseNotes = null;
        if ($releaseFrom !== null) {
            $diff = $this->diffs->snapshotDiff($projectId, $releaseFrom, 'active', 100);
            $components = $diff->data['changes']['components']['counts'];
            $relationships = $diff->data['changes']['relationships']['counts'];
            $releaseNotes = [
                'from_snapshot' => $diff->data['from']['scan_id'], 'to_snapshot' => $diff->data['to']['scan_id'],
                'markdown' => sprintf(
                    "## Architecture changes\n\n- Components: +%d / -%d / %d changed / %d moved\n- Relationships: +%d / -%d / %d changed\n- Confidence: %d raised / %d lowered\n%s",
                    $components['added'],
                    $components['removed'],
                    $components['changed'],
                    $components['moved'],
                    $relationships['added'],
                    $relationships['removed'],
                    $relationships['changed'],
                    $diff->data['confidence_changes']['raised'],
                    $diff->data['confidence_changes']['lowered'],
                    $diff->truncated ? "- Detail output was truncated by the 100-change release-note bound.\n" : '',
                ),
                'changes' => $diff->data['changes'], 'truncated' => $diff->truncated,
            ];
        }
        return new ResultEnvelope($projectId, $project['active_scan_id'] ?? '', sprintf('Reported architecture trends across %d snapshot%s.', count($series), count($series) === 1 ? '' : 's'), [
            'series' => $series, 'release_notes' => $releaseNotes, 'bounds' => ['limit' => $limit, 'available_truncated' => $listed->truncated],
        ], warnings: ['Trend metrics are bounded static signals and scanner/config fingerprint changes can affect comparability.'], truncated: $listed->truncated || ($releaseNotes['truncated'] ?? false));
    }

    /**
     * The active graph's policy violations whose dependency is new: no impact
     * edge joined the two components before. Null without policies;
     * `truncated` when the check stopped early, so the count is a floor.
     *
     * @param list<array<string, mixed>> $policies
     * @param array<string, true> $edgesBefore the base graph's impact edges as identity-key pairs
     * @param array<string, string> $keysNow the active graph's identity keys by id
     * @return array{count: int, items: list<array<string, mixed>>, truncated: bool}|null
     */
    private function newViolations(string $projectId, array $policies, array $edgesBefore, array $keysNow, int $limit): ?array
    {
        if ($policies === []) {
            return null;
        }
        try {
            $check = $this->policyQueries->checkArchitecture($projectId, $policies, limit: 100, timeoutMs: 5000);
        } catch (InvalidArgumentException) {
            return null;
        }
        $fresh = array_values(array_filter($check->data['violations'], static fn(array $v): bool => !isset($edgesBefore[($keysNow[$v['source']['id']] ?? '') . "\0" . ($keysNow[$v['target']['id']] ?? '')])));
        // The check's own order follows the graph's storage; the list is the same on every read only once sorted.
        usort($fresh, static fn(array $a, array $b): int => [$a['policy_id'], $a['source']['canonical_name'], $a['target']['canonical_name']] <=> [$b['policy_id'], $b['source']['canonical_name'], $b['target']['canonical_name']]);

        return [
            'count' => count($fresh),
            'items' => array_map(static fn(array $v): array => ['policy_id' => (string) $v['policy_id'], 'source' => (string) $v['source']['canonical_name'], 'source_kind' => (string) $v['source']['kind'],
                'target' => (string) $v['target']['canonical_name'], 'target_kind' => (string) $v['target']['kind']], array_slice($fresh, 0, $limit)),
            'truncated' => $check->truncated,
        ];
    }
}
