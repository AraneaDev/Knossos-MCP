<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use PDO;

final readonly class ProjectCatalogQueryService extends AbstractArchitectureQueryService
{
    public function __construct(PDO $pdo, ?Closure $clock, private ArchitecturePolicyQueryService $policyQueries)
    {
        parent::__construct($pdo, $clock);
    }

    public function listProjects(int $limit = 50, int $offset = 0, bool $includeRoots = false): ResultEnvelope
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

    public function snapshotDiff(string $projectId, string $fromSnapshot, string $toSnapshot = 'active', int $maxChanges = 200): ResultEnvelope
    {
        $project = $this->project($projectId);
        if ($maxChanges < 1 || $maxChanges > 1000) {
            throw new InvalidArgumentException('max_changes must be between 1 and 1000.');
        }
        $from = $this->snapshotFacts($projectId, $fromSnapshot, $project['active_scan_id'] ?? '');
        $to = $this->snapshotFacts($projectId, $toSnapshot, $project['active_scan_id'] ?? '');
        if ($from['metadata']['scan_id'] === $to['metadata']['scan_id']) {
            throw new InvalidArgumentException('from_snapshot and to_snapshot must identify different scans.');
        }

        $remaining = $maxChanges;
        $total = 0;
        $truncated = false;
        $sections = [];
        $tableMap = [
            'components' => ['nodes', 'id'],
            'relationships' => ['edges', 'id'],
            'roles' => ['classifications', 'id'],
            'boundaries' => ['boundaries', 'id'],
            'boundary_memberships' => ['boundary_memberships', null],
            'diagnostics' => ['diagnostics', 'id'],
        ];
        $rawDiffs = [];
        $allComponentChanges = [];
        foreach ($tableMap as $section => [$table, $key]) {
            $diff = $this->diffSnapshotRows($from['facts'][$table] ?? [], $to['facts'][$table] ?? [], $key);
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
                    fn(array $change): array => $this->snapshotChangeRecord($table, $kind, $change, $from['facts']['files'] ?? [], $to['facts']['files'] ?? []),
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
                    $moved[] = $this->snapshotChangeRecord('nodes', 'moved', $change, $from['facts']['files'] ?? [], $to['facts']['files'] ?? []);
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
        $rank = ['possible' => 1, 'probable' => 2, 'certain' => 3];
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

    /** @param array<string, mixed> $budgets @param list<array<string, mixed>> $policies */
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
        $baseline = $this->snapshotFacts($projectId, $baselineSnapshot, $project['active_scan_id'] ?? '');
        $current = $this->snapshotFacts($projectId, 'active', $project['active_scan_id'] ?? '');
        if ($baseline['metadata']['scan_id'] === $current['metadata']['scan_id']) {
            throw new InvalidArgumentException('baseline_snapshot must differ from the active snapshot.');
        }
        $before = $this->snapshotQualityMetrics($baseline['facts']);
        $after = $this->snapshotQualityMetrics($current['facts']);
        $actual = [
            'new_cycles' => max(0, $after['cycles'] - $before['cycles']),
            'error_diagnostics' => $after['error_diagnostics'],
            'warning_diagnostics' => $after['warning_diagnostics'],
            'hub_degree_growth' => max(0, $after['max_degree'] - $before['max_degree']),
            'unreferenced_candidates' => $after['unreferenced_candidates'],
            'public_surface_changes' => $this->publicSurfaceChanges($baseline['facts'], $current['facts']),
        ];
        $policyResult = null;
        if ($policies !== []) {
            $policyResult = $this->policyQueries->checkArchitecture($projectId, $policies, limit: 100);
            $actual['boundary_violations'] = count($policyResult->data['violations']);
        }
        $checks = [];
        $passed = true;
        foreach ($budgets as $name => $limit) {
            $value = $actual[$name];
            $checkPassed = $value <= $limit;
            $checks[] = ['metric' => $name, 'actual' => $value, 'limit' => $limit, 'passed' => $checkPassed];
            $passed = $passed && $checkPassed;
        }
        $data = ['passed' => $passed, 'baseline_snapshot' => $baseline['metadata']['scan_id'], 'active_snapshot' => $current['metadata']['scan_id'],
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
            foreach ($current['facts']['diagnostics'] ?? [] as $diagnostic) {
                if (!in_array($diagnostic['severity'], ['warning', 'error'], true)) {
                    continue;
                }
                $results[] = ['ruleId' => 'knossos.' . $diagnostic['code'], 'level' => $diagnostic['severity'],
                    'message' => ['text' => $diagnostic['message']]];
                if (count($results) >= 200) {
                    break;
                }
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

    public function architectureTrends(string $projectId, int $limit = 10, ?string $releaseFrom = null): ResultEnvelope
    {
        $project = $this->project($projectId);
        if ($limit < 2 || $limit > 20) {
            throw new InvalidArgumentException('limit must be between 2 and 20.');
        }
        $listed = $this->listSnapshots($projectId, $limit);
        $series = [];
        foreach (array_reverse($listed->data['snapshots']) as $snapshot) {
            if ($snapshot['retained'] && $snapshot['complete_archive'] === false) {
                $series[] = ['scan_id' => $snapshot['scan_id'], 'captured_at' => $snapshot['captured_at'], 'complete' => false];
                continue;
            }
            $loaded = $this->snapshotFacts($projectId, $snapshot['scan_id'], $project['active_scan_id'] ?? '');
            $metrics = $this->snapshotQualityMetrics($loaded['facts']);
            $series[] = ['scan_id' => $snapshot['scan_id'], 'active' => $snapshot['active'], 'complete' => true,
                'finished_at' => $snapshot['finished_at'], 'scanner_set_hash' => $snapshot['scanner_set_hash'],
                'config_hash' => $snapshot['config_hash'], 'counts' => [
                    'components' => count($loaded['facts']['nodes'] ?? []), 'relationships' => count($loaded['facts']['edges'] ?? []),
                    'roles' => count($loaded['facts']['classifications'] ?? []), 'boundaries' => count($loaded['facts']['boundaries'] ?? []),
                    'diagnostics' => count($loaded['facts']['diagnostics'] ?? []),
                ], 'metrics' => $metrics];
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

    /** @return array{metadata: array<string, mixed>, facts: array<string, list<array<string, mixed>>>} */
    private function snapshotFacts(string $projectId, string $identifier, string $activeScanId): array
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
        if ($scanId !== $activeScanId) {
            if (!is_array($archived)) {
                throw new InvalidArgumentException(sprintf('Snapshot facts are not retained: %s', $scanId));
            }
            if ((int) $archived['complete'] !== 1) {
                throw new InvalidArgumentException(sprintf('Snapshot archive is incomplete: %s', $scanId));
            }
            $payload = json_decode($archived['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $facts = $payload['facts'] ?? null;
            if (!is_array($facts)) {
                throw new InvalidArgumentException(sprintf('Snapshot archive payload is invalid: %s', $scanId));
            }
        } else {
            $facts = [];
            foreach (['files', 'nodes', 'edges', 'classifications', 'boundaries', 'boundary_memberships', 'diagnostics'] as $table) {
                $order = $table === 'boundary_memberships' ? 'boundary_id, node_id' : 'id';
                $statement = $this->pdo->prepare(sprintf('SELECT * FROM %s WHERE project_id = :project ORDER BY %s LIMIT 200001', $table, $order));
                $statement->execute(['project' => $projectId]);
                $rows = $statement->fetchAll();
                if (count($rows) > 200_000) {
                    throw new InvalidArgumentException(sprintf('Active snapshot %s exceeds the 200000-row %s diff limit.', $scanId, $table));
                }
                $facts[$table] = $rows;
            }
        }
        return ['metadata' => [
            'scan_id' => $scanId, 'active' => $scanId === $activeScanId, 'mode' => $metadata['mode'],
            'scanner_set_hash' => $metadata['scanner_set_hash'], 'config_hash' => is_array($archived) ? $archived['config_hash'] : null,
            'started_at' => $metadata['started_at'], 'finished_at' => $metadata['finished_at'],
        ], 'facts' => $facts];
    }
    /**
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
    /** @param list<array<string, mixed>> $beforeFiles @param list<array<string, mixed>> $afterFiles @return array<string, mixed> */
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
    /** @param list<array<string, mixed>> $removed @param list<array<string, mixed>> $added @return list<array<string, mixed>> */
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
    /** @param array<string, list<array<string, mixed>>> $facts @return array<string, int> */
    private function snapshotQualityMetrics(array $facts): array
    {
        $nodes = array_fill_keys(array_column($facts['nodes'] ?? [], 'id'), true);
        $adjacency = $reverse = [];
        $degree = array_fill_keys(array_keys($nodes), 0);
        foreach (array_keys($nodes) as $id) {
            $adjacency[$id] = $reverse[$id] = [];
        }
        foreach ($facts['edges'] ?? [] as $edge) {
            if (!isset($nodes[$edge['source_id']], $nodes[$edge['target_id']]) || !in_array($edge['kind'], self::IMPACT_EDGE_KINDS, true)) {
                continue;
            }
            $adjacency[$edge['source_id']][] = $edge['target_id'];
            $reverse[$edge['target_id']][] = $edge['source_id'];
            ++$degree[$edge['source_id']];
            ++$degree[$edge['target_id']];
        }
        $cycles = 0;
        foreach ($this->stronglyConnectedComponents($adjacency, $reverse)['components'] as $component) {
            if (count($component) > 1 || in_array($component[0], $adjacency[$component[0]], true)) {
                ++$cycles;
            }
        }
        $errors = $warnings = 0;
        foreach ($facts['diagnostics'] ?? [] as $diagnostic) {
            $errors += $diagnostic['severity'] === 'error' ? 1 : 0;
            $warnings += $diagnostic['severity'] === 'warning' ? 1 : 0;
        }
        $entryKinds = ['route', 'command', 'event', 'listener', 'handler'];
        $unreferenced = 0;
        foreach ($facts['nodes'] ?? [] as $node) {
            if (($reverse[$node['id']] ?? []) === [] && !str_starts_with($node['kind'], 'external_') && !in_array($node['kind'], $entryKinds, true)) {
                ++$unreferenced;
            }
        }
        return ['cycles' => $cycles, 'max_degree' => $degree === [] ? 0 : max($degree), 'error_diagnostics' => $errors,
            'warning_diagnostics' => $warnings, 'unreferenced_candidates' => $unreferenced];
    }
    /** @param array<string, list<array<string, mixed>>> $before @param array<string, list<array<string, mixed>>> $after */
    private function publicSurfaceChanges(array $before, array $after): int
    {
        $surface = static function (array $facts): array {
            $ids = [];
            foreach ($facts['nodes'] ?? [] as $node) {
                if (in_array($node['kind'], ['route', 'command', 'endpoint', 'export'], true)) {
                    $ids[$node['id']] = true;
                }
            }
            foreach ($facts['classifications'] ?? [] as $role) {
                if (str_contains($role['role'], 'entry_point') || str_contains($role['role'], 'public')) {
                    $ids[$role['node_id']] = true;
                }
            }
            return $ids;
        };
        $beforeIds = $surface($before);
        $afterIds = $surface($after);
        return count(array_diff_key($beforeIds, $afterIds)) + count(array_diff_key($afterIds, $beforeIds));
    }
}
