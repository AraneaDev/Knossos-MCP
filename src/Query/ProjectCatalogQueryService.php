<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use Knossos\Store\SnapshotPayload;
use PDO;

/**
 * The project catalogue and its retained scan history.
 *
 * Answers which projects exist and how fresh each is, diffs two snapshots, and
 * evaluates the CI quality gate. Freshness is reported everywhere because a
 * confident answer from a stale graph is the most misleading thing this system can
 * produce.
 */
final readonly class ProjectCatalogQueryService extends AbstractArchitectureQueryService
{
    public function __construct(PDO $pdo, ?Closure $clock, private ArchitecturePolicyQueryService $policyQueries)
    {
        parent::__construct($pdo, $clock);
    }

    /**
     * Scanned projects with freshness and graph size, so a caller can pick the right project_id.
     *
     * Every parameter is required. `ArchitectureQueryService::listProjects()` is
     * the only caller and always passes all three, so defaults here were a
     * second copy of values that already live on that facade: unreachable, and
     * free to drift out of step with the ones callers actually get. The facade
     * owns them.
     */
    public function listProjects(int $limit, int $offset, bool $includeRoots): ResultEnvelope
    {
        self::assertLimit($limit);
        if ($offset < 0 || $offset > 100_000) {
            throw new InvalidArgumentException('offset must be between 0 and 100000.');
        }
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.name, p.root_realpath, p.active_scan_id, p.created_at, p.updated_at, ' .
            'active.mode AS active_mode, active.status AS active_status, active.started_at AS active_started_at, ' .
            'active.finished_at AS active_finished_at, latest.id AS latest_scan_id, latest.mode AS latest_mode, ' .
            'latest.status AS latest_status, latest.started_at AS latest_started_at, latest.finished_at AS latest_finished_at, ' .
            '(SELECT COUNT(*) FROM files f WHERE f.project_id = p.id) AS file_count, ' .
            '(SELECT COUNT(*) FROM nodes n WHERE n.project_id = p.id) AS node_count, ' .
            '(SELECT COUNT(*) FROM edges e WHERE e.project_id = p.id) AS edge_count, ' .
            '(SELECT COUNT(*) FROM diagnostics d WHERE d.project_id = p.id) AS diagnostic_count ' .
            'FROM projects p LEFT JOIN scans active ON active.id = p.active_scan_id ' .
            'LEFT JOIN scans latest ON latest.id = (SELECT s.id FROM scans s WHERE s.project_id = p.id ' .
            'ORDER BY s.started_at DESC, s.id DESC LIMIT 1) ' .
            'ORDER BY p.updated_at DESC, p.id ASC LIMIT :limit OFFSET :offset',
        );
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $truncated = count($rows) > $limit;
        $projects = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $rootAvailable = is_dir($row['root_realpath']);
            $freshness = match (true) {
                !is_string($row['active_scan_id']) || $row['active_scan_id'] === '' => 'unscanned',
                $row['latest_scan_id'] !== $row['active_scan_id'] && $row['latest_status'] === 'running' => 'scan_in_progress',
                $row['latest_scan_id'] !== $row['active_scan_id'] && $row['latest_status'] === 'failed' => 'latest_scan_failed',
                $row['latest_scan_id'] !== $row['active_scan_id'] && $row['latest_status'] === 'cancelled' => 'latest_scan_cancelled',
                !$rootAvailable => 'root_unavailable',
                default => 'ready',
            };
            $project = [
                'id' => $row['id'],
                'name' => $row['name'],
                'active_snapshot_id' => $row['active_scan_id'],
                'freshness' => $freshness,
                'root_available' => $rootAvailable,
                'active_scan' => $row['active_scan_id'] === null ? null : [
                    'mode' => $row['active_mode'], 'status' => $row['active_status'],
                    'started_at' => $row['active_started_at'], 'finished_at' => $row['active_finished_at'],
                ],
                'latest_scan' => $row['latest_scan_id'] === null ? null : [
                    'id' => $row['latest_scan_id'], 'mode' => $row['latest_mode'], 'status' => $row['latest_status'],
                    'started_at' => $row['latest_started_at'], 'finished_at' => $row['latest_finished_at'],
                ],
                'counts' => [
                    'files' => (int) $row['file_count'], 'nodes' => (int) $row['node_count'],
                    'edges' => (int) $row['edge_count'], 'diagnostics' => (int) $row['diagnostic_count'],
                ],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
            if ($includeRoots) {
                $project['root'] = $row['root_realpath'];
            }
            $projects[] = $project;
        }
        $count = count($projects);

        return new ResultEnvelope(
            'catalog',
            '',
            sprintf('Found %d persisted project%s.', $count, $count === 1 ? '' : 's'),
            [
                'projects' => $projects,
                'roots_included' => $includeRoots,
                'pagination' => [
                    'offset' => $offset,
                    'next_offset' => $truncated ? $offset + $limit : null,
                    'truncation_reason' => $truncated ? 'result_limit' : null,
                ],
            ],
            [],
            [],
            $truncated,
        );
    }

    /** Retained scan history, for choosing a baseline to diff or gate against. */

    public function listSnapshots(string $projectId, int $limit = 20, int $offset = 0): ResultEnvelope
    {
        $project = $this->project($projectId);
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }
        if ($offset < 0 || $offset > 100_000) {
            throw new InvalidArgumentException('offset must be between 0 and 100000.');
        }
        $statement = $this->pdo->prepare(
            'SELECT s.id, s.mode, s.status, s.scanner_set_hash, s.started_at, s.finished_at, ' .
            'ss.config_hash, ss.complete, ss.fact_count, ss.byte_size, ss.captured_at ' .
            'FROM scans s LEFT JOIN scan_snapshots ss ON ss.scan_id = s.id ' .
            'WHERE s.project_id = :project AND (s.id = :active OR ss.scan_id IS NOT NULL) ' .
            'ORDER BY (s.id = :active) DESC, ss.rowid DESC, COALESCE(s.finished_at, s.started_at) DESC, s.id DESC LIMIT :limit OFFSET :offset',
        );
        $statement->bindValue(':project', $projectId);
        $statement->bindValue(':active', $project['active_scan_id'] ?? '');
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $truncated = count($rows) > $limit;
        $snapshots = array_map(static fn(array $row): array => [
            'scan_id' => $row['id'], 'active' => $row['id'] === $project['active_scan_id'],
            'retained' => $row['captured_at'] !== null, 'complete_archive' => $row['complete'] === null ? null : (bool) $row['complete'],
            'mode' => $row['mode'], 'status' => $row['status'], 'scanner_set_hash' => $row['scanner_set_hash'],
            'config_hash' => $row['config_hash'], 'started_at' => $row['started_at'], 'finished_at' => $row['finished_at'],
            'captured_at' => $row['captured_at'], 'fact_count' => $row['fact_count'] === null ? null : (int) $row['fact_count'],
            'byte_size' => $row['byte_size'] === null ? null : (int) $row['byte_size'],
        ], array_slice($rows, 0, $limit));

        return new ResultEnvelope($projectId, $project['active_scan_id'] ?? '', sprintf('Listed %d active or retained snapshot%s.', count($snapshots), count($snapshots) === 1 ? '' : 's'), [
            'snapshots' => $snapshots, 'pagination' => ['limit' => $limit, 'offset' => $offset, 'next_offset' => $truncated ? $offset + $limit : null],
        ], warnings: ['Incomplete archives report metadata but cannot support full fact diffs.'], truncated: $truncated);
    }

    /** Architectural changes between two scans: added, removed, changed, and moved facts. */

    public function snapshotDiff(string $projectId, string $fromSnapshot, string $toSnapshot = 'active', int $maxChanges = 25): ResultEnvelope
    {
        $project = $this->project($projectId);
        if ($maxChanges < 1 || $maxChanges > 1000) {
            throw new InvalidArgumentException('max_changes must be between 1 and 1000.');
        }
        $from = $this->snapshotSource($projectId, $fromSnapshot, $project['active_scan_id'] ?? '');
        $to = $this->snapshotSource($projectId, $toSnapshot, $project['active_scan_id'] ?? '');
        if ($from['metadata']['scan_id'] === $to['metadata']['scan_id']) {
            throw new InvalidArgumentException('from_snapshot and to_snapshot must identify different scans.');
        }
        // Files are the only cross-section fact (path resolution), so load them
        // once; every other table is loaded, diffed, and freed inside the loop.
        $fromFiles = ($from['load'])('files');
        $toFiles = ($to['load'])('files');

        $remaining = $maxChanges;
        $total = 0;
        $truncated = false;
        $sections = [];
        $tableMap = [
            'components' => ['nodes', 'id'],
            'boundaries' => ['boundaries', 'id'],
            'relationships' => ['edges', 'id'],
            'roles' => ['classifications', 'id'],
            'boundary_memberships' => ['boundary_memberships', null],
            'diagnostics' => ['diagnostics', 'id'],
        ];
        $rawDiffs = [];
        $allComponentChanges = [];
        foreach ($tableMap as $section => [$table, $key]) {
            $fromRows = ($from['load'])($table);
            $toRows = ($to['load'])($table);
            $diff = $this->diffSnapshotRows($fromRows, $toRows, $key);
            unset($fromRows, $toRows); // free the raw rows before the next table
            if ($section === 'components') {
                $allComponentChanges = $diff['changed'];
                $diff['changed'] = array_values(array_filter($diff['changed'], static function (array $change): bool {
                    $before = $change['before'];
                    $after = $change['after'];
                    unset($before['file_id'], $after['file_id']);
                    return $before !== $after;
                }));
            }
            $rawDiffs[$section] = $diff;
            $sectionOutput = [];
            foreach (['added', 'removed', 'changed'] as $kind) {
                $count = count($diff[$kind]);
                $total += $count;
                $take = min($remaining, $count);
                $sectionOutput[$kind] = array_map(
                    fn(array $change): array => $this->snapshotChangeRecord($table, $kind, $change, $fromFiles, $toFiles),
                    array_slice($diff[$kind], 0, $take),
                );
                $remaining -= $take;
                $truncated = $truncated || $take < $count;
            }
            $sectionOutput['counts'] = ['added' => count($diff['added']), 'removed' => count($diff['removed']), 'changed' => count($diff['changed'])];
            $sections[$section] = $sectionOutput;
        }

        $moved = [];
        foreach ($allComponentChanges as $change) {
            if (($change['before']['file_id'] ?? null) !== ($change['after']['file_id'] ?? null)) {
                ++$total;
                if ($remaining > 0) {
                    $moved[] = $this->snapshotChangeRecord('nodes', 'moved', $change, $fromFiles, $toFiles);
                    --$remaining;
                } else {
                    $truncated = true;
                }
            }
        }
        $sections['components']['moved'] = $moved;
        $sections['components']['counts']['moved'] = count(array_filter(
            $allComponentChanges,
            static fn(array $change): bool => ($change['before']['file_id'] ?? null) !== ($change['after']['file_id'] ?? null),
        ));

        $renameCandidates = $this->renameCandidates($rawDiffs['components']['removed'], $rawDiffs['components']['added']);
        $renameCount = count($renameCandidates);
        $take = min($remaining, $renameCount);
        $sections['components']['rename_candidates'] = array_slice($renameCandidates, 0, $take);
        $sections['components']['counts']['rename_candidates'] = $renameCount;
        $truncated = $truncated || $take < $renameCount;
        $confidence = ['raised' => 0, 'lowered' => 0];
        $rank = self::CONFIDENCE_RANK;
        foreach (['components', 'relationships', 'roles'] as $section) {
            $confidenceChanges = $section === 'components' ? $allComponentChanges : $rawDiffs[$section]['changed'];
            foreach ($confidenceChanges as $change) {
                $before = $rank[$change['before']['confidence'] ?? ''] ?? null;
                $after = $rank[$change['after']['confidence'] ?? ''] ?? null;
                if ($before !== null && $after !== null && $before !== $after) {
                    ++$confidence[$after > $before ? 'raised' : 'lowered'];
                }
            }
        }

        return new ResultEnvelope(
            $projectId,
            $to['metadata']['scan_id'],
            sprintf('Compared snapshots with %d added, removed, changed, or moved fact%s.', $total, $total === 1 ? '' : 's'),
            ['from' => $from['metadata'], 'to' => $to['metadata'], 'changes' => $sections, 'confidence_changes' => $confidence,
                'bounds' => ['max_changes' => $maxChanges, 'reported_changes' => $maxChanges - $remaining, 'total_changes' => $total]],
            warnings: ['Rename candidates are conservative exact kind/display-name heuristics, not proven identity.'],
            truncated: $truncated,
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
     * both graphs when its language, kind and full name are (ids change with
     * a rescan, and a name alone is not unique). A dependency is new when no
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
        $resolved = $this->resolveSnapshot($projectId, $baseSnapshot, $active);
        $reader = new SnapshotGraphReader($this->pdo);
        $was = $this->baseFigures($resolved['is_active'] ? $reader->active($projectId, $active) : $reader->archived((string) $resolved['archived']['payload_json'], $resolved['scan_id']));
        unset($resolved['archived']);
        $facts = $reader->active($projectId, $active);
        $after = $this->snapshotAnalysis($facts);
        $now = self::identityKeys($facts);
        $nodes = self::placedNodes($facts);
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
        foreach ($after['sccs'] as $members) {
            $old = array_unique(array_map(static fn(string $m): int => $was['cycle_of'][$now[$m]] ?? -1, $members));
            if (count($members) > 1 && (count($old) > 1 || $old[array_key_first($old)] < 0)) {
                $sorted = $members;
                usort($sorted, static fn(string $a, string $b): int => [$nodes[$a]['canonical_name'], $now[$a]] <=> [$nodes[$b]['canonical_name'], $now[$b]]);
                $cycles[] = ['size' => count($members), 'members' => array_map($item, array_slice($sorted, 0, $limit))];
            }
        }
        usort($cycles, static fn(array $a, array $b): int => [$b['size'], $a['members'][0]['canonical_name']] <=> [$a['size'], $b['members'][0]['canonical_name']]);

        $idOf = array_flip($now);
        $grown = [];
        foreach (self::inDegrees($after, $now) as $key => $degree) {
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

    /**
     * What a branch comparison asks of the base graph, by identity key (see
     * {@see self::identityKeys()}): its impact edges (`source\0target`), the cycle each member of one was in,
     * how many reportable components depended on each one, and the
     * unreferenced candidates.
     *
     * @param array<string, list<array<string, mixed>>> $facts
     * @return array{edges: array<string, true>, cycle_of: array<string, int>, in: array<string, int>, dead: array<string, true>}
     */
    private function baseFigures(array $facts): array
    {
        $before = $this->snapshotAnalysis($facts);
        $names = self::identityKeys($facts);
        unset($facts);
        $cycleOf = [];
        foreach ($before['sccs'] as $index => $members) {
            if (count($members) > 1) {
                foreach ($members as $member) {
                    $cycleOf[$names[$member]] = $index;
                }
            }
        }

        return [
            'edges' => self::edgePairs($before['adjacency'], $names),
            'cycle_of' => $cycleOf,
            'in' => self::inDegrees($before, $names),
            'dead' => array_fill_keys(array_map(static fn(string $id): string => $names[$id], $before['unreferenced']), true),
        ];
    }

    /** The fewest components depending on one for it to count as a hub that grew. */
    private const HUB_MIN = 10;

    /**
     * Each node's identity across two graphs, by id: `language\0kind\0canonical_name`.
     *
     * Ids change with a rescan, so two graphs are matched by what a node is.
     * The full name alone is not enough: a module and a package, or a class
     * and a function, can share one, and keying by it merged two components
     * into one in every comparison.
     *
     * @param array<string, list<array<string, mixed>>> $facts
     * @return array<string, string>
     */
    private static function identityKeys(array $facts): array
    {
        $keys = [];
        foreach ($facts['nodes'] ?? [] as $node) {
            $keys[(string) $node['id']] = (string) ($node['language'] ?? '') . "\0" . (string) $node['kind'] . "\0" . (string) $node['canonical_name'];
        }
        return $keys;
    }

    /**
     * Each node as the pane opens it, by id: its shown and full name, kind, file and line.
     *
     * @param array<string, list<array<string, mixed>>> $facts
     * @return array<string, array{name: string, canonical_name: string, kind: string, path: string|null, line: int|null}>
     */
    private static function placedNodes(array $facts): array
    {
        $paths = [];
        foreach ($facts['files'] ?? [] as $file) {
            $paths[(string) $file['id']] = (string) $file['relative_path'];
        }
        $nodes = [];
        foreach ($facts['nodes'] ?? [] as $node) {
            $nodes[(string) $node['id']] = [
                'name' => (string) $node['display_name'], 'canonical_name' => (string) $node['canonical_name'], 'kind' => (string) $node['kind'],
                'path' => $paths[(string) ($node['file_id'] ?? '')] ?? null, 'line' => isset($node['start_line']) ? (int) $node['start_line'] : null,
            ];
        }
        return $nodes;
    }

    /**
     * The impact edges as pairs of identity keys, `source\0target`.
     *
     * @param array<string, list<string>> $adjacency
     * @param array<string, string> $names
     * @return array<string, true>
     */
    private static function edgePairs(array $adjacency, array $names): array
    {
        $pairs = [];
        foreach ($adjacency as $source => $targets) {
            foreach ($targets as $target) {
                $pairs[$names[$source] . "\0" . $names[$target]] = true;
            }
        }
        return $pairs;
    }

    /**
     * How many reportable components depend on each reportable one, by identity key.
     *
     * @param array{reportable: array<string, true>, reverse: array<string, list<string>>} $analysis
     * @param array<string, string> $names
     * @return array<string, int>
     */
    private static function inDegrees(array $analysis, array $names): array
    {
        $degrees = [];
        foreach ($analysis['reverse'] as $target => $sources) {
            if (isset($analysis['reportable'][$target])) {
                $degrees[$names[$target]] = count(array_filter(array_unique($sources), static fn(string $s): bool => isset($analysis['reportable'][$s])));
            }
        }
        return $degrees;
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

    /**
     * Budget evaluation against a baseline, optionally as SARIF for CI annotation.
     *
     * @param array<string, mixed> $budgets @param list<array<string, mixed>> $policies
     */
    public function qualityGate(string $projectId, string $baselineSnapshot, array $budgets, array $policies = [], bool $sarif = false, bool $proposeBaseline = false): ResultEnvelope
    {
        $project = $this->project($projectId);
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
        $activeScan = (string) ($project['active_scan_id'] ?? '');
        $baseline = $this->resolveSnapshot($projectId, $baselineSnapshot, $activeScan);
        $current = $this->resolveSnapshot($projectId, 'active', $activeScan);
        if ($baseline['scan_id'] === $current['scan_id']) {
            throw new InvalidArgumentException('baseline_snapshot must differ from the active snapshot.');
        }
        // One graph at a time, through the reader's column list: the baseline
        // is reduced to what the gate compares and let go before the active
        // graph is read. Reading both whole (`SELECT *` with attributes and
        // owners) and holding them to the end cost about 78 MB on a 20,001-edge
        // graph.
        $before = $this->gateFigures($this->readerFacts($projectId, $baseline));
        unset($baseline['archived']);
        $after = $this->gateFigures($this->readerFacts($projectId, $current));
        $actual = [
            'new_cycles' => max(0, $after['metrics']['cycles'] - $before['metrics']['cycles']),
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

    /** How metrics moved across recent snapshots, plus release-note material. */

    public function architectureTrends(string $projectId, int $limit = 10, ?string $releaseFrom = null): ResultEnvelope
    {
        $project = $this->project($projectId);
        if ($limit < 2 || $limit > 20) {
            throw new InvalidArgumentException('limit must be between 2 and 20.');
        }
        $listed = $this->listSnapshots($projectId, $limit);
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
                $figures = $this->snapshotFigures($this->readerFacts($projectId, $this->resolveSnapshot($projectId, $snapshot['scan_id'], $project['active_scan_id'] ?? '')));
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
            $diff = $this->snapshotDiff($projectId, $releaseFrom, 'active', 100);
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
     * Resolve a snapshot identifier to its scan metadata and (when retained)
     * archive row, validating existence and archive completeness. Fact rows are
     * NOT loaded here so callers can stream table-by-table.
     *
     * @return array{scan_id: string, is_active: bool, archived: array<string, mixed>|null, metadata: array<string, mixed>}
     */
    private function resolveSnapshot(string $projectId, string $identifier, string $activeScanId): array
    {
        $scanId = $identifier === 'active' ? $activeScanId : trim($identifier);
        if ($scanId === '') {
            throw new InvalidArgumentException('The project has no active snapshot.');
        }
        $scan = $this->pdo->prepare('SELECT * FROM scans WHERE id = :scan AND project_id = :project AND status = :status');
        $scan->execute(['scan' => $scanId, 'project' => $projectId, 'status' => 'complete']);
        $metadata = $scan->fetch();
        if (!is_array($metadata)) {
            throw new InvalidArgumentException(sprintf('Unknown complete snapshot: %s', $scanId));
        }
        $archive = $this->pdo->prepare('SELECT * FROM scan_snapshots WHERE scan_id = :scan AND project_id = :project');
        $archive->execute(['scan' => $scanId, 'project' => $projectId]);
        $archived = $archive->fetch();
        $isActive = $scanId === $activeScanId;
        if (!$isActive) {
            if (!is_array($archived)) {
                throw new InvalidArgumentException(sprintf('Snapshot facts are not retained: %s', $scanId));
            }
            if ((int) $archived['complete'] !== 1) {
                throw new InvalidArgumentException(sprintf('Snapshot archive is incomplete: %s', $scanId));
            }
        }
        return [
            'scan_id' => $scanId,
            'is_active' => $isActive,
            'archived' => is_array($archived) ? $archived : null,
            'metadata' => [
                'scan_id' => $scanId, 'active' => $isActive, 'mode' => $metadata['mode'],
                'scanner_set_hash' => $metadata['scanner_set_hash'], 'config_hash' => is_array($archived) ? $archived['config_hash'] : null,
                'started_at' => $metadata['started_at'], 'finished_at' => $metadata['finished_at'],
            ],
        ];
    }

    /**
     * A resolved snapshot's graph through {@see SnapshotGraphReader}: the active tables by column list, or the archive read as it inflates.
     *
     * @param array{scan_id: string, is_active: bool, archived: array<string, mixed>|null} $resolved
     * @return array<string, list<array<string, mixed>>>
     */
    private function readerFacts(string $projectId, array $resolved): array
    {
        $reader = new SnapshotGraphReader($this->pdo);

        return $resolved['is_active']
            ? $reader->active($projectId, $resolved['scan_id'])
            : $reader->archived((string) ($resolved['archived']['payload_json'] ?? ''), $resolved['scan_id']);
    }

    /**
     * What the quality gate compares of one graph: its metrics and its public surface.
     *
     * @param array<string, list<array<string, mixed>>> $facts
     * @return array{metrics: array<string, int>, surface: array<string, true>}
     */
    private function gateFigures(array $facts): array
    {
        return ['metrics' => $this->snapshotQualityMetrics($facts), 'surface' => self::surfaceIds($facts)];
    }

    /**
     * A snapshot exposed as a per-table loader so a diff can load, compare, and
     * free one table at a time instead of materializing two whole graphs at once.
     *
     * @return array{metadata: array<string, mixed>, load: \Closure(string): list<array<string, mixed>>}
     */
    private function snapshotSource(string $projectId, string $identifier, string $activeScanId): array
    {
        $resolved = $this->resolveSnapshot($projectId, $identifier, $activeScanId);
        $scanId = $resolved['scan_id'];
        if (!$resolved['is_active']) {
            // A single JSON blob can only be decoded once; cache it and hand out
            // per-table slices, reusing the one decoded payload.
            $archived = $resolved['archived'];
            $decoded = null;
            $load = static function (string $table) use (&$decoded, $archived, $scanId): array {
                if ($decoded === null) {
                    $payload = json_decode(SnapshotPayload::decode((string) $archived['payload_json']), true, 512, JSON_THROW_ON_ERROR);
                    $facts = $payload['facts'] ?? null;
                    if (!is_array($facts)) {
                        throw new InvalidArgumentException(sprintf('Snapshot archive payload is invalid: %s', $scanId));
                    }
                    $decoded = $facts;
                }
                return $decoded[$table] ?? [];
            };
        } else {
            $load = fn(string $table): array => $this->activeSnapshotRows($projectId, $scanId, $table);
        }
        return ['metadata' => $resolved['metadata'], 'load' => $load];
    }

    /**
     * Rows of the current active graph, in the shape snapshot comparison expects.
     *
     * @return list<array<string, mixed>>
     */
    private function activeSnapshotRows(string $projectId, string $scanId, string $table): array
    {
        // Each column with `+`: ordered by an index, SQLite would walk every project's rows rather than find this one's and sort them.
        $order = $table === 'boundary_memberships' ? '+boundary_id, +node_id' : '+id';
        $statement = $this->pdo->prepare(sprintf('SELECT * FROM %s WHERE project_id = :project ORDER BY %s LIMIT 200001', $table, $order));
        $statement->execute(['project' => $projectId]);
        $rows = $statement->fetchAll();
        if (count($rows) > 200_000) {
            throw new InvalidArgumentException(sprintf('Active snapshot %s exceeds the 200000-row %s diff limit.', $scanId, $table));
        }
        return $rows;
    }
    /**
     * Compare two fact sets into added, removed, and changed records.
     *
     * @param list<array<string, mixed>> $beforeRows
     * @param list<array<string, mixed>> $afterRows
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}
     */
    private function diffSnapshotRows(array $beforeRows, array $afterRows, ?string $key): array
    {
        $index = static function (array $rows) use ($key): array {
            $indexed = [];
            foreach ($rows as $row) {
                $id = $key === null ? (string) $row['boundary_id'] . "\0" . (string) $row['node_id'] : (string) $row[$key];
                unset($row['last_scan_id'], $row['scan_id']);
                ksort($row, SORT_STRING);
                $indexed[$id] = $row;
            }
            ksort($indexed, SORT_STRING);
            return $indexed;
        };
        $before = $index($beforeRows);
        $after = $index($afterRows);
        $added = [];
        $removed = [];
        $changed = [];
        foreach (array_diff_key($after, $before) as $id => $row) {
            $added[] = ['id' => $id, 'after' => $row];
        }
        foreach (array_diff_key($before, $after) as $id => $row) {
            $removed[] = ['id' => $id, 'before' => $row];
        }
        foreach (array_intersect_key($before, $after) as $id => $row) {
            if ($row !== $after[$id]) {
                $changed[] = ['id' => $id, 'before' => $row, 'after' => $after[$id]];
            }
        }
        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }
    /**
     * One change entry, carrying enough identity to be actionable rather than just a count.
     *
     * @param list<array<string, mixed>> $beforeFiles @param list<array<string, mixed>> $afterFiles @return array<string, mixed>
     */
    private function snapshotChangeRecord(string $table, string $kind, array $change, array $beforeFiles, array $afterFiles): array
    {
        $summarize = function (?array $row, array $files) use ($table): ?array {
            if ($row === null) {
                return null;
            }
            $fields = match ($table) {
                'nodes' => ['id', 'kind', 'canonical_name', 'display_name', 'file_id', 'start_line', 'end_line', 'origin', 'confidence'],
                'edges' => ['id', 'kind', 'source_id', 'target_id', 'file_id', 'origin', 'confidence'],
                'classifications' => ['id', 'node_id', 'role', 'origin', 'confidence', 'rule_id'],
                'boundaries' => ['id', 'name', 'source', 'matcher_json'],
                'boundary_memberships' => ['boundary_id', 'node_id'],
                'diagnostics' => ['id', 'severity', 'code', 'message', 'file_id', 'start_line', 'end_line'],
                default => array_keys($row),
            };
            $summary = array_intersect_key($row, array_fill_keys($fields, true));
            if (isset($summary['file_id'])) {
                $paths = [];
                foreach ($files as $file) {
                    $paths[$file['id']] = $file['relative_path'];
                }
                $summary['path'] = $paths[$summary['file_id']] ?? null;
                unset($summary['file_id']);
            }
            return $summary;
        };
        $before = $summarize($change['before'] ?? null, $beforeFiles);
        $after = $summarize($change['after'] ?? null, $afterFiles);
        $record = ['id' => str_replace("\0", ':', (string) $change['id'])];
        if ($before !== null) {
            $record['before'] = $before;
        }
        if ($after !== null) {
            $record['after'] = $after;
        }
        if ($kind === 'changed' || $kind === 'moved') {
            $record['changed_fields'] = array_values(array_unique(array_merge(
                array_keys(array_diff_assoc($before ?? [], $after ?? [])),
                array_keys(array_diff_assoc($after ?? [], $before ?? [])),
            )));
            sort($record['changed_fields'], SORT_STRING);
        }
        return $record;
    }
    /**
     * Pairs that look like a rename rather than a delete plus an add, so a move is not double-counted.
     *
     * @param list<array<string, mixed>> $removed @param list<array<string, mixed>> $added @return list<array<string, mixed>>
     */
    private function renameCandidates(array $removed, array $added): array
    {
        $addedBySignature = [];
        foreach ($added as $change) {
            $row = $change['after'];
            $addedBySignature[$row['kind'] . "\0" . $row['display_name']][] = $row;
        }
        $candidates = [];
        foreach ($removed as $change) {
            $before = $change['before'];
            $matches = $addedBySignature[$before['kind'] . "\0" . $before['display_name']] ?? [];
            if (count($matches) === 1) {
                $candidates[] = ['from_id' => $before['id'], 'to_id' => $matches[0]['id'], 'kind' => $before['kind'],
                    'display_name' => $before['display_name'], 'heuristic' => 'exact_kind_and_display_name', 'confidence' => 'possible'];
            }
        }
        usort($candidates, static fn(array $left, array $right): int => [$left['from_id'], $left['to_id']] <=> [$right['from_id'], $right['to_id']]);
        return $candidates;
    }
    /**
     * The roles each of the snapshot's nodes carries, keyed by node id.
     *
     * @param array<string, list<array<string, mixed>>> $facts @return array<string, list<string>>
     */
    private function snapshotRoles(array $facts): array
    {
        $roles = [];
        foreach ($facts['classifications'] ?? [] as $classification) {
            $roles[$classification['node_id']][] = (string) $classification['role'];
        }

        return $roles;
    }

    /** Relationships by which a type takes on another type's members. */
    private const CONTRACT_EDGE_KINDS = ['implements', 'extends', 'uses_trait'];

    /**
     * Whether a method is reached through a contract its type carries.
     *
     * A call to an interface method lands on the interface's declaration, so
     * every implementation of it has an in-degree of zero however heavily the
     * interface is used. `architecture_health` discounts these; counting them
     * here charged the budget for every implementation of every interface,
     * which no maintainer could pay down without deleting the contract.
     *
     * Gated on the declaring type being used for something other than being
     * implemented, exactly as health gates it: when nothing else references
     * the interface, the interface is the unit worth deleting and its members
     * stay reportable.
     *
     * @param array<string, mixed> $node
     * @param array<string, string> $declaringType
     * @param array<string, list<string>> $contracts
     * @param array<string, list<string>> $members
     * @param array<string, string> $displayNames
     * @param array<string, list<string>> $reverse
     * @param array<string, int> $inheritanceInDegree
     */
    private function isContractMemberOfUsedType(
        array $node,
        array $declaringType,
        array $contracts,
        array $members,
        array $displayNames,
        array $reverse,
        array $inheritanceInDegree,
    ): bool {
        if ($node['kind'] !== 'method') {
            return false;
        }
        $owner = $declaringType[$node['id']] ?? null;
        if ($owner === null) {
            return false;
        }
        $name = (string) $node['display_name'];
        foreach ($contracts[$owner] ?? [] as $contract) {
            $declares = false;
            foreach ($members[$contract] ?? [] as $member) {
                if (($displayNames[$member] ?? null) === $name) {
                    $declares = true;
                    break;
                }
            }
            if (!$declares) {
                continue;
            }
            $uses = count($reverse[$contract] ?? []) - ($inheritanceInDegree[$contract] ?? 0);
            if ($uses > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * A snapshot's fact counts and quality metrics, as a trend reports them.
     *
     * @param array<string, list<array<string, mixed>>> $facts
     * @return array{counts: array<string, int>, metrics: array<string, int>}
     */
    private function snapshotFigures(array $facts): array
    {
        return [
            'counts' => [
                'components' => count($facts['nodes'] ?? []), 'relationships' => count($facts['edges'] ?? []),
                'roles' => count($facts['classifications'] ?? []), 'boundaries' => count($facts['boundaries'] ?? []),
                'diagnostics' => count($facts['diagnostics'] ?? []),
            ],
            'metrics' => $this->snapshotQualityMetrics($facts),
        ];
    }

    /**
     * The metrics the quality gate compares: cycles, violations, diagnostics, hub degree.
     *
     * @param array<string, list<array<string, mixed>>> $facts @return array<string, int>
     */
    private function snapshotQualityMetrics(array $facts): array
    {
        $analysis = $this->snapshotAnalysis($facts);
        $cycles = count(array_filter($analysis['sccs'], static fn(array $component): bool => count($component) > 1));
        // Hub size is likewise a statement about the architecture, so a test-only
        // hub must not move it: otherwise every commit that adds tests spends
        // hub_degree_growth budget it has no way to reclaim.
        $reportableDegrees = array_intersect_key($analysis['degree'], $analysis['reportable']);
        return ['cycles' => $cycles, 'max_degree' => $reportableDegrees === [] ? 0 : max($reportableDegrees), 'error_diagnostics' => $analysis['errors'],
            'warning_diagnostics' => $analysis['warnings'], 'unreferenced_candidates' => count($analysis['unreferenced'])];
    }

    /**
     * One snapshot's graph read for the gate and the branch comparison: the
     * reportable components, each one's degree among them and its impact
     * edges both ways, the strongly connected components, the diagnostics by
     * severity, and the unreferenced candidates the gate counts.
     *
     * @param array<string, list<array<string, mixed>>> $facts
     * @return array{reportable: array<string, true>, degree: array<string, int>, adjacency: array<string, list<string>>, reverse: array<string, list<string>>, sccs: list<list<string>>, errors: int, warnings: int, unreferenced: list<string>}
     */
    private function snapshotAnalysis(array $facts): array
    {
        $nodes = array_fill_keys(array_column($facts['nodes'] ?? [], 'id'), true);
        $roles = $this->snapshotRoles($facts);
        // Hub scope: vendor code and test code are not this architecture.
        $reportable = [];
        foreach ($facts['nodes'] ?? [] as $node) {
            if (!ReportableComponent::isExternal((string) $node['kind'], $node['origin'] ?? null)
                && !ReportableComponent::isTest($roles[$node['id']] ?? [])) {
                $reportable[$node['id']] = true;
            }
        }
        $adjacency = $reverse = [];
        $degree = array_fill_keys(array_keys($nodes), 0);
        foreach (array_keys($nodes) as $id) {
            $adjacency[$id] = $reverse[$id] = [];
        }
        // `contains` is not an impact relationship, so it never contributes a
        // degree, but it is what says which type a method belongs to — needed
        // below to tell a contract member from an orphan.
        $members = $contracts = $inheritanceInDegree = [];
        foreach ($facts['edges'] ?? [] as $edge) {
            if (!isset($nodes[$edge['source_id']], $nodes[$edge['target_id']])) {
                continue;
            }
            if ($edge['kind'] === 'contains') {
                $members[$edge['source_id']][] = $edge['target_id'];
            }
            if (in_array($edge['kind'], self::CONTRACT_EDGE_KINDS, true)) {
                $contracts[$edge['source_id']][] = $edge['target_id'];
                $inheritanceInDegree[$edge['target_id']] = ($inheritanceInDegree[$edge['target_id']] ?? 0) + 1;
            }
            if (!in_array($edge['kind'], self::IMPACT_EDGE_KINDS, true)) {
                continue;
            }
            $adjacency[$edge['source_id']][] = $edge['target_id'];
            $reverse[$edge['target_id']][] = $edge['source_id'];
            // Only a relationship between two reportable components is part of
            // the architecture this degree describes. Excluding test and vendor
            // components from BEING hubs was not enough on its own: a test
            // referencing a production hub still raised that hub's degree, so a
            // commit that only added tests spent hub_degree_growth it had no way
            // to reclaim, which is the failure the scope comment above exists to
            // prevent. Found by running this gate against Knossos itself, where
            // adding twenty-five test files moved the budget by 57.
            //
            // Reachability is deliberately left alone: $adjacency and $reverse
            // still record the edge, so a component a test references stays
            // referenced rather than becoming an unreferenced candidate.
            if (isset($reportable[$edge['source_id']], $reportable[$edge['target_id']])) {
                ++$degree[$edge['source_id']];
                ++$degree[$edge['target_id']];
            }
        }
        $declaringType = [];
        foreach ($members as $type => $held) {
            foreach ($held as $member) {
                $declaringType[$member] = $type;
            }
        }
        $displayNames = [];
        foreach ($facts['nodes'] ?? [] as $node) {
            $displayNames[$node['id']] = (string) $node['display_name'];
        }
        // Self-loops are ordinary recursion, not architectural cycles;
        // dependency_cycles excludes them by default, so mirror that here.
        $sccs = $this->stronglyConnectedComponents($adjacency, $reverse)['components'];
        $errors = $warnings = 0;
        foreach ($facts['diagnostics'] ?? [] as $diagnostic) {
            $errors += $diagnostic['severity'] === 'error' ? 1 : 0;
            $warnings += $diagnostic['severity'] === 'warning' ? 1 : 0;
        }
        // Count only the declaration kinds architecture_health treats as
        // dead-code candidates, and only the components it reports on at all —
        // see ReportableComponent for why counting the rest made this budget
        // unusable rather than merely imprecise. Health layers further,
        // database-backed exclusions on top (inherited and contract members,
        // annotations, suppressions), so among components with NO inbound edge
        // this count is the larger of the two by design; what it may not do is
        // count a component health drops for a reason this loop can see for
        // itself.
        //
        // Health also reports a class this budget deliberately does not charge
        // for: a component reached only from test code is `test_only` there and
        // referenced here. It is worth deleting, but it is not a regression the
        // way a newly orphaned component is, and a budget that moved when a
        // caller was replaced by a test would punish the wrong change.
        $candidateKinds = ['class', 'interface', 'trait', 'enum', 'function', 'method', 'module'];
        $unreferenced = [];
        foreach ($facts['nodes'] ?? [] as $node) {
            if (($reverse[$node['id']] ?? []) !== [] || !isset($reportable[$node['id']])) {
                continue;
            }
            if (!in_array($node['kind'], $candidateKinds, true)) {
                continue;
            }
            // A runtime-invoked lifecycle method, a method fulfilling a
            // supertype's member, an executable script's module, a type
            // declaration, or a convention-discovered component has no inbound edge by
            // construction, so counting it would charge the budget for
            // something no maintainer can act on.
            if (ReportableComponent::isRuntimeLifecycleMethod((string) $node['kind'], (string) $node['display_name'])
                || ReportableComponent::isRuntimeInvoked($node['attributes_json'] ?? null)
                || ReportableComponent::isDeclaredOverride((string) $node['kind'], $node['attributes_json'] ?? null)
                || ReportableComponent::isExecutableScript((string) $node['kind'], $node['attributes_json'] ?? null)
                || ReportableComponent::isTypeDeclaration($node['attributes_json'] ?? null)
                || ReportableComponent::isDiscoveredByConvention($roles[$node['id']] ?? [])) {
                continue;
            }
            if ($this->isContractMemberOfUsedType($node, $declaringType, $contracts, $members, $displayNames, $reverse, $inheritanceInDegree)) {
                continue;
            }
            $unreferenced[] = (string) $node['id'];
        }
        return ['reportable' => $reportable, 'degree' => $degree, 'adjacency' => $adjacency, 'reverse' => $reverse, 'sccs' => $sccs,
            'errors' => $errors, 'warnings' => $warnings, 'unreferenced' => $unreferenced];
    }
    /**
     * The ids of a graph's public API surface, the components whose addition or removal is most likely to break a consumer.
     *
     * @param array<string, list<array<string, mixed>>> $facts
     * @return array<string, true>
     */
    private static function surfaceIds(array $facts): array
    {
        $ids = [];
        foreach ($facts['nodes'] ?? [] as $node) {
            if (in_array($node['kind'], ['route', 'command', 'endpoint', 'export'], true)) {
                $ids[(string) $node['id']] = true;
            }
        }
        foreach ($facts['classifications'] ?? [] as $role) {
            if (str_contains($role['role'], 'entry_point') || str_contains($role['role'], 'public')) {
                $ids[(string) $role['node_id']] = true;
            }
        }

        return $ids;
    }
}
