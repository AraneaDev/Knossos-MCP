<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Result\ResultEnvelope;

/**
 * Agent write-backs: durable annotations keyed by canonical name so they
 * survive rescans (node ids do not). Writes follow the repo's
 * preview-unless-execute convention.
 */
final readonly class AnnotationService extends AbstractArchitectureQueryService
{
    public const KINDS = ['intended_boundary', 'confirmed_dead', 'false_positive', 'intentional', 'note'];
    /** Record or replace a durable annotation, previewing unless executing. */
    public function upsertAnnotation(string $projectId, string $component, string $kind, string $value = '', bool $execute = false): ResultEnvelope
    {
        $target = $this->target($projectId, $component, $kind, $value);
        if (!$execute) {
            return $this->preview($projectId, $target, $kind, 'upsert', ['value' => $value]);
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO annotations(project_id, canonical_name, kind, value, author, created_at, updated_at) ' .
            "VALUES (:project, :name, :kind, :value, 'agent', :now, :now) " .
            'ON CONFLICT(project_id, canonical_name, kind) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
        );
        $statement->execute(['project' => $projectId, 'name' => $target['canonical'], 'kind' => $kind, 'value' => $value, 'now' => gmdate('Y-m-d\TH:i:s\Z')]);

        return $this->executed($projectId, $target, $kind, 'upsert', 'Recorded', $this->fetch($projectId, $target['canonical'], $kind));
    }

    /**
     * Remove a durable annotation, previewing unless executing.
     *
     * A value a caller passes along is checked as an upsert's would be and
     * otherwise ignored: the write tools take one value argument for both
     * actions, and an over-long one is refused whichever action it came with.
     */
    public function removeAnnotation(string $projectId, string $component, string $kind, string $value = '', bool $execute = false): ResultEnvelope
    {
        $target = $this->target($projectId, $component, $kind, $value);
        if (!$execute) {
            return $this->preview($projectId, $target, $kind, 'remove', null);
        }
        $statement = $this->pdo->prepare('DELETE FROM annotations WHERE project_id = :project AND canonical_name = :name AND kind = :kind');
        $statement->execute(['project' => $projectId, 'name' => $target['canonical'], 'kind' => $kind]);

        return $this->executed($projectId, $target, $kind, 'remove', 'Removed', null);
    }

    /**
     * Check a write's arguments and resolve the component it names: the
     * project, the canonical name the annotation is keyed by, the warnings
     * about that name, and the annotation already stored under it.
     *
     * @return array{project: array<string, mixed>, canonical: string, warnings: list<string>, existing: ?array<string, mixed>}
     */
    private function target(string $projectId, string $component, string $kind, string $value): array
    {
        $project = $this->project($projectId);
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('kind must be one of: ' . implode(', ', self::KINDS) . '.');
        }
        if (trim($component) === '') {
            throw new InvalidArgumentException('component must not be empty.');
        }
        // Characters, as the schema's maxLength counts them, not bytes.
        if (mb_strlen($value) > 2000) {
            throw new InvalidArgumentException('value must not exceed 2000 characters.');
        }
        // Exact matches only: a prefix match once turned `App\Invoice` into
        // `App\InvoiceService` and wrote the annotation on the wrong component.
        $matches = $this->resolveExact($projectId, $component);
        if (count($matches) > 1) {
            $names = array_slice(array_column($matches, 'canonical_name'), 0, 5);
            throw new InvalidArgumentException('Component is ambiguous; use a canonical name. Candidates: ' . implode(', ', $names) . '.');
        }
        $canonical = $matches === [] ? $component : (string) $matches[0]['canonical_name'];
        $warnings = [];
        if ($matches === []) {
            $warning = 'Component not found in the current graph; the annotation is kept anyway (dynamic or upcoming symbol?).';
            $near = array_slice(array_column($this->resolve($projectId, $component), 'canonical_name'), 0, 5);
            $warnings[] = $near === [] ? $warning : $warning . ' Did you mean: ' . implode(', ', $near) . '?';
        }

        return ['project' => $project, 'canonical' => $canonical, 'warnings' => $warnings, 'existing' => $this->fetch($projectId, $canonical, $kind)];
    }

    /**
     * What a write would do, without doing it.
     *
     * @param array{project: array<string, mixed>, canonical: string, warnings: list<string>, existing: ?array<string, mixed>} $target
     * @param array<string, mixed>|null $annotation the annotation the write would leave
     */
    private function preview(string $projectId, array $target, string $kind, string $action, ?array $annotation): ResultEnvelope
    {
        return new ResultEnvelope(
            $projectId,
            $target['project']['active_scan_id'],
            sprintf('Preview: would %s %s annotation on %s.', $action, $kind, $target['canonical']),
            ['component' => $target['canonical'], 'kind' => $kind, 'action' => $action, 'executed' => false, 'previous' => $target['existing'], 'annotation' => $annotation],
            [],
            [...$target['warnings'], 'Set execute=true to apply the change.'],
        );
    }

    /**
     * What a write did.
     *
     * @param array{project: array<string, mixed>, canonical: string, warnings: list<string>, existing: ?array<string, mixed>} $target
     * @param array<string, mixed>|null $annotation the annotation stored now
     */
    private function executed(string $projectId, array $target, string $kind, string $action, string $verb, ?array $annotation): ResultEnvelope
    {
        return new ResultEnvelope(
            $projectId,
            $target['project']['active_scan_id'],
            sprintf('%s %s annotation on %s.', $verb, $kind, $target['canonical']),
            ['component' => $target['canonical'], 'kind' => $kind, 'action' => $action, 'executed' => true, 'previous' => $target['existing'], 'annotation' => $annotation],
            [],
            $target['warnings'],
        );
    }

    /** Annotations recorded for a project, optionally filtered. */

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

    /**
     * The stored annotation rows behind a listing.
     *
     * @return array<string, mixed>|null
     */
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
