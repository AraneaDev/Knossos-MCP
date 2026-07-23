<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;
use Knossos\Reconciliation\ContributionCacheEntry;
use PDO;
use PDOStatement;
use Throwable;

final class SqliteGraphRepository implements GraphRepository
{
    /** @var array<string, PDOStatement> */
    private array $statements = [];

    /** Depth of write transactions this repository has opened via BEGIN IMMEDIATE. */
    private int $transactionDepth = 0;

    /** Monotonic sequence used to name nested savepoints uniquely. */
    private int $savepointSequence = 0;

    public function __construct(private PDO $pdo) {}

    public function transaction(callable $operation): mixed
    {
        if ($this->transactionDepth > 0 || $this->pdo->inTransaction()) {
            return $this->savepointTransaction($operation);
        }

        // BEGIN IMMEDIATE acquires the write lock up front so a read-then-write
        // upgrade under WAL cannot hit a non-retryable SQLITE_BUSY. PDO's
        // beginTransaction() issues a deferred BEGIN, and PDO::inTransaction()
        // only tracks API-level transactions, so the boundary and the nesting
        // depth are managed manually here.
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->transactionDepth = 1;
        try {
            $result = $operation($this);
            $this->pdo->exec('COMMIT');
            $this->transactionDepth = 0;
            $this->savepointSequence = 0;

            return $result;
        } catch (Throwable $error) {
            $this->transactionDepth = 0;
            $this->savepointSequence = 0;
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            throw $error;
        }
    }

    /**
     * Run a nested transaction as a SAVEPOINT so a caught inner failure rolls
     * back only the inner work. The previous no-op nesting silently committed
     * partial inner writes with the enclosing transaction.
     *
     * @template T
     * @param callable(GraphRepository): T $operation
     * @return T
     */
    private function savepointTransaction(callable $operation): mixed
    {
        $name = 'knossos_sp_' . $this->savepointSequence++;
        $this->transactionDepth++;
        $this->pdo->exec('SAVEPOINT ' . $name);
        try {
            $result = $operation($this);
            $this->pdo->exec('RELEASE SAVEPOINT ' . $name);
            $this->transactionDepth--;

            return $result;
        } catch (Throwable $error) {
            $this->transactionDepth--;
            try {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $name);
            } catch (Throwable) {
            }
            throw $error;
        }
    }

    public function saveProject(string $id, string $name, string $rootRealpath, array $config = []): void
    {
        $now = self::now();
        $statement = $this->pdo->prepare(
            'INSERT INTO projects(id, name, root_realpath, config_json, created_at, updated_at) ' .
            'VALUES (:id, :name, :root, :config, :created, :updated) ' .
            'ON CONFLICT(id) DO UPDATE SET name = excluded.name, root_realpath = excluded.root_realpath, ' .
            'config_json = excluded.config_json, updated_at = excluded.updated_at',
        );
        $statement->execute([
            'id' => $id,
            'name' => $name,
            'root' => $rootRealpath,
            'config' => self::json($config),
            'created' => $now,
            'updated' => $now,
        ]);
    }

    public function findProject(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM projects WHERE id = :id');
        $statement->execute(['id' => $id]);
        $project = $statement->fetch();

        return $project === false ? null : $project;
    }

    public function createScan(string $id, string $projectId, string $mode, string $scannerSetHash): void
    {
        if (!in_array($mode, ['full', 'incremental'], true)) {
            throw new InvalidArgumentException('Scan mode must be full or incremental.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at) ' .
            'VALUES (:id, :project, :mode, :status, :hash, :started)',
        );
        $statement->execute([
            'id' => $id,
            'project' => $projectId,
            'mode' => $mode,
            'status' => 'running',
            'hash' => $scannerSetHash,
            'started' => self::now(),
        ]);
    }

    public function completeScan(string $projectId, string $scanId): void
    {
        $this->transaction(function () use ($projectId, $scanId): void {
            $updateScan = $this->pdo->prepare(
                'UPDATE scans SET status = :status, finished_at = :finished ' .
                'WHERE id = :id AND project_id = :project AND status = :running',
            );
            $updateScan->execute([
                'status' => 'complete',
                'finished' => self::now(),
                'id' => $scanId,
                'project' => $projectId,
                'running' => 'running',
            ]);
            if ($updateScan->rowCount() !== 1) {
                throw new InvalidArgumentException('Running scan not found for project.');
            }

            $updateProject = $this->pdo->prepare(
                'UPDATE projects SET active_scan_id = :scan, updated_at = :updated WHERE id = :project',
            );
            $updateProject->execute([
                'scan' => $scanId,
                'updated' => self::now(),
                'project' => $projectId,
            ]);
            $project = $this->findProject($projectId);
            $config = is_array($project) ? json_decode((string) $project['config_json'], true, 32, JSON_THROW_ON_ERROR) : [];
            $this->pruneSnapshotHistory($projectId, is_int($config['snapshot_retention'] ?? null) ? $config['snapshot_retention'] : 5);
        });
    }

    public function archiveActiveSnapshot(string $projectId, string $configHash, int $retention): void
    {
        if ($retention < 0 || $retention > 20) {
            throw new InvalidArgumentException('Snapshot retention must be between 0 and 20.');
        }
        if ($retention === 0) {
            return;
        }
        $project = $this->findProject($projectId);
        $scanId = is_array($project) ? $project['active_scan_id'] : null;
        if (!is_string($scanId) || $scanId === '') {
            return;
        }
        // Skip when the active graph is unchanged: a snapshot for this scan was
        // already captured, so there is nothing new to archive. This also
        // avoids materialising and JSON-encoding a payload only for the
        // INSERT OR IGNORE below to discard it.
        $existing = $this->pdo->prepare('SELECT 1 FROM scan_snapshots WHERE scan_id = :scan');
        $existing->execute(['scan' => $scanId]);
        if ($existing->fetchColumn() !== false) {
            return;
        }
        $scan = $this->pdo->prepare('SELECT scanner_set_hash FROM scans WHERE id = :scan AND project_id = :project AND status = :status');
        $scan->execute(['scan' => $scanId, 'project' => $projectId, 'status' => 'complete']);
        $scannerHash = $scan->fetchColumn();
        if (!is_string($scannerHash)) {
            return;
        }

        $tables = ['files', 'nodes', 'edges', 'classifications', 'boundaries', 'boundary_memberships', 'diagnostics'];

        // Count first so an over-limit table is never fetched into memory only
        // to be discarded (the previous SELECT * ... LIMIT 200001 + fetchAll
        // materialised up to 1.4M rows before checking the bound).
        $complete = true;
        foreach ($tables as $table) {
            $count = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE project_id = :project', $table));
            $count->execute(['project' => $projectId]);
            if ((int) $count->fetchColumn() > 200_000) {
                $complete = false;
                break;
            }
        }

        // Build and JSON-encode the payload exactly once. The over-limit and
        // over-byte cases encode only a small marker object, never the full
        // (discarded) payload a second time.
        $factCount = 0;
        if ($complete) {
            $payload = [];
            foreach ($tables as $table) {
                $order = $table === 'boundary_memberships' ? 'boundary_id, node_id' : 'id';
                $statement = $this->pdo->prepare(sprintf('SELECT * FROM %s WHERE project_id = :project ORDER BY %s', $table, $order));
                $statement->execute(['project' => $projectId]);
                $rows = $statement->fetchAll();
                $payload[$table] = $rows;
                $factCount += count($rows);
            }
            $encoded = self::json(['schema' => 1, 'facts' => $payload]);
            if (strlen($encoded) > 50_000_000) {
                $complete = false;
                $factCount = 0;
                $encoded = self::json(['schema' => 1, 'reason' => 'byte_limit']);
            }
        } else {
            $encoded = self::json(['schema' => 1, 'reason' => 'fact_limit']);
        }
        $insert = $this->pdo->prepare(
            'INSERT OR IGNORE INTO scan_snapshots(scan_id, project_id, scanner_set_hash, config_hash, complete, fact_count, byte_size, payload_json, captured_at) ' .
            'VALUES (:scan, :project, :scanner, :config, :complete, :facts, :bytes, :payload, :captured)',
        );
        $insert->execute([
            'scan' => $scanId, 'project' => $projectId, 'scanner' => $scannerHash, 'config' => $configHash,
            'complete' => $complete ? 1 : 0, 'facts' => $factCount, 'bytes' => strlen($encoded),
            'payload' => $encoded, 'captured' => self::now(),
        ]);
    }

    public function clearProjectGraph(string $projectId): void
    {
        foreach (['diagnostics', 'boundary_memberships', 'boundaries', 'classifications', 'edges', 'nodes', 'files'] as $table) {
            $statement = $this->pdo->prepare(sprintf('DELETE FROM %s WHERE project_id = :project', $table));
            $statement->execute(['project' => $projectId]);
        }
    }

    private function pruneSnapshotHistory(string $projectId, int $retention): void
    {
        $statement = $this->pdo->prepare('SELECT scan_id FROM scan_snapshots WHERE project_id = :project ORDER BY captured_at DESC, rowid DESC');
        $statement->execute(['project' => $projectId]);
        $snapshotIds = $statement->fetchAll(PDO::FETCH_COLUMN);
        $deleteSnapshot = $this->pdo->prepare('DELETE FROM scan_snapshots WHERE scan_id = :scan AND project_id = :project');
        foreach (array_slice($snapshotIds, $retention) as $snapshotId) {
            $deleteSnapshot->execute(['scan' => $snapshotId, 'project' => $projectId]);
        }
        $deleteScans = $this->pdo->prepare(
            "DELETE FROM scans WHERE project_id = :project AND status = 'complete' " .
            'AND id <> COALESCE((SELECT active_scan_id FROM projects WHERE id = :project), \'\') ' .
            'AND NOT EXISTS (SELECT 1 FROM scan_snapshots ss WHERE ss.scan_id = scans.id)',
        );
        $deleteScans->execute(['project' => $projectId]);
    }

    public function saveFile(
        string $id,
        string $projectId,
        string $relativePath,
        string $contentHash,
        int $size,
        int $mtime,
        string $language,
        string $scannerVersion,
        string $scanId,
        int $lineCount = 0,
    ): void {
        $statement = $this->prepare(
            'INSERT INTO files(id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id, line_count) ' .
            'VALUES (:id, :project, :path, :hash, :size, :mtime, :language, :scanner, :scan, :lines) ' .
            'ON CONFLICT(project_id, relative_path) DO UPDATE SET content_hash = excluded.content_hash, ' .
            'size = excluded.size, mtime = excluded.mtime, language = excluded.language, ' .
            'scanner_version = excluded.scanner_version, last_scan_id = excluded.last_scan_id, ' .
            'line_count = excluded.line_count',
        );
        $statement->execute([
            'id' => $id,
            'project' => $projectId,
            'path' => $relativePath,
            'hash' => $contentHash,
            'size' => $size,
            'mtime' => $mtime,
            'language' => $language,
            'scanner' => $scannerVersion,
            'scan' => $scanId,
            'lines' => $lineCount,
        ]);
    }

    public function saveNode(
        string $id,
        string $projectId,
        string $language,
        string $kind,
        string $canonicalName,
        string $displayName,
        ?string $parentId,
        ?string $fileId,
        ?int $startLine,
        ?int $endLine,
        string $origin,
        string $confidence,
        array $attributes,
        string $ownerKey,
        string $scanId,
    ): void {
        $statement = $this->prepare(
            'INSERT INTO nodes(id, project_id, language, kind, canonical_name, display_name, parent_id, file_id, start_line, ' .
            'end_line, origin, confidence, attributes_json, owner_key, last_scan_id) ' .
            'VALUES (:id, :project, :language, :kind, :canonical, :display, :parent, :file, :start, :end, :origin, ' .
            ':confidence, :attributes, :owner, :scan) ' .
            'ON CONFLICT(id) DO UPDATE SET language = excluded.language, kind = excluded.kind, canonical_name = excluded.canonical_name, ' .
            'display_name = excluded.display_name, parent_id = excluded.parent_id, file_id = excluded.file_id, ' .
            'start_line = excluded.start_line, end_line = excluded.end_line, origin = excluded.origin, ' .
            'confidence = excluded.confidence, attributes_json = excluded.attributes_json, ' .
            'owner_key = excluded.owner_key, last_scan_id = excluded.last_scan_id',
        );
        $statement->execute([
            'id' => $id,
            'project' => $projectId,
            'language' => $language,
            'kind' => $kind,
            'canonical' => $canonicalName,
            'display' => $displayName,
            'parent' => $parentId,
            'file' => $fileId,
            'start' => $startLine,
            'end' => $endLine,
            'origin' => $origin,
            'confidence' => $confidence,
            'attributes' => self::json($attributes),
            'owner' => $ownerKey,
            'scan' => $scanId,
        ]);
    }

    public function saveEdge(
        string $id,
        string $projectId,
        string $kind,
        string $sourceId,
        string $targetId,
        ?string $fileId,
        ?int $startLine,
        ?int $endLine,
        string $origin,
        string $confidence,
        array $attributes,
        string $ownerKey,
        string $scanId,
    ): void {
        $statement = $this->prepare(
            'INSERT INTO edges(id, project_id, kind, source_id, target_id, file_id, start_line, end_line, origin, ' .
            'confidence, attributes_json, owner_key, last_scan_id) ' .
            'VALUES (:id, :project, :kind, :source, :target, :file, :start, :end, :origin, :confidence, ' .
            ':attributes, :owner, :scan) ' .
            'ON CONFLICT(id) DO UPDATE SET kind = excluded.kind, source_id = excluded.source_id, ' .
            'target_id = excluded.target_id, file_id = excluded.file_id, start_line = excluded.start_line, ' .
            'end_line = excluded.end_line, origin = excluded.origin, confidence = excluded.confidence, ' .
            'attributes_json = excluded.attributes_json, owner_key = excluded.owner_key, last_scan_id = excluded.last_scan_id',
        );
        $statement->execute([
            'id' => $id,
            'project' => $projectId,
            'kind' => $kind,
            'source' => $sourceId,
            'target' => $targetId,
            'file' => $fileId,
            'start' => $startLine,
            'end' => $endLine,
            'origin' => $origin,
            'confidence' => $confidence,
            'attributes' => self::json($attributes),
            'owner' => $ownerKey,
            'scan' => $scanId,
        ]);
    }

    /** @param list<array<string, mixed>> $nodes rows shaped as GraphReconciler node records */
    public function saveNodes(array $nodes, string $projectId, string $scanId): void
    {
        foreach (array_chunk($nodes, 60) as $chunk) { // 15 params/row keeps chunks under SQLite's 999-variable floor
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
            $sql = 'INSERT INTO nodes(id, project_id, language, kind, canonical_name, display_name, parent_id, file_id, start_line, end_line, origin, confidence, attributes_json, owner_key, last_scan_id) VALUES '
                . $placeholders
                . ' ON CONFLICT(id) DO UPDATE SET language = excluded.language, kind = excluded.kind, canonical_name = excluded.canonical_name, display_name = excluded.display_name, parent_id = excluded.parent_id, file_id = excluded.file_id, start_line = excluded.start_line, end_line = excluded.end_line, origin = excluded.origin, confidence = excluded.confidence, attributes_json = excluded.attributes_json, owner_key = excluded.owner_key, last_scan_id = excluded.last_scan_id';
            $values = [];
            foreach ($chunk as $node) {
                array_push($values, $node['id'], $projectId, $node['language'], $node['kind'], $node['canonical_name'], $node['display_name'], null, $node['file_id'], $node['start_line'], $node['end_line'], $node['origin'], $node['confidence'], self::json($node['attributes']), $node['owner_key'], $scanId);
            }
            $this->prepare($sql)->execute($values);
        }
    }

    /** @param list<array<string, mixed>> $edges rows shaped as GraphReconciler edge records */
    public function saveEdges(array $edges, string $projectId, string $scanId): void
    {
        foreach (array_chunk($edges, 70) as $chunk) { // 13 params/row
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?,?,?,?)'));
            $sql = 'INSERT INTO edges(id, project_id, kind, source_id, target_id, file_id, start_line, end_line, origin, confidence, attributes_json, owner_key, last_scan_id) VALUES '
                . $placeholders
                . ' ON CONFLICT(id) DO UPDATE SET kind = excluded.kind, source_id = excluded.source_id, target_id = excluded.target_id, file_id = excluded.file_id, start_line = excluded.start_line, end_line = excluded.end_line, origin = excluded.origin, confidence = excluded.confidence, attributes_json = excluded.attributes_json, owner_key = excluded.owner_key, last_scan_id = excluded.last_scan_id';
            $values = [];
            foreach ($chunk as $edge) {
                array_push($values, $edge['id'], $projectId, $edge['kind'], $edge['source_id'], $edge['target_id'], $edge['file_id'], $edge['start_line'], $edge['end_line'], $edge['origin'], $edge['confidence'], self::json($edge['attributes']), $edge['owner_key'], $scanId);
            }
            $this->prepare($sql)->execute($values);
        }
    }

    public function saveDiagnostic(
        string $id,
        string $projectId,
        string $scanId,
        ?string $fileId,
        string $severity,
        string $code,
        string $message,
        ?int $startLine,
        ?int $endLine,
        string $ownerKey,
    ): void {
        $statement = $this->prepare(
            'INSERT INTO diagnostics(id, project_id, scan_id, file_id, severity, code, message, start_line, end_line, owner_key) ' .
            'VALUES (:id, :project, :scan, :file, :severity, :code, :message, :start, :end, :owner)',
        );
        $statement->execute([
            'id' => $id,
            'project' => $projectId,
            'scan' => $scanId,
            'file' => $fileId,
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'start' => $startLine,
            'end' => $endLine,
            'owner' => $ownerKey,
        ]);
    }

    public function findNodesByName(string $projectId, string $name, int $limit = 20): array
    {
        self::assertLimit($limit);
        $statement = $this->pdo->prepare(
            'SELECT * FROM nodes WHERE project_id = :project ' .
            'AND (canonical_name = :name OR display_name = :name) ' .
            'ORDER BY CASE WHEN canonical_name = :name THEN 0 ELSE 1 END, canonical_name LIMIT :limit',
        );
        $statement->bindValue(':project', $projectId);
        $statement->bindValue(':name', $name);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function saveClassification(
        string $id,
        string $projectId,
        string $nodeId,
        string $role,
        string $origin,
        string $confidence,
        string $ruleId,
        ?string $fileId,
        ?int $startLine,
        ?int $endLine,
        array $attributes,
        string $scanId,
    ): void {
        $statement = $this->prepare(
            'INSERT INTO classifications(id, project_id, node_id, role, origin, confidence, rule_id, file_id, ' .
            'start_line, end_line, attributes_json, last_scan_id) VALUES (:id, :project, :node, :role, :origin, ' .
            ':confidence, :rule, :file, :start, :end, :attributes, :scan)',
        );
        $statement->execute([
            'id' => $id, 'project' => $projectId, 'node' => $nodeId, 'role' => $role,
            'origin' => $origin, 'confidence' => $confidence, 'rule' => $ruleId, 'file' => $fileId,
            'start' => $startLine, 'end' => $endLine, 'attributes' => self::json($attributes), 'scan' => $scanId,
        ]);
    }

    public function saveBoundary(string $id, string $projectId, string $name, array $matcher, string $source, string $scanId): void
    {
        $statement = $this->prepare(
            'INSERT INTO boundaries(id, project_id, name, matcher_json, source, last_scan_id) ' .
            'VALUES (:id, :project, :name, :matcher, :source, :scan)',
        );
        $statement->execute([
            'id' => $id, 'project' => $projectId, 'name' => $name, 'matcher' => self::json($matcher),
            'source' => $source, 'scan' => $scanId,
        ]);
    }

    public function saveBoundaryMembership(string $boundaryId, string $projectId, string $nodeId, string $scanId): void
    {
        $statement = $this->prepare(
            'INSERT INTO boundary_memberships(boundary_id, project_id, node_id, last_scan_id) VALUES (:boundary, :project, :node, :scan)',
        );
        $statement->execute(['boundary' => $boundaryId, 'project' => $projectId, 'node' => $nodeId, 'scan' => $scanId]);
    }

    public function replaceContributionCache(string $projectId, array $entries): void
    {
        $delete = $this->pdo->prepare('DELETE FROM contribution_cache WHERE project_id = :project');
        $delete->execute(['project' => $projectId]);
        $insert = $this->prepare(
            'INSERT INTO contribution_cache(project_id, owner_key, file_path, content_hash, scanner_id, scanner_version, ' .
            'configuration_hash, payload_json, updated_at) VALUES (:project, :owner, :path, :hash, :scanner, :version, :config, :payload, :updated)',
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof ContributionCacheEntry) {
                throw new InvalidArgumentException('Invalid contribution cache entry.');
            }
            $insert->execute([
                'project' => $projectId,
                'owner' => $entry->contribution->ownerKey,
                'path' => $entry->filePath,
                'hash' => $entry->contentHash,
                'scanner' => $entry->scannerId,
                'version' => $entry->scannerVersion,
                'config' => $entry->configurationHash,
                'payload' => json_encode($entry->contribution, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated' => self::now(),
            ]);
        }
    }

    public function outgoing(string $projectId, string $nodeId, ?string $kind = null, int $limit = 100): array
    {
        return $this->adjacent('source_id', $projectId, $nodeId, $kind, $limit);
    }

    public function incoming(string $projectId, string $nodeId, ?string $kind = null, int $limit = 100): array
    {
        return $this->adjacent('target_id', $projectId, $nodeId, $kind, $limit);
    }

    public function deleteFactsByOwner(string $projectId, string $ownerKey): void
    {
        $this->transaction(function () use ($projectId, $ownerKey): void {
            foreach (['edges', 'nodes', 'diagnostics'] as $table) {
                $statement = $this->pdo->prepare(
                    sprintf('DELETE FROM %s WHERE project_id = :project AND owner_key = :owner', $table),
                );
                $statement->execute(['project' => $projectId, 'owner' => $ownerKey]);
            }
        });
    }

    /** @return list<array<string, mixed>> */
    private function adjacent(
        string $column,
        string $projectId,
        string $nodeId,
        ?string $kind,
        int $limit,
    ): array {
        self::assertLimit($limit);
        $sql = sprintf('SELECT * FROM edges WHERE project_id = :project AND %s = :node', $column);
        if ($kind !== null) {
            $sql .= ' AND kind = :kind';
        }
        $sql .= ' ORDER BY kind, id LIMIT :limit';

        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':project', $projectId);
        $statement->bindValue(':node', $nodeId);
        if ($kind !== null) {
            $statement->bindValue(':kind', $kind);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private static function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Query limit must be between 1 and 1000.');
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        return $this->statements[$sql] ??= $this->pdo->prepare($sql);
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
