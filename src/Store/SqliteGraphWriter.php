<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;
use Knossos\Reconciliation\ContributionCacheEntry;

/**
 * Every row a scan writes into the graph, one statement per table.
 *
 * Statements come from the shared cache because a scan replays each of them
 * thousands of times. Nothing here opens a transaction: the reconciler owns the
 * transaction a scan's writes run inside.
 */
final class SqliteGraphWriter
{
    public function __construct(private readonly SqliteStatementCache $statements) {}

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
        int $lineCount,
    ): void {
        $statement = $this->statements->prepare(
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
        $statement = $this->statements->prepare(
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
            'attributes' => SqliteValues::json($attributes),
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
        $statement = $this->statements->prepare(
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
            'attributes' => SqliteValues::json($attributes),
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
                array_push($values, $node['id'], $projectId, $node['language'], $node['kind'], $node['canonical_name'], $node['display_name'], null, $node['file_id'], $node['start_line'], $node['end_line'], $node['origin'], $node['confidence'], SqliteValues::json($node['attributes']), $node['owner_key'], $scanId);
            }
            $this->statements->prepare($sql)->execute($values);
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
                array_push($values, $edge['id'], $projectId, $edge['kind'], $edge['source_id'], $edge['target_id'], $edge['file_id'], $edge['start_line'], $edge['end_line'], $edge['origin'], $edge['confidence'], SqliteValues::json($edge['attributes']), $edge['owner_key'], $scanId);
            }
            $this->statements->prepare($sql)->execute($values);
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
            $this->statements->prepare($sql)->execute($values);
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
                array_push($values, $classification['id'], $projectId, $classification['node_id'], $classification['role'], $classification['origin'], $classification['confidence'], $classification['rule_id'], $classification['file_id'], $classification['start_line'], $classification['end_line'], SqliteValues::json($classification['attributes']), $scanId);
            }
            $this->statements->prepare($sql)->execute($values);
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
            $this->statements->prepare($sql)->execute($values);
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
        $statement = $this->statements->prepare(
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
        $statement = $this->statements->prepare(
            'INSERT INTO classifications(id, project_id, node_id, role, origin, confidence, rule_id, file_id, ' .
            'start_line, end_line, attributes_json, last_scan_id) VALUES (:id, :project, :node, :role, :origin, ' .
            ':confidence, :rule, :file, :start, :end, :attributes, :scan)',
        );
        $statement->execute([
            'id' => $id, 'project' => $projectId, 'node' => $nodeId, 'role' => $role,
            'origin' => $origin, 'confidence' => $confidence, 'rule' => $ruleId, 'file' => $fileId,
            'start' => $startLine, 'end' => $endLine, 'attributes' => SqliteValues::json($attributes), 'scan' => $scanId,
        ]);
    }

    /**
     * Persist a boundary, declared or inferred.
     *
     * @param array<string, mixed> $matcher the path/name rules defining membership
     */
    public function saveBoundary(string $id, string $projectId, string $name, array $matcher, string $source, string $scanId): void
    {
        $statement = $this->statements->prepare(
            'INSERT INTO boundaries(id, project_id, name, matcher_json, source, last_scan_id) ' .
            'VALUES (:id, :project, :name, :matcher, :source, :scan) ' .
            'ON CONFLICT(id) DO UPDATE SET name = excluded.name, matcher_json = excluded.matcher_json, source = excluded.source, last_scan_id = excluded.last_scan_id ' .
            'WHERE boundaries.name IS NOT excluded.name OR boundaries.matcher_json IS NOT excluded.matcher_json OR boundaries.source IS NOT excluded.source',
        );
        $statement->execute([
            'id' => $id, 'project' => $projectId, 'name' => $name, 'matcher' => SqliteValues::json($matcher),
            'source' => $source, 'scan' => $scanId,
        ]);
    }

    /** Attach a node to a boundary. Separate from the boundary itself so membership can be recomputed alone. */
    public function saveBoundaryMembership(string $boundaryId, string $projectId, string $nodeId, string $scanId): void
    {
        $statement = $this->statements->prepare(
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
        $delete = $this->statements->pdo()->prepare('DELETE FROM contribution_cache WHERE project_id = :project');
        $delete->execute(['project' => $projectId]);
        $insert = $this->statements->prepare(
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
                'updated' => SqliteValues::now(),
            ]);
        }
    }
}
