<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Result\ResultEnvelope;
use PDO;

/**
 * The project catalogue and its retained scan history.
 *
 * Answers which projects exist, how fresh each is, and which snapshots each
 * keeps. Freshness is reported everywhere because a confident answer from a
 * stale graph is the most misleading thing this system can produce. What is
 * done with a snapshot once chosen lives apart: {@see SnapshotDiffQuery}
 * compares two, and {@see QualityGateQueryService} gates and trends them.
 */
final readonly class ProjectCatalogQueryService extends AbstractArchitectureQueryService
{
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

    /**
     * One page of projects in creation order, starting after the given position.
     *
     * Ordered by (created_at, id), which a scan never changes: a rescan bumps
     * updated_at, so paging over listProjects()' most-recently-updated order
     * skipped or repeated projects whenever one was scanned between pages. The
     * position is a keyset, not an offset, so adding or removing a project
     * between pages cannot shift the rest either.
     *
     * No position (null) starts at the beginning: every created_at sorts after
     * the empty string. $limit is the caller's page size, not client input.
     *
     * @return array{projects: list<array{id: string, name: string, created_at: string}>, more: bool}
     */
    public function projectsInCreationOrder(int $limit, ?string $afterCreatedAt, ?string $afterId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, created_at FROM projects ' .
            'WHERE created_at > :after_created OR (created_at = :same_created AND id > :after_id) ' .
            'ORDER BY created_at ASC, id ASC LIMIT :limit',
        );
        $statement->bindValue(':after_created', (string) $afterCreatedAt);
        $statement->bindValue(':same_created', (string) $afterCreatedAt);
        $statement->bindValue(':after_id', (string) $afterId);
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();
        /** @var list<array{id: string, name: string, created_at: string}> $rows */
        $rows = $statement->fetchAll();

        return ['projects' => array_slice($rows, 0, $limit), 'more' => count($rows) > $limit];
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
}
