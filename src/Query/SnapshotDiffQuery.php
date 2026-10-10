<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use PDO;

/**
 * Architectural changes between two snapshots: `snapshot_diff`.
 *
 * Compares two graphs one table at a time, each loaded, diffed and freed
 * before the next, so a diff never holds two whole graphs at once. Release
 * notes in `architecture_trends` are built from the same diff, so the two
 * cannot count a change differently.
 */
final readonly class SnapshotDiffQuery extends AbstractArchitectureQueryService
{
    private SnapshotResolver $snapshots;

    public function __construct(PDO $pdo, ?Closure $clock = null)
    {
        parent::__construct($pdo, $clock);
        $this->snapshots = new SnapshotResolver($pdo);
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
                // A component in another file is a move, reported under
                // `moved` with every field that changed; listing it under
                // `changed` too counted one component twice.
                $diff['changed'] = array_values(array_filter(
                    $diff['changed'],
                    static fn(array $change): bool => ($change['before']['file_id'] ?? null) === ($change['after']['file_id'] ?? null),
                ));
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
     * A snapshot exposed as a per-table loader so a diff can load, compare, and
     * free one table at a time instead of materializing two whole graphs at once.
     *
     * @return array{metadata: array<string, mixed>, load: \Closure(string): list<array<string, mixed>>}
     */
    private function snapshotSource(string $projectId, string $identifier, string $activeScanId): array
    {
        $resolved = $this->snapshots->resolve($projectId, $identifier, $activeScanId);
        $scanId = $resolved['scan_id'];
        if (!$resolved['is_active']) {
            // One table per read, inflated a few kilobytes at a time: decoding
            // the payload whole once and handing out slices held its JSON and
            // every table's rows beside the rows being compared. A payload
            // that can only be decoded whole (plain JSON from an earlier
            // version) is decoded once for every table, not once per table.
            $reader = new SnapshotGraphReader($this->pdo);
            if ($reader->isStreamable($scanId)) {
                $load = static fn(string $table): array => $reader->archivedTablesById($scanId, [$table])[$table];
            } else {
                $all = $reader->archivedTablesById($scanId, ['files', 'nodes', 'edges', 'classifications', 'boundaries', 'boundary_memberships', 'diagnostics']);
                $load = static fn(string $table): array => $all[$table] ?? [];
            }
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
}
