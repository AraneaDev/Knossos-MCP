<?php

declare(strict_types=1);

namespace Knossos\Query;

use PDO;

/**
 * One file as the architecture pane shows it: who depends on it, and what
 * it declares. Addressed by the file's own path; the project is the one that
 * contains it, resolved through {@see ProjectPathResolver} as the dashboard
 * resolves its own, so the pane's file view and its overview always come from
 * the same graph.
 *
 * Read-only. Every way there can be nothing is a status: `unscanned` (no
 * project with an active scan contains the path) and `not-found` (the graph
 * holds no such file: never scanned, ignored, or deleted before the snapshot).
 *
 * `dependents` counts the other files with a component that depends on one
 * of this file's, over the same relationships the fan-in map counts and
 * leaving out the same `external_*` stand-ins ({@see FileFanInQuery}), names
 * the boundaries they sit in, and lists the most connected few with how many
 * relationships run from each and its own boundary label. `components` counts
 * what the file declares (never the module node that stands for the file
 * itself, nor an external symbol it merely names first)
 * and lists the most used few with how many components in other files use
 * each. Both lists are bounded; `truncated` says the list holds fewer than
 * the count, never that the count is a floor: both counts are exact.
 */
final readonly class FileDetailService
{
    /** Dependent files listed with their relationship counts. */
    private const DEPENDENTS = 10;

    /** Components of the file listed with their use counts. */
    private const COMPONENTS = 12;

    /** @param PDO $pdo an existing, migrated graph database */
    public function __construct(private PDO $pdo) {}

    /**
     * The detail of the file at `$path`, in the project that contains it.
     *
     * @return array<string, mixed>
     */
    public function detail(string $path): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = ['status' => 'unscanned', 'path' => $absolute, 'project_id' => null, 'snapshot_id' => null, 'file' => null];
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        // A project row without an active scan has no graph to look in.
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return $envelope;
        }
        $id = (string) $project['id'];
        $envelope['project_id'] = $id;
        $envelope['snapshot_id'] = $project['active_scan_id'];
        $root = rtrim((string) $project['root_realpath'], '/');
        $relative = str_starts_with($absolute, $root . '/') ? substr($absolute, strlen($root) + 1) : $absolute;
        $statement = $this->pdo->prepare('SELECT id, language, line_count FROM files WHERE project_id = :project AND relative_path = :path');
        $statement->execute(['project' => $id, 'path' => $relative]);
        $file = $statement->fetch(PDO::FETCH_ASSOC);
        if ($file === false) {
            return ['status' => 'not-found'] + $envelope;
        }
        $labels = BoundaryLabels::load($this->pdo, $id);
        $fanIn = (new FileFanInQuery($this->pdo))->forPaths($id, [$relative], 0)[$relative];
        $dependents = $this->dependents((string) $file['id']);
        $boundaries = $labels->forFiles($id, array_column($dependents, 'path'));
        $components = $this->components((string) $file['id']);
        $componentLabels = $labels->forNodes(array_column($components, 'id'));
        $envelope['status'] = 'ok';
        $envelope['file'] = [
            'path' => $relative,
            'language' => (string) $file['language'],
            'lines' => isset($file['line_count']) ? (int) $file['line_count'] : null,
            'boundary' => $fanIn['boundary'],
            'dependents' => [
                'count' => $fanIn['dependent_files'],
                'truncated' => $fanIn['dependent_files'] > count($dependents),
                'boundaries' => $fanIn['boundaries'],
                'items' => array_map(static fn(array $d): array => $d + ['boundary' => $boundaries[$d['path']] ?? null], $dependents),
            ],
            'components' => [
                'count' => $this->componentCount((string) $file['id']),
                'items' => array_map(static fn(array $c): array => [
                    'name' => $c['name'],
                    'canonical_name' => $c['canonical_name'],
                    'kind' => $c['kind'],
                    'line' => $c['line'],
                    'boundary' => $componentLabels[$c['id']] ?? null,
                    'used_by' => $c['used_by'],
                ], $components),
            ],
        ];
        $envelope['file']['components']['truncated'] = $envelope['file']['components']['count'] > count($components);
        return $envelope;
    }

    /**
     * The files most connected to this one by the impact relationships, each
     * with how many run from it, most first.
     *
     * @return list<array{path: string, edges: int}>
     */
    private function dependents(string $fileId): array
    {
        $kinds = implode(',', array_fill(0, count(AbstractArchitectureQueryService::IMPACT_EDGE_KINDS), '?'));
        $statement = $this->pdo->prepare(<<<SQL
            SELECT sf.relative_path AS path, COUNT(*) AS edges
              FROM edges e
              JOIN nodes tn ON tn.id = e.target_id
              JOIN nodes sn ON sn.id = e.source_id
              JOIN files sf ON sf.id = sn.file_id
             WHERE tn.file_id = ? AND sf.id <> tn.file_id AND e.kind IN ($kinds) AND tn.kind NOT LIKE 'external\_%' ESCAPE '\'
             GROUP BY sf.id
             ORDER BY edges DESC, path ASC
             LIMIT ?
            SQL);
        $statement->bindValue(1, $fileId);
        foreach (AbstractArchitectureQueryService::IMPACT_EDGE_KINDS as $i => $kind) {
            $statement->bindValue($i + 2, $kind);
        }
        $statement->bindValue(count(AbstractArchitectureQueryService::IMPACT_EDGE_KINDS) + 2, self::DEPENDENTS, PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn(array $row): array => ['path' => (string) $row['path'], 'edges' => (int) $row['edges']], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The file's most used components: how many components in other files
     * depend on each, then by line.
     *
     * @return list<array{id: string, name: string, canonical_name: string, kind: string, line: int|null, used_by: int}>
     */
    private function components(string $fileId): array
    {
        $kinds = implode(',', array_fill(0, count(AbstractArchitectureQueryService::IMPACT_EDGE_KINDS), '?'));
        $statement = $this->pdo->prepare(<<<SQL
            SELECT n.id, n.display_name, n.canonical_name, n.kind, n.start_line,
                   (SELECT COUNT(DISTINCT sn.id)
                      FROM edges e
                      JOIN nodes sn ON sn.id = e.source_id
                     WHERE e.target_id = n.id AND sn.file_id IS NOT NULL AND sn.file_id <> n.file_id
                       AND e.kind IN ($kinds)) AS used_by
              FROM nodes n
             WHERE n.file_id = ? AND n.kind <> 'module' AND n.kind NOT LIKE 'external\_%' ESCAPE '\'
             ORDER BY used_by DESC, n.start_line IS NULL, n.start_line, n.canonical_name
             LIMIT ?
            SQL);
        $values = [...AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, $fileId];
        foreach ($values as $i => $value) {
            $statement->bindValue($i + 1, $value);
        }
        $statement->bindValue(count($values) + 1, self::COMPONENTS, PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn(array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['display_name'],
            'canonical_name' => (string) $row['canonical_name'],
            'kind' => (string) $row['kind'],
            'line' => $row['start_line'] === null ? null : (int) $row['start_line'],
            'used_by' => (int) $row['used_by'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** How many components the file declares. */
    private function componentCount(string $fileId): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM nodes WHERE file_id = :file AND kind <> 'module' AND kind NOT LIKE 'external\\_%' ESCAPE '\\'");
        $statement->execute(['file' => $fileId]);

        return (int) $statement->fetchColumn();
    }
}
