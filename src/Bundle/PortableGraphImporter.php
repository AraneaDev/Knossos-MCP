<?php

declare(strict_types=1);

namespace Knossos\Bundle;

use InvalidArgumentException;
use PDO;

final readonly class PortableGraphImporter
{
    public function __construct(private PDO $pdo) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $manifest
     * @param array{files: array<string, string>, nodes: array<string, string>, boundaries: array<string, string>} $maps
     */
    public function import(array $payload, array $manifest, array $maps, string $projectId, string $scanId, string $checksum, ?string $name): void
    {
        $scan = $this->object($payload['scan'] ?? null, 'scan');
        $finishedAt = is_string($scan['finished_at'] ?? null) ? $scan['finished_at'] : '1970-01-01T00:00:00+00:00';
        $projectName = $this->text($name ?? ($payload['project_name'] ?? 'Imported graph'));
        $this->insertProjectAndScan($manifest, $scan, $projectId, $scanId, $checksum, $projectName, $finishedAt);
        $this->insertFiles($payload['files'], $maps, $projectId, $scanId);
        $this->insertNodes($payload['nodes'], $maps, $projectId, $scanId);
        $this->insertEdges($payload['edges'], $maps, $projectId, $scanId);
        $this->insertBoundaries($payload['boundaries'], $maps, $projectId, $scanId);
        $this->insertMemberships($payload['memberships'], $maps, $projectId, $scanId);
        $this->insertClassifications($payload['classifications'], $maps, $projectId, $scanId);
        $this->insertDiagnostics($payload['diagnostics'], $maps, $projectId, $scanId);
        $statement = $this->pdo->prepare('UPDATE projects SET active_scan_id = :scan WHERE id = :project');
        $statement->execute(['scan' => $scanId, 'project' => $projectId]);
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $scan */
    private function insertProjectAndScan(array $manifest, array $scan, string $projectId, string $scanId, string $checksum, string $projectName, string $finishedAt): void
    {
        $this->insert('projects', ['id' => $projectId, 'name' => $projectName, 'root_realpath' => 'bundle://' . substr($checksum, 0, 32), 'config_json' => GraphBundleDecoder::encodeCanonical(['imported' => true, 'redaction' => $manifest['redaction'] ?? 'unknown']), 'active_scan_id' => null, 'created_at' => $finishedAt, 'updated_at' => $finishedAt]);
        $this->insert('scans', ['id' => $scanId, 'project_id' => $projectId, 'mode' => 'full', 'status' => 'complete', 'scanner_set_hash' => (string) ($scan['scanner_set_hash'] ?? 'bundle'), 'started_at' => $finishedAt, 'finished_at' => $finishedAt]);
    }

    /** @param list<mixed> $rows @param array{files: array<string, string>, nodes: array<string, string>, boundaries: array<string, string>} $maps */
    private function insertFiles(array $rows, array $maps, string $projectId, string $scanId): void
    {
        foreach ($rows as $row) {
            $item = $this->object($row, 'file');
            $this->insert('files', ['id' => $this->mappedRequired($maps['files'], $item['id'] ?? null), 'project_id' => $projectId, 'relative_path' => $this->relativePath($item['relative_path'] ?? null), 'content_hash' => (string) ($item['content_hash'] ?? hash('sha256', '')), 'size' => $this->nonNegative($item['size'] ?? null), 'line_count' => $this->nonNegative($item['line_count'] ?? 0), 'mtime' => 0, 'language' => $this->text($item['language'] ?? null), 'scanner_version' => $this->text($item['scanner_version'] ?? null), 'last_scan_id' => $scanId]);
        }
    }

    /** @param list<mixed> $rows @param array{files: array<string, string>, nodes: array<string, string>, boundaries: array<string, string>} $maps */
    private function insertNodes(array $rows, array $maps, string $projectId, string $scanId): void
    {
        $parents = [];
        foreach ($rows as $row) {
            $item = $this->object($row, 'node');
            $nodeId = $this->mappedRequired($maps['nodes'], $item['id'] ?? null);
            $parents[$nodeId] = $this->mappedNullable($maps['nodes'], $item['parent_id'] ?? null);
            $this->insert('nodes', ['id' => $nodeId, 'project_id' => $projectId, 'kind' => $this->text($item['kind'] ?? null), 'canonical_name' => $this->text($item['canonical_name'] ?? null), 'display_name' => $this->text($item['display_name'] ?? null), 'parent_id' => null, 'file_id' => $this->mappedNullable($maps['files'], $item['file_id'] ?? null), 'start_line' => $item['start_line'] ?? null, 'end_line' => $item['end_line'] ?? null, 'origin' => $this->text($item['origin'] ?? null), 'confidence' => $this->confidence($item['confidence'] ?? null), 'attributes_json' => $this->jsonObject($item['attributes_json'] ?? '{}'), 'owner_key' => $this->text($item['owner_key'] ?? null), 'last_scan_id' => $scanId]);
        }
        $statement = $this->pdo->prepare('UPDATE nodes SET parent_id = :parent WHERE id = :id');
        foreach ($parents as $nodeId => $parentId) {
            if ($parentId !== null) {
                $statement->execute(['parent' => $parentId, 'id' => $nodeId]);
            }
        }
    }

    /** @param list<mixed> $rows @param array{files: array<string, string>, nodes: array<string, string>, boundaries: array<string, string>} $maps */
    private function insertEdges(array $rows, array $maps, string $projectId, string $scanId): void
    {
        foreach ($rows as $row) {
            $item = $this->object($row, 'edge');
            $this->insert('edges', ['id' => BundleIdMapBuilder::mappedId($projectId, 'edges', $this->text($item['id'] ?? null)), 'project_id' => $projectId, 'kind' => $this->text($item['kind'] ?? null), 'source_id' => $this->mappedRequired($maps['nodes'], $item['source_id'] ?? null), 'target_id' => $this->mappedRequired($maps['nodes'], $item['target_id'] ?? null), 'file_id' => $this->mappedNullable($maps['files'], $item['file_id'] ?? null), 'start_line' => $item['start_line'] ?? null, 'end_line' => $item['end_line'] ?? null, 'origin' => $this->text($item['origin'] ?? null), 'confidence' => $this->confidence($item['confidence'] ?? null), 'attributes_json' => $this->jsonObject($item['attributes_json'] ?? '{}'), 'owner_key' => $this->text($item['owner_key'] ?? null), 'last_scan_id' => $scanId]);
        }
    }

    /** @param list<mixed> $rows @param array{files: array<string, string>, nodes: array<string, string>, boundaries: array<string, string>} $maps */
    private function insertBoundaries(array $rows, array $maps, string $projectId, string $scanId): void
    {
        foreach ($rows as $row) {
            $item = $this->object($row, 'boundary');
            $source = in_array($item['source'] ?? null, ['explicit', 'inferred'], true) ? $item['source'] : throw new InvalidArgumentException('Boundary source is invalid.');
            $this->insert('boundaries', ['id' => $this->mappedRequired($maps['boundaries'], $item['id'] ?? null), 'project_id' => $projectId, 'name' => $this->text($item['name'] ?? null), 'matcher_json' => $this->jsonObject($item['matcher_json'] ?? '{}'), 'source' => $source, 'last_scan_id' => $scanId]);
        }
    }

    /** @param list<mixed> $rows @param array{files: array<string, string>, nodes: array<string, string>, boundaries: array<string, string>} $maps */
    private function insertMemberships(array $rows, array $maps, string $projectId, string $scanId): void
    {
        foreach ($rows as $row) {
            $item = $this->object($row, 'membership');
            $this->insert('boundary_memberships', ['boundary_id' => $this->mappedRequired($maps['boundaries'], $item['boundary_id'] ?? null), 'project_id' => $projectId, 'node_id' => $this->mappedRequired($maps['nodes'], $item['node_id'] ?? null), 'last_scan_id' => $scanId]);
        }
    }

    /** @param list<mixed> $rows @param array{files: array<string, string>, nodes: array<string, string>, boundaries: array<string, string>} $maps */
    private function insertClassifications(array $rows, array $maps, string $projectId, string $scanId): void
    {
        foreach ($rows as $row) {
            $item = $this->object($row, 'classification');
            $this->insert('classifications', ['id' => BundleIdMapBuilder::mappedId($projectId, 'classifications', $this->text($item['id'] ?? null)), 'project_id' => $projectId, 'node_id' => $this->mappedRequired($maps['nodes'], $item['node_id'] ?? null), 'role' => $this->text($item['role'] ?? null), 'origin' => $this->text($item['origin'] ?? null), 'confidence' => $this->confidence($item['confidence'] ?? null), 'rule_id' => $this->text($item['rule_id'] ?? null), 'file_id' => $this->mappedNullable($maps['files'], $item['file_id'] ?? null), 'start_line' => $item['start_line'] ?? null, 'end_line' => $item['end_line'] ?? null, 'attributes_json' => $this->jsonObject($item['attributes_json'] ?? '{}'), 'last_scan_id' => $scanId]);
        }
    }

    /** @param list<mixed> $rows @param array{files: array<string, string>, nodes: array<string, string>, boundaries: array<string, string>} $maps */
    private function insertDiagnostics(array $rows, array $maps, string $projectId, string $scanId): void
    {
        foreach ($rows as $row) {
            $item = $this->object($row, 'diagnostic');
            $severity = in_array($item['severity'] ?? null, ['info', 'warning', 'error'], true) ? $item['severity'] : throw new InvalidArgumentException('Diagnostic severity is invalid.');
            $this->insert('diagnostics', ['id' => BundleIdMapBuilder::mappedId($projectId, 'diagnostics', $this->text($item['id'] ?? null)), 'project_id' => $projectId, 'scan_id' => $scanId, 'file_id' => $this->mappedNullable($maps['files'], $item['file_id'] ?? null), 'severity' => $severity, 'code' => $this->text($item['code'] ?? null), 'message' => $this->text($item['message'] ?? null), 'start_line' => $item['start_line'] ?? null, 'end_line' => $item['end_line'] ?? null, 'owner_key' => $this->text($item['owner_key'] ?? null)]);
        }
    }

    /** @param array<string, mixed> $values */
    private function insert(string $table, array $values): void
    {
        $columns = array_keys($values);
        $statement = $this->pdo->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns))));
        $statement->execute($values);
    }

    /** @param array<string, string> $map */
    private function mappedRequired(array $map, mixed $old): string
    {
        if (!is_string($old) || !isset($map[$old])) {
            throw new InvalidArgumentException('Bundle contains a dangling reference.');
        }
        return $map[$old];
    }

    /** @param array<string, string> $map */
    private function mappedNullable(array $map, mixed $old): ?string
    {
        return $old === null ? null : $this->mappedRequired($map, $old);
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $name): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Bundle ' . $name . ' must be an object.');
        }
        return $value;
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 1_000_000) {
            throw new InvalidArgumentException('Bundle contains invalid text.');
        }
        return $value;
    }

    private function relativePath(mixed $value): string
    {
        $path = $this->text($value);
        if (str_starts_with($path, '/') || str_contains($path, "\0") || in_array('..', explode('/', str_replace('\\', '/', $path)), true)) {
            throw new InvalidArgumentException('Bundle contains an unsafe file path.');
        }
        return $path;
    }

    private function nonNegative(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Bundle contains an invalid non-negative integer.');
        }
        return $value;
    }

    private function confidence(mixed $value): string
    {
        return in_array($value, ['certain', 'probable', 'possible'], true) ? $value : throw new InvalidArgumentException('Bundle confidence is invalid.');
    }

    private function jsonObject(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 1_000_000) {
            throw new InvalidArgumentException('Bundle JSON attributes are invalid.');
        }
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new InvalidArgumentException('Bundle JSON attributes must be objects.');
        }
        return GraphBundleDecoder::encodeCanonical($decoded);
    }
}
