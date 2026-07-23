<?php

declare(strict_types=1);

namespace Knossos\Bundle;

use InvalidArgumentException;
use Knossos\Query\ResultEnvelope;
use PDO;
use Throwable;

final readonly class GraphBundleService
{
    public function __construct(private PDO $pdo) {}

    public function export(string $projectId, string $redaction = 'none'): string
    {
        if (!in_array($redaction, ['none', 'paths', 'strict'], true)) {
            throw new InvalidArgumentException('Bundle redaction must be none, paths, or strict.');
        }

        // Read all seven tables inside a single deferred read transaction so a
        // concurrent reconcile commit cannot land between the snapshot SELECTs
        // and produce a torn, self-checksummed bundle that fails on import.
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $compressed = $this->readAndEncode($projectId, $redaction);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $compressed;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function readAndEncode(string $projectId, string $redaction): string
    {
        $project = $this->one('SELECT id, name, active_scan_id FROM projects WHERE id = :id', ['id' => $projectId]);
        if ($project === null || !is_string($project['active_scan_id'])) {
            throw new InvalidArgumentException('Project has no active snapshot to export.');
        }
        $scanId = $project['active_scan_id'];
        $scan = $this->one('SELECT scanner_set_hash, finished_at FROM scans WHERE id = :id AND status = :status', ['id' => $scanId, 'status' => 'complete']);
        if ($scan === null) {
            throw new InvalidArgumentException('Active scan is unavailable or incomplete.');
        }
        $tables = [
            'files' => $this->all('SELECT id, relative_path, content_hash, size, line_count, language, scanner_version FROM files WHERE project_id = :project ORDER BY id', $projectId),
            'nodes' => $this->all('SELECT id, language, kind, canonical_name, display_name, parent_id, file_id, start_line, end_line, origin, confidence, attributes_json, owner_key FROM nodes WHERE project_id = :project ORDER BY id', $projectId),
            'edges' => $this->all('SELECT id, kind, source_id, target_id, file_id, start_line, end_line, origin, confidence, attributes_json, owner_key FROM edges WHERE project_id = :project ORDER BY id', $projectId),
            'classifications' => $this->all('SELECT id, node_id, role, origin, confidence, rule_id, file_id, start_line, end_line, attributes_json FROM classifications WHERE project_id = :project ORDER BY id', $projectId),
            'boundaries' => $this->all('SELECT id, name, matcher_json, source FROM boundaries WHERE project_id = :project ORDER BY id', $projectId),
            'memberships' => $this->all('SELECT boundary_id, node_id FROM boundary_memberships WHERE project_id = :project ORDER BY boundary_id, node_id', $projectId),
            'diagnostics' => $this->all('SELECT id, file_id, severity, code, message, start_line, end_line, owner_key FROM diagnostics WHERE project_id = :project AND scan_id = :scan ORDER BY id', $projectId, ['scan' => $scanId]),
        ];
        $factCount = array_sum(array_map('count', $tables));
        if ($factCount > GraphBundleDecoder::MAX_FACTS) {
            throw new InvalidArgumentException('Bundle fact limit exceeded.');
        }
        foreach ($tables['files'] as &$file) {
            $original = $file['relative_path'];
            if ($redaction !== 'none' && is_string($original)) {
                $extension = pathinfo($original, PATHINFO_EXTENSION);
                $file['relative_path'] = 'redacted/' . substr(hash('sha256', $original), 0, 24) . ($extension === '' ? '' : '.' . strtolower($extension));
            }
        }
        unset($file);
        if ($redaction === 'strict') {
            foreach (['nodes', 'edges', 'classifications'] as $table) {
                foreach ($tables[$table] as &$row) {
                    if (isset($row['attributes_json'])) {
                        $row['attributes_json'] = '{}';
                    }
                    $row['owner_key'] = isset($row['owner_key']) ? 'redacted:' . substr(hash('sha256', (string) $row['owner_key']), 0, 24) : null;
                }
                unset($row);
            }
            foreach ($tables['diagnostics'] as &$diagnostic) {
                $diagnostic['message'] = '[redacted]';
                $diagnostic['owner_key'] = 'redacted:' . substr(hash('sha256', (string) $diagnostic['owner_key']), 0, 24);
            }
            unset($diagnostic);
        }
        $payload = ['project_name' => $project['name'], 'scan' => ['scanner_set_hash' => $scan['scanner_set_hash'], 'finished_at' => $scan['finished_at']], ...$tables];
        $payloadJson = GraphBundleDecoder::encodeCanonical($payload);
        $manifest = [
            'format' => GraphBundleDecoder::FORMAT,
            'version' => GraphBundleDecoder::VERSION,
            'redaction' => $redaction,
            'checksum' => 'sha256:' . hash('sha256', $payloadJson),
            'uncompressed_bytes' => strlen($payloadJson),
            'fact_count' => $factCount,
            'created_at' => $scan['finished_at'],
        ];
        $json = GraphBundleDecoder::encodeCanonical(['manifest' => $manifest, 'payload' => $payload]);
        if (strlen($json) > GraphBundleDecoder::MAX_UNCOMPRESSED_BYTES) {
            throw new InvalidArgumentException('Bundle uncompressed byte limit exceeded.');
        }
        $compressed = gzencode($json, 9, ZLIB_ENCODING_GZIP);
        if (!is_string($compressed) || strlen($compressed) > GraphBundleDecoder::MAX_COMPRESSED_BYTES) {
            throw new InvalidArgumentException('Bundle compression failed or compressed byte limit exceeded.');
        }
        return $compressed;
    }

    public function import(string $compressed, ?string $name = null): ResultEnvelope
    {
        $bundle = (new GraphBundleDecoder())->decodeAndValidate($compressed);
        $manifest = $bundle['manifest'];
        $payload = $bundle['payload'];
        $factCount = $bundle['fact_count'];
        $checksum = $bundle['checksum'];
        $projectId = 'bundle:' . substr($checksum, 0, 32);
        $scanId = 'bundle-scan:' . substr($checksum, 0, 32);
        $maps = (new BundleIdMapBuilder())->build($projectId, $payload);

        // BEGIN IMMEDIATE acquires SQLite's single writer slot before the
        // duplicate-project check so two concurrent imports of the same bundle
        // surface the clean "already imported" error instead of one racing past
        // the check and hitting a raw UNIQUE constraint violation. inTransaction()
        // does not track a manually issued BEGIN, so ownership is tracked here.
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($this->one('SELECT id FROM projects WHERE id = :id', ['id' => $projectId]) !== null) {
                throw new InvalidArgumentException('Bundle is already imported.');
            }
            (new PortableGraphImporter($this->pdo))->import($payload, $manifest, $maps, $projectId, $scanId, $checksum, $name);
            $this->pdo->exec('COMMIT');
        } catch (Throwable $error) {
            // Any failure before COMMIT leaves the manual transaction open;
            // inTransaction() does not track a BEGIN issued via exec(), so roll
            // back unconditionally to release SQLite's writer slot.
            $this->pdo->exec('ROLLBACK');
            throw $error;
        }
        return new ResultEnvelope($projectId, $scanId, sprintf('Imported %d portable graph facts.', $factCount), ['fact_count' => $factCount, 'redaction' => $manifest['redaction'] ?? 'unknown', 'root_imported' => false]);
    }

    /** @param array<string, mixed> $params @return array<string, mixed>|null */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $extra @return list<array<string, mixed>> */
    private function all(string $sql, string $projectId, array $extra = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['project' => $projectId, ...$extra]);
        return $statement->fetchAll();
    }
}
