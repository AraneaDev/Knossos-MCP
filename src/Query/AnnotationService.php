<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;

/**
 * Agent write-backs: durable annotations keyed by canonical name so they
 * survive rescans (node ids do not). Writes follow the repo's
 * preview-unless-execute convention.
 */
final readonly class AnnotationService extends AbstractArchitectureQueryService
{
    public const KINDS = ['intended_boundary', 'confirmed_dead', 'false_positive', 'note'];

    public function annotateComponent(string $projectId, string $component, string $kind, string $value = '', bool $remove = false, bool $execute = false): ResultEnvelope
    {
        $project = $this->project($projectId);
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('kind must be one of: ' . implode(', ', self::KINDS) . '.');
        }
        if (trim($component) === '') {
            throw new InvalidArgumentException('component must not be empty.');
        }
        if (strlen($value) > 2000) {
            throw new InvalidArgumentException('value must not exceed 2000 bytes.');
        }
        $matches = $this->resolve($projectId, $component);
        if (count($matches) > 1) {
            $names = array_slice(array_column($matches, 'canonical_name'), 0, 5);
            throw new InvalidArgumentException('Component is ambiguous; use a canonical name. Candidates: ' . implode(', ', $names) . '.');
        }
        $canonical = $matches === [] ? $component : (string) $matches[0]['canonical_name'];
        $warnings = $matches === []
            ? ['Component not found in the current graph; the annotation is kept anyway (dynamic or upcoming symbol?).']
            : [];

        $existing = $this->fetch($projectId, $canonical, $kind);
        $action = $remove ? 'remove' : 'upsert';
        if (!$execute) {
            return new ResultEnvelope(
                $projectId,
                $project['active_scan_id'],
                sprintf('Preview: would %s %s annotation on %s.', $action, $kind, $canonical),
                ['component' => $canonical, 'kind' => $kind, 'action' => $action, 'executed' => false, 'previous' => $existing, 'annotation' => $remove ? null : ['value' => $value]],
                [],
                [...$warnings, 'Set execute=true to apply the change.'],
            );
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if ($remove) {
            $statement = $this->pdo->prepare('DELETE FROM annotations WHERE project_id = :project AND canonical_name = :name AND kind = :kind');
            $statement->execute(['project' => $projectId, 'name' => $canonical, 'kind' => $kind]);
            $annotation = null;
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO annotations(project_id, canonical_name, kind, value, author, created_at, updated_at) ' .
                "VALUES (:project, :name, :kind, :value, 'agent', :now, :now) " .
                'ON CONFLICT(project_id, canonical_name, kind) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            );
            $statement->execute(['project' => $projectId, 'name' => $canonical, 'kind' => $kind, 'value' => $value, 'now' => $now]);
            $annotation = $this->fetch($projectId, $canonical, $kind);
        }

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('%s %s annotation on %s.', $remove ? 'Removed' : 'Recorded', $kind, $canonical),
            ['component' => $canonical, 'kind' => $kind, 'action' => $action, 'executed' => true, 'previous' => $existing, 'annotation' => $annotation],
            [],
            $warnings,
        );
    }

    public function listAnnotations(string $projectId, ?string $component = null, ?string $kind = null, int $limit = 100, int $offset = 0): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::assertLimit($limit);
        if ($offset < 0 || $offset > 100_000) {
            throw new InvalidArgumentException('offset must be between 0 and 100000.');
        }
        if ($kind !== null && !in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('kind must be one of: ' . implode(', ', self::KINDS) . '.');
        }
        $sql = 'SELECT canonical_name, kind, value, author, created_at, updated_at FROM annotations WHERE project_id = :project';
        $parameters = ['project' => $projectId];
        if ($component !== null) {
            $sql .= ' AND canonical_name = :name';
            $parameters['name'] = $component;
        }
        if ($kind !== null) {
            $sql .= ' AND kind = :kind';
            $parameters['kind'] = $kind;
        }
        $sql .= ' ORDER BY canonical_name, kind LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $key => $parameterValue) {
            $statement->bindValue(':' . $key, $parameterValue);
        }
        $statement->bindValue(':limit', $limit + 1, \PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Found %d annotation%s.', count($rows), count($rows) === 1 ? '' : 's'),
            ['annotations' => $rows, 'pagination' => ['offset' => $offset, 'next_offset' => $truncated ? $offset + $limit : null]],
            [],
            [],
            $truncated,
        );
    }

    /** @return array<string, mixed>|null */
    private function fetch(string $projectId, string $canonical, string $kind): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT canonical_name, kind, value, author, created_at, updated_at FROM annotations ' .
            'WHERE project_id = :project AND canonical_name = :name AND kind = :kind',
        );
        $statement->execute(['project' => $projectId, 'name' => $canonical, 'kind' => $kind]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }
}
