<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;
use Knossos\Reconciliation\ContributionCacheEntry;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * SQLite implementation of the graph store.
 *
 * Writes go through BEGIN IMMEDIATE rather than PDO's deferred transaction,
 * because a read-then-write upgrade under WAL can hit a non-retryable
 * SQLITE_BUSY; nesting is handled with savepoints so a reconciler already inside
 * a transaction can call these methods safely. Prepared statements are cached,
 * since a scan replays the same handful of inserts thousands of times.
 */
final class SqliteGraphRepository implements GraphRepository
{
    /** @var array<string, PDOStatement> */
    private array $statements = [];

    /** Depth of write transactions this repository has opened via BEGIN IMMEDIATE. */
    private int $transactionDepth = 0;

    /** Monotonic sequence used to name nested savepoints uniquely. */
    private int $savepointSequence = 0;

    /**
     * The graph tables a scan owns and the column identifying a row in each,
     * child-first so a delete never orphans a row it has not reached yet.
     */
    /** Every table a graph rewrite writes, which is the scope its integrity check needs. */
    private const REWRITTEN_TABLES = [
        'files', 'nodes', 'edges', 'classifications', 'boundaries', 'boundary_memberships', 'diagnostics',
    ];

    private const GRAPH_TABLES = [
        'boundary_memberships' => 'boundary_id',
        'boundaries' => 'id',
        'classifications' => 'id',
        'edges' => 'id',
        'nodes' => 'id',
        'files' => 'id',
    ];

    public function __construct(private PDO $pdo) {}

    /** {@inheritDoc} */
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
     * Run a whole-graph rewrite, verifying referential integrity once at the end.
     *
     * Per-statement foreign-key enforcement, not the row count, is what a rescan
     * spends its time on: SQLite runs the referencing-table sub-programs for
     * every row deleted, and clearing this repository's own graph measured 5.6s
     * that way against 0.6s with enforcement off and a single
     * `PRAGMA foreign_key_check` at the end. The check runs inside the
     * transaction, so a rewrite that would leave a dangling reference is rolled
     * back and never observable — the same guarantee, verified once instead of
     * a few hundred thousand times.
     *
     * `PRAGMA foreign_keys` is a no-op inside a transaction, so it is toggled
     * around the BEGIN and restored in a finally. A nested call cannot do that
     * and runs as an ordinary transaction instead.
     *
     * @template T
     * @param callable(GraphRepository): T $operation
     *
     * @return T
     */
    public function bulkTransaction(callable $operation): mixed
    {
        if ($this->transactionDepth > 0 || $this->pdo->inTransaction()) {
            return $this->transaction($operation);
        }
        $previous = (string) $this->pdo->query('PRAGMA foreign_keys')->fetchColumn();
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            return $this->transaction(function (GraphRepository $repository) use ($operation): mixed {
                $result = $operation($repository);
                // Checked per table rather than database-wide: an unrelated
                // table's pre-existing damage is not this rewrite's to fail on,
                // and naming the table keeps the blame where it belongs.
                foreach (self::REWRITTEN_TABLES as $table) {
                    $violations = $this->pdo->query(sprintf('PRAGMA foreign_key_check(%s)', $table))->fetchAll();
                    if ($violations !== []) {
                        throw new RuntimeException(sprintf(
                            'Refusing to commit a graph with %d dangling reference(s) in table %s.',
                            count($violations),
                            $table,
                        ));
                    }
                }

                return $result;
            });
        } finally {
            // Restored rather than forced on: a caller that had enforcement off
            // did not ask this method to change that.
            $this->pdo->exec('PRAGMA foreign_keys = ' . ($previous === '1' ? 'ON' : 'OFF'));
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

    /** Upsert by id: a rescan of the same root updates the name/config rather than creating a second project. */
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

    /**
     * The project row, or null when the id is unknown.
     *
     * @return array<string, mixed>|null the raw row, or null when the id is unknown
     */
    public function findProject(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM projects WHERE id = :id');
        $statement->execute(['id' => $id]);
        $project = $statement->fetch();

        return $project === false ? null : $project;
    }

    /**
     * Open a scan in `running` state.
     *
     * @param string $scannerSetHash identifies the analyzer set; a change invalidates
     *        incremental reuse, because facts from a different analyzer are not comparable
     * @throws InvalidArgumentException when $mode is neither full nor incremental
     */
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

    /**
     * Promote a running scan to the project's active snapshot and prune history.
     *
     * Transactional, and asserts exactly one running scan was updated: two writers
     * racing to finish would otherwise leave the project pointing at a graph that
     * only half-exists.
     *
     * @throws InvalidArgumentException when no running scan matches the project
     */
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

    /**
     * Record a terminal failed/cancelled scan for diagnostics.
     *
     * Silently skips a project that was never persisted: the failure may have been
     * the persist itself, and there is nothing for the foreign key to reference.
     *
     * @throws InvalidArgumentException on an unknown mode or a non-terminal status
     */
    public function recordFailedScan(string $id, string $projectId, string $mode, string $status): void
    {
        if (!in_array($mode, ['full', 'incremental'], true)) {
            throw new InvalidArgumentException('Scan mode must be full or incremental.');
        }
        if (!in_array($status, ['failed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Terminal scan status must be failed or cancelled.');
        }
        $this->transaction(function () use ($id, $projectId, $mode, $status): void {
            // A terminal record for a project that was never persisted has nothing
            // to reference and nothing to clean up, so skip it rather than trip the
            // scans.project_id foreign key.
            $exists = $this->pdo->prepare('SELECT 1 FROM projects WHERE id = :project');
            $exists->execute(['project' => $projectId]);
            if ($exists->fetchColumn() === false) {
                return;
            }
            $now = self::now();
            $statement = $this->pdo->prepare(
                'INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at, finished_at) ' .
                'VALUES (:id, :project, :mode, :status, :hash, :started, :finished)',
            );
            $statement->execute([
                'id' => $id,
                'project' => $projectId,
                'mode' => $mode,
                'status' => $status,
                'hash' => '',
                'started' => $now,
                'finished' => $now,
            ]);
        });
    }

    /**
     * Capture the current active graph as a retained snapshot, then prune to $retention.
     *
     * No-ops when retention is 0, when the project has no active scan, or when this
     * scan is already snapshotted — the last case also avoids building and encoding a
     * payload that INSERT OR IGNORE would discard.
     *
     * @throws InvalidArgumentException when $retention is outside 0..20
     */
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

        // Streamed row by row into an incremental compressor rather than built
        // whole: materialising the payload cost several times its own size in
        // peak memory, and that multiplier grew with the project.
        [$payload, $factCount, $byteSize] = $complete
            ? $this->streamSnapshotPayload($projectId, $tables, $complete)
            : [null, 0, 0];
        if (!$complete) {
            // Which ceiling stopped it: too many facts to be worth keeping, or a
            // payload that outgrew the byte cap while being written.
            $encoded = self::json(['schema' => 1, 'reason' => $payload === null ? 'fact_limit' : 'byte_limit']);
            $factCount = 0;
            $byteSize = strlen($encoded);
            $payload = SnapshotPayload::encode($encoded);
        }
        // byte_size stays the size of the facts themselves. It answers "how big
        // is this snapshot", which readers compare across scans; how many bytes
        // the row happens to occupy after compression is a storage detail.
        $insert = $this->pdo->prepare(
            'INSERT OR IGNORE INTO scan_snapshots(scan_id, project_id, scanner_set_hash, config_hash, complete, fact_count, byte_size, payload_json, captured_at) ' .
            'VALUES (:scan, :project, :scanner, :config, :complete, :facts, :bytes, :payload, :captured)',
        );
        $insert->execute([
            'scan' => $scanId, 'project' => $projectId, 'scanner' => $scannerHash, 'config' => $configHash,
            'complete' => $complete ? 1 : 0, 'facts' => $factCount, 'bytes' => $byteSize,
            'payload' => $payload, 'captured' => self::now(),
        ]);
    }

    /**
     * Compress a project's facts into a stored payload as the rows are read.
     *
     * @param list<string> $tables
     * @param bool $complete set to false when the payload outgrew its byte ceiling
     *
     * @return array{0: string, 1: int, 2: int} payload, fact count, uncompressed size
     */
    private function streamSnapshotPayload(string $projectId, array $tables, bool &$complete): array
    {
        $writer = SnapshotPayload::writer(50_000_000);
        $writer->write('{"schema":1,"facts":{');
        $factCount = 0;
        foreach ($tables as $index => $table) {
            $order = $table === 'boundary_memberships' ? 'boundary_id, node_id' : 'id';
            $statement = $this->pdo->prepare(sprintf('SELECT * FROM %s WHERE project_id = :project ORDER BY %s', $table, $order));
            $statement->execute(['project' => $projectId]);
            $writer->write(sprintf('%s"%s":[', $index === 0 ? '' : ',', $table));
            $first = true;
            while (($row = $statement->fetch()) !== false) {
                $writer->write(($first ? '' : ',') . self::json($row));
                $first = false;
                ++$factCount;
                if ($writer->exceeded()) {
                    // The payload is already being abandoned; reading and
                    // encoding the rest of this table would be work for nothing.
                    break;
                }
            }
            $writer->write(']');
            if ($writer->exceeded()) {
                // Past the ceiling the payload is discarded, so stop reading.
                $complete = false;

                return ['', 0, 0];
            }
        }
        $writer->write('}}');

        return [$writer->finish(), $factCount, $writer->byteSize()];
    }

    /**
     * The ids a project's graph currently holds, per table.
     *
     * Read before the scan's own rows are written, so the difference against
     * what the scan produced is exactly what no longer exists.
     *
     * @return array<string, array<string, true>> table name to id set
     */
    public function existingGraphIds(string $projectId): array
    {
        $ids = [];
        foreach (array_keys(self::GRAPH_TABLES) as $table) {
            if ($table === 'boundary_memberships') {
                continue;
            }
            $statement = $this->prepare(sprintf('SELECT id FROM %s WHERE project_id = :project', $table));
            $statement->execute(['project' => $projectId]);
            $seen = [];
            while (($row = $statement->fetch()) !== false) {
                $seen[(string) $row['id']] = true;
            }
            $ids[$table] = $seen;
        }
        // A membership is identified by the pair it joins, not by an id column.
        $statement = $this->prepare('SELECT boundary_id, node_id FROM boundary_memberships WHERE project_id = :project');
        $statement->execute(['project' => $projectId]);
        $memberships = [];
        while (($row = $statement->fetch()) !== false) {
            $memberships[$row['boundary_id'] . "\0" . $row['node_id']] = true;
        }
        $ids['boundary_memberships'] = $memberships;

        return $ids;
    }

    /**
     * Delete the graph rows a scan did not produce, leaving the rest untouched.
     *
     * The alternative — clearing the project and writing every row back — cost
     * the size of the project on every rescan rather than the size of the
     * change. Child rows go first so the delete order is meaningful even though
     * a bulk transaction defers the foreign-key check to the commit.
     *
     * @param array<string, array<string, true>> $existing @param array<string, array<string, true>> $desired
     */
    public function pruneGraph(string $projectId, array $existing, array $desired): void
    {
        foreach (array_keys(self::GRAPH_TABLES) as $table) {
            $obsolete = array_keys(array_diff_key($existing[$table] ?? [], $desired[$table] ?? []));
            if ($obsolete === []) {
                continue;
            }
            if ($table === 'boundary_memberships') {
                $this->deleteMemberships($projectId, $obsolete);
                continue;
            }
            foreach (array_chunk($obsolete, 400) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $statement = $this->prepare(sprintf('DELETE FROM %s WHERE project_id = ? AND id IN (%s)', $table, $placeholders));
                $statement->execute([$projectId, ...$chunk]);
            }
        }
    }

    /**
     * Delete memberships by the pair they join, grouped so each delete can use the index.
     *
     * @param list<string> $pairs boundary id and node id joined by a NUL byte
     */
    private function deleteMemberships(string $projectId, array $pairs): void
    {
        $byBoundary = [];
        foreach ($pairs as $pair) {
            [$boundaryId, $nodeId] = explode("\0", $pair, 2);
            $byBoundary[$boundaryId][] = $nodeId;
        }
        foreach ($byBoundary as $boundaryId => $nodeIds) {
            foreach (array_chunk($nodeIds, 400) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $statement = $this->prepare(sprintf(
                    'DELETE FROM boundary_memberships WHERE project_id = ? AND boundary_id = ? AND node_id IN (%s)',
                    $placeholders,
                ));
                $statement->execute([$projectId, $boundaryId, ...$chunk]);
            }
        }
    }

    /**
     * Attribute every surviving graph row to the scan that just confirmed it.
     *
     * Rows a scan left untouched are still current, and `last_scan_id` is what
     * keeps scan cleanup from deleting history the graph still points at — a row
     * left on an older scan would pin that scan forever. Nothing indexes this
     * column, so the update rewrites rows without touching an index: on this
     * repository's graph it costs about a tenth of a second against the couple
     * of seconds a full rewrite spent on index maintenance alone.
     */
    public function stampGraphScan(string $projectId, string $scanId): void
    {
        foreach (array_keys(self::GRAPH_TABLES) as $table) {
            $statement = $this->prepare(sprintf('UPDATE %s SET last_scan_id = :scan WHERE project_id = :project AND last_scan_id <> :scan', $table));
            $statement->execute(['scan' => $scanId, 'project' => $projectId]);
        }
    }

    /** Drop a project's diagnostics, which belong to the scan that produced them. */
    public function clearProjectDiagnostics(string $projectId): void
    {
        $this->prepare('DELETE FROM diagnostics WHERE project_id = :project')->execute(['project' => $projectId]);
    }

    /** Drop the oldest snapshots beyond the project's retention setting. */

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

    /** Record one discovered file and the fingerprint that lets the next scan decide whether to re-analyse it. */
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

    /**
     * Persist one graph node.
     *
     * @param string $ownerKey the contributing scanner, so reconciliation can replace
     *        one analyzer's facts without disturbing another's
     * @param array<string, mixed> $attributes
     */
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

    /**
     * Persist one relationship.
     *
     * @param string $confidence how far the edge is inferred rather than proven; queries
     *        filter on it, so a guess must never be recorded as certain
     * @param array<string, mixed> $attributes
     */
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

    /**
     * Insert many nodes in one prepared batch, since a scan writes thousands.
     *
     * @param list<array<string, mixed>> $nodes rows shaped as GraphReconciler node records
     */
    public function saveNodes(array $nodes, string $projectId, string $scanId): void
    {
        foreach (array_chunk($nodes, 60) as $chunk) { // 15 params/row keeps chunks under SQLite's 999-variable floor
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
            $sql = 'INSERT INTO nodes(id, project_id, language, kind, canonical_name, display_name, parent_id, file_id, start_line, end_line, origin, confidence, attributes_json, owner_key, last_scan_id) VALUES '
                . $placeholders
                . ' ON CONFLICT(id) DO UPDATE SET language = excluded.language, kind = excluded.kind, canonical_name = excluded.canonical_name, display_name = excluded.display_name, parent_id = excluded.parent_id, file_id = excluded.file_id, start_line = excluded.start_line, end_line = excluded.end_line, origin = excluded.origin, confidence = excluded.confidence, attributes_json = excluded.attributes_json, owner_key = excluded.owner_key, last_scan_id = excluded.last_scan_id'
                . ' WHERE nodes.language IS NOT excluded.language OR nodes.kind IS NOT excluded.kind OR nodes.canonical_name IS NOT excluded.canonical_name OR nodes.display_name IS NOT excluded.display_name OR nodes.parent_id IS NOT excluded.parent_id OR nodes.file_id IS NOT excluded.file_id OR nodes.start_line IS NOT excluded.start_line OR nodes.end_line IS NOT excluded.end_line OR nodes.origin IS NOT excluded.origin OR nodes.confidence IS NOT excluded.confidence OR nodes.attributes_json IS NOT excluded.attributes_json OR nodes.owner_key IS NOT excluded.owner_key';
            $values = [];
            foreach ($chunk as $node) {
                array_push($values, $node['id'], $projectId, $node['language'], $node['kind'], $node['canonical_name'], $node['display_name'], null, $node['file_id'], $node['start_line'], $node['end_line'], $node['origin'], $node['confidence'], self::json($node['attributes']), $node['owner_key'], $scanId);
            }
            $this->prepare($sql)->execute($values);
        }
    }

    /**
     * Insert many edges in one prepared batch.
     *
     * @param list<array<string, mixed>> $edges rows shaped as GraphReconciler edge records
     */
    public function saveEdges(array $edges, string $projectId, string $scanId): void
    {
        foreach (array_chunk($edges, 70) as $chunk) { // 13 params/row
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?,?,?,?)'));
            $sql = 'INSERT INTO edges(id, project_id, kind, source_id, target_id, file_id, start_line, end_line, origin, confidence, attributes_json, owner_key, last_scan_id) VALUES '
                . $placeholders
                . ' ON CONFLICT(id) DO UPDATE SET kind = excluded.kind, source_id = excluded.source_id, target_id = excluded.target_id, file_id = excluded.file_id, start_line = excluded.start_line, end_line = excluded.end_line, origin = excluded.origin, confidence = excluded.confidence, attributes_json = excluded.attributes_json, owner_key = excluded.owner_key, last_scan_id = excluded.last_scan_id'
                . ' WHERE edges.kind IS NOT excluded.kind OR edges.source_id IS NOT excluded.source_id OR edges.target_id IS NOT excluded.target_id OR edges.file_id IS NOT excluded.file_id OR edges.start_line IS NOT excluded.start_line OR edges.end_line IS NOT excluded.end_line OR edges.origin IS NOT excluded.origin OR edges.confidence IS NOT excluded.confidence OR edges.attributes_json IS NOT excluded.attributes_json OR edges.owner_key IS NOT excluded.owner_key';
            $values = [];
            foreach ($chunk as $edge) {
                array_push($values, $edge['id'], $projectId, $edge['kind'], $edge['source_id'], $edge['target_id'], $edge['file_id'], $edge['start_line'], $edge['end_line'], $edge['origin'], $edge['confidence'], self::json($edge['attributes']), $edge['owner_key'], $scanId);
            }
            $this->prepare($sql)->execute($values);
        }
    }

    /**
     * Insert many file rows in one prepared batch.
     *
     * @param list<array<string, mixed>> $files each: id, relative_path, content_hash, size, mtime, language, scanner_version, line_count
     */
    public function saveFiles(array $files, string $projectId, string $scanId): void
    {
        foreach (array_chunk($files, 90) as $chunk) { // 10 params/row
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?)'));
            $sql = 'INSERT INTO files(id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id, line_count) VALUES '
                . $placeholders
                . ' ON CONFLICT(project_id, relative_path) DO UPDATE SET content_hash = excluded.content_hash, size = excluded.size, mtime = excluded.mtime, language = excluded.language, scanner_version = excluded.scanner_version, last_scan_id = excluded.last_scan_id, line_count = excluded.line_count'
                . ' WHERE files.content_hash IS NOT excluded.content_hash OR files.size IS NOT excluded.size OR files.mtime IS NOT excluded.mtime OR files.language IS NOT excluded.language OR files.scanner_version IS NOT excluded.scanner_version OR files.line_count IS NOT excluded.line_count';
            $values = [];
            foreach ($chunk as $file) {
                array_push($values, $file['id'], $projectId, $file['relative_path'], $file['content_hash'], $file['size'], $file['mtime'], $file['language'], $file['scanner_version'], $scanId, $file['line_count']);
            }
            $this->prepare($sql)->execute($values);
        }
    }

    /**
     * Insert many classifications in one prepared batch.
     *
     * @param list<array<string, mixed>> $classifications each shaped as a GraphReconciler classification record
     */
    public function saveClassifications(array $classifications, string $projectId, string $scanId): void
    {
        foreach (array_chunk($classifications, 80) as $chunk) { // 12 params/row
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?,?,?)'));
            $sql = 'INSERT INTO classifications(id, project_id, node_id, role, origin, confidence, rule_id, file_id, start_line, end_line, attributes_json, last_scan_id) VALUES '
                . $placeholders
                . ' ON CONFLICT(id) DO UPDATE SET node_id = excluded.node_id, role = excluded.role, origin = excluded.origin, confidence = excluded.confidence, rule_id = excluded.rule_id, file_id = excluded.file_id, start_line = excluded.start_line, end_line = excluded.end_line, attributes_json = excluded.attributes_json, last_scan_id = excluded.last_scan_id'
                . ' WHERE classifications.node_id IS NOT excluded.node_id OR classifications.role IS NOT excluded.role OR classifications.origin IS NOT excluded.origin OR classifications.confidence IS NOT excluded.confidence OR classifications.rule_id IS NOT excluded.rule_id OR classifications.file_id IS NOT excluded.file_id OR classifications.start_line IS NOT excluded.start_line OR classifications.end_line IS NOT excluded.end_line OR classifications.attributes_json IS NOT excluded.attributes_json';
            $values = [];
            foreach ($chunk as $classification) {
                array_push($values, $classification['id'], $projectId, $classification['node_id'], $classification['role'], $classification['origin'], $classification['confidence'], $classification['rule_id'], $classification['file_id'], $classification['start_line'], $classification['end_line'], self::json($classification['attributes']), $scanId);
            }
            $this->prepare($sql)->execute($values);
        }
    }

    /**
     * Insert many boundary memberships in one prepared batch.
     *
     * @param list<array<string, mixed>> $memberships each: boundary_id, node_id
     */
    public function saveBoundaryMemberships(array $memberships, string $projectId, string $scanId): void
    {
        foreach (array_chunk($memberships, 240) as $chunk) { // 4 params/row
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?)'));
            $sql = 'INSERT OR IGNORE INTO boundary_memberships(boundary_id, project_id, node_id, last_scan_id) VALUES ' . $placeholders;
            $values = [];
            foreach ($chunk as $membership) {
                array_push($values, $membership['boundary_id'], $projectId, $membership['node_id'], $scanId);
            }
            $this->prepare($sql)->execute($values);
        }
    }

    /** Record a scanner-reported problem against a file, surfaced with the scan rather than thrown. */
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

    /**
     * Look up nodes by canonical or display name.
     *
     * @return list<array<string, mixed>>
     */
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

    /**
     * Record an inferred role for a node, attributed to the rule that inferred it.
     *
     * @param array<string, mixed> $attributes
     */
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

    /**
     * Persist a boundary, declared or inferred.
     *
     * @param array<string, mixed> $matcher the path/name rules defining membership
     */
    public function saveBoundary(string $id, string $projectId, string $name, array $matcher, string $source, string $scanId): void
    {
        $statement = $this->prepare(
            'INSERT INTO boundaries(id, project_id, name, matcher_json, source, last_scan_id) ' .
            'VALUES (:id, :project, :name, :matcher, :source, :scan) ' .
            'ON CONFLICT(id) DO UPDATE SET name = excluded.name, matcher_json = excluded.matcher_json, source = excluded.source, last_scan_id = excluded.last_scan_id ' .
            'WHERE boundaries.name IS NOT excluded.name OR boundaries.matcher_json IS NOT excluded.matcher_json OR boundaries.source IS NOT excluded.source',
        );
        $statement->execute([
            'id' => $id, 'project' => $projectId, 'name' => $name, 'matcher' => self::json($matcher),
            'source' => $source, 'scan' => $scanId,
        ]);
    }

    /** Attach a node to a boundary. Separate from the boundary itself so membership can be recomputed alone. */
    public function saveBoundaryMembership(string $boundaryId, string $projectId, string $nodeId, string $scanId): void
    {
        $statement = $this->prepare(
            'INSERT INTO boundary_memberships(boundary_id, project_id, node_id, last_scan_id) VALUES (:boundary, :project, :node, :scan)',
        );
        $statement->execute(['boundary' => $boundaryId, 'project' => $projectId, 'node' => $nodeId, 'scan' => $scanId]);
    }

    /**
     * Swap a project's incremental-reuse cache wholesale.
     *
     * Replaced rather than merged: a stale entry would let the next scan reuse facts
     * for a file it should have re-analysed.
     *
     * @param list<ContributionCacheEntry> $entries
     */
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

    /**
     * Edges leaving a node.
     *
     * @return list<array<string, mixed>>
     */
    public function outgoing(string $projectId, string $nodeId, ?string $kind = null, int $limit = 100): array
    {
        return $this->adjacent('source_id', $projectId, $nodeId, $kind, $limit);
    }

    /**
     * Edges arriving at a node.
     *
     * @return list<array<string, mixed>>
     */
    public function incoming(string $projectId, string $nodeId, ?string $kind = null, int $limit = 100): array
    {
        return $this->adjacent('target_id', $projectId, $nodeId, $kind, $limit);
    }

    /**
     * Edges on one side of a node, shared by outgoing() and incoming().
     *
     * @return list<array<string, mixed>>
     */
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
    /** Reject a limit outside its bounds rather than clamping it silently. */

    private static function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Query limit must be between 1 and 1000.');
        }
    }
    /** A cached prepared statement, since a scan replays the same inserts repeatedly. */

    private function prepare(string $sql): PDOStatement
    {
        return $this->statements[$sql] ??= $this->pdo->prepare($sql);
    }

    /**
     * Encode an attributes array for storage, throwing rather than storing malformed JSON.
     *
     * @param array<string, mixed> $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    /** The current timestamp in the format the schema stores. */

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
