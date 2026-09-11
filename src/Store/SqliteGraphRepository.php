<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;
use PDO;

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
    /** The prepared-statement cache every store class for this connection shares. */
    private SqliteStatementCache $statements;

    /** The one transaction state this connection has: nesting depends on it being shared. */
    private SqliteTransactions $transactions;

    /** Reads over the stored graph. */
    private SqliteGraphReader $reader;

    /** Every row a scan writes. */
    private SqliteGraphWriter $writer;

    /** Brings the stored graph in line with a rescan. */
    private SqliteGraphPruner $pruner;

    /** Projects and scans. */
    private SqliteScanLifecycle $lifecycle;

    /** Every table a graph rewrite writes, which is the scope its integrity check needs. */
    private const REWRITTEN_TABLES = [
        'files', 'nodes', 'edges', 'classifications', 'boundaries', 'boundary_memberships', 'diagnostics',
    ];

    public function __construct(private PDO $pdo)
    {
        $this->statements = new SqliteStatementCache($pdo);
        $this->transactions = new SqliteTransactions($pdo);
        $this->reader = new SqliteGraphReader($pdo);
        $this->writer = new SqliteGraphWriter($this->statements);
        $this->pruner = new SqliteGraphPruner($this->statements);
        $this->lifecycle = new SqliteScanLifecycle($this->statements, $this->transactions);
    }

    /** {@inheritDoc} */
    public function transaction(callable $operation): mixed
    {
        return $this->transactions->run(fn(): mixed => $operation($this));
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
        return $this->transactions->runBulk(fn(): mixed => $operation($this), self::REWRITTEN_TABLES);
    }

    /** Upsert by id: a rescan of the same root updates the name/config rather than creating a second project. */
    public function saveProject(string $id, string $name, string $rootRealpath, array $config = []): void
    {
        $this->lifecycle->saveProject($id, $name, $rootRealpath, $config);
    }

    /**
     * The project row, or null when the id is unknown.
     *
     * @return array<string, mixed>|null the raw row, or null when the id is unknown
     */
    public function findProject(string $id): ?array
    {
        return $this->lifecycle->findProject($id);
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
        $this->lifecycle->createScan($id, $projectId, $mode, $scannerSetHash);
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
        $this->lifecycle->completeScan($projectId, $scanId);
    }

    /**
     * Restamp a completed scan's finished_at, recording that its graph was
     * re-verified against the source without being rebuilt.
     *
     * finished_at is read as "when this graph last agreed with the tree", and it
     * is what StalenessProbe compares directory mtimes against to notice added
     * files. A scan that discovered no change has re-established that agreement,
     * so leaving the timestamp where it was reports drift that no later scan
     * could ever clear. Restricted to a complete scan: a running or terminal one
     * has no completion to restate, and its timestamp means something else.
     */
    public function refreshScanCompletion(string $projectId, string $scanId): void
    {
        $this->lifecycle->refreshScanCompletion($projectId, $scanId);
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
        $this->lifecycle->recordFailedScan($id, $projectId, $mode, $status);
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
            $encoded = SqliteValues::json(['schema' => 1, 'reason' => $payload === null ? 'fact_limit' : 'byte_limit']);
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
            'payload' => $payload, 'captured' => SqliteValues::now(),
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
                $writer->write(($first ? '' : ',') . SqliteValues::json($row));
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
        return $this->pruner->existingGraphIds($projectId);
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
        $this->pruner->pruneGraph($projectId, $existing, $desired);
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
        $this->pruner->stampGraphScan($projectId, $scanId);
    }

    /** Drop a project's diagnostics, which belong to the scan that produced them. */
    public function clearProjectDiagnostics(string $projectId): void
    {
        $this->pruner->clearProjectDiagnostics($projectId);
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
        $this->writer->saveFile($id, $projectId, $relativePath, $contentHash, $size, $mtime, $language, $scannerVersion, $scanId, $lineCount);
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
        $this->writer->saveNode($id, $projectId, $language, $kind, $canonicalName, $displayName, $parentId, $fileId, $startLine, $endLine, $origin, $confidence, $attributes, $ownerKey, $scanId);
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
        $this->writer->saveEdge($id, $projectId, $kind, $sourceId, $targetId, $fileId, $startLine, $endLine, $origin, $confidence, $attributes, $ownerKey, $scanId);
    }

    /**
     * Insert many nodes in one prepared batch, since a scan writes thousands.
     *
     * @param list<array<string, mixed>> $nodes rows shaped as GraphReconciler node records
     */
    public function saveNodes(array $nodes, string $projectId, string $scanId): void
    {
        $this->writer->saveNodes($nodes, $projectId, $scanId);
    }

    /**
     * Insert many edges in one prepared batch.
     *
     * @param list<array<string, mixed>> $edges rows shaped as GraphReconciler edge records
     */
    public function saveEdges(array $edges, string $projectId, string $scanId): void
    {
        $this->writer->saveEdges($edges, $projectId, $scanId);
    }

    /**
     * Insert many file rows in one prepared batch.
     *
     * @param list<array<string, mixed>> $files each: id, relative_path, content_hash, size, mtime, language, scanner_version, line_count
     */
    public function saveFiles(array $files, string $projectId, string $scanId): void
    {
        $this->writer->saveFiles($files, $projectId, $scanId);
    }

    /**
     * Insert many classifications in one prepared batch.
     *
     * @param list<array<string, mixed>> $classifications each shaped as a GraphReconciler classification record
     */
    public function saveClassifications(array $classifications, string $projectId, string $scanId): void
    {
        $this->writer->saveClassifications($classifications, $projectId, $scanId);
    }

    /**
     * Insert many boundary memberships in one prepared batch.
     *
     * @param list<array<string, mixed>> $memberships each: boundary_id, node_id
     */
    public function saveBoundaryMemberships(array $memberships, string $projectId, string $scanId): void
    {
        $this->writer->saveBoundaryMemberships($memberships, $projectId, $scanId);
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
        $this->writer->saveDiagnostic($id, $projectId, $scanId, $fileId, $severity, $code, $message, $startLine, $endLine, $ownerKey);
    }

    /**
     * Look up nodes by canonical or display name.
     *
     * @return list<array<string, mixed>>
     */
    public function findNodesByName(string $projectId, string $name, int $limit = 20): array
    {
        return $this->reader->findNodesByName($projectId, $name, $limit);
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
        $this->writer->saveClassification($id, $projectId, $nodeId, $role, $origin, $confidence, $ruleId, $fileId, $startLine, $endLine, $attributes, $scanId);
    }

    /**
     * Persist a boundary, declared or inferred.
     *
     * @param array<string, mixed> $matcher the path/name rules defining membership
     */
    public function saveBoundary(string $id, string $projectId, string $name, array $matcher, string $source, string $scanId): void
    {
        $this->writer->saveBoundary($id, $projectId, $name, $matcher, $source, $scanId);
    }

    /** Attach a node to a boundary. Separate from the boundary itself so membership can be recomputed alone. */
    public function saveBoundaryMembership(string $boundaryId, string $projectId, string $nodeId, string $scanId): void
    {
        $this->writer->saveBoundaryMembership($boundaryId, $projectId, $nodeId, $scanId);
    }

    /**
     * Swap a project's incremental-reuse cache wholesale.
     *
     * Replaced rather than merged: a stale entry would let the next scan reuse facts
     * for a file it should have re-analysed.
     *
     * @param list<\Knossos\Reconciliation\ContributionCacheEntry> $entries
     */
    public function replaceContributionCache(string $projectId, array $entries): void
    {
        $this->writer->replaceContributionCache($projectId, $entries);
    }

    /**
     * Edges leaving a node.
     *
     * @return list<array<string, mixed>>
     */
    public function outgoing(string $projectId, string $nodeId, ?string $kind = null, int $limit = 100): array
    {
        return $this->reader->outgoing($projectId, $nodeId, $kind, $limit);
    }

    /**
     * Edges arriving at a node.
     *
     * @return list<array<string, mixed>>
     */
    public function incoming(string $projectId, string $nodeId, ?string $kind = null, int $limit = 100): array
    {
        return $this->reader->incoming($projectId, $nodeId, $kind, $limit);
    }
}
