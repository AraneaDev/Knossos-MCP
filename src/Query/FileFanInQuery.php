<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * How many other files depend on each file, over the impact edge kinds.
 *
 * Counts distinct dependent files, not edges: a file calling forty methods
 * of another is one dependent. Edges inside one file never count, so a
 * class calling its own helpers does not look like a hub. Nor do edges to an
 * `external_*` node: one stands for a symbol declared outside the project
 * (`sprintf`, a vendor class) and is filed under whichever file first named
 * it, so counting it would make that file look depended on by every caller
 * of the built-in.
 *
 * Each row carries two boundary answers that are easy to confuse:
 * `boundaries` lists the boundaries the file's dependents sit in (where a
 * change reaches), and `boundary` is the file's own label (where the file
 * sits, labelled as {@see BoundaryLabels} labels components), null when none
 * of its components sits in a boundary.
 */
final readonly class FileFanInQuery extends AbstractArchitectureQueryService
{
    /**
     * Files with at least $threshold dependent files, most depended-on first.
     *
     * @return list<array{path: string, dependent_files: int, boundaries: list<string>, boundary: string|null}>
     */
    public function aboveThreshold(string $projectId, int $threshold, int $cap = 500): array
    {
        return array_values($this->labelled($projectId, $this->select($projectId, null, $threshold, $cap)));
    }

    /**
     * Fan-in for the given paths; a path nobody depends on reports zero.
     *
     * Every path is a bound SQLite variable alongside the edge kinds, so a list
     * beyond the build's variable limit (32766 by default) fails; callers pass
     * the handful of files an edit touched.
     *
     * @param list<string> $paths
     * @return array<string, array{path: string, dependent_files: int, boundaries: list<string>, boundary: string|null, top_dependents: list<string>}>
     */
    public function forPaths(string $projectId, array $paths, int $topDependents = 5): array
    {
        $found = $paths === [] ? [] : $this->select($projectId, $paths, 1, count($paths));
        foreach ($paths as $path) {
            $found[$path] ??= ['path' => $path, 'dependent_files' => 0, 'boundaries' => []];
        }
        $found = $this->labelled($projectId, $found);
        $result = [];
        foreach ($paths as $path) {
            $row = $found[$path];
            $row['top_dependents'] = $this->dependents($projectId, $path, $topDependents);
            $result[$path] = $row;
        }
        return $result;
    }

    /**
     * The rows with each file's own boundary label added as `boundary`.
     *
     * @param array<string, array{path: string, dependent_files: int, boundaries: list<string>}> $rows
     * @return array<string, array{path: string, dependent_files: int, boundaries: list<string>, boundary: string|null}>
     */
    private function labelled(string $projectId, array $rows): array
    {
        $labels = $rows === [] ? [] : BoundaryLabels::load($this->pdo, $projectId)->forFiles($projectId, array_map('strval', array_keys($rows)));
        foreach ($rows as $path => $row) {
            $rows[$path] = $row + ['boundary' => $labels[$path] ?? null];
        }

        return $rows;
    }

    /**
     * One grouped pass: per target file, its distinct dependent-file count and
     * the boundaries those dependents belong to, optionally restricted to paths.
     *
     * @param list<string>|null $paths
     * @return array<string, array{path: string, dependent_files: int, boundaries: list<string>}>
     */
    private function select(string $projectId, ?array $paths, int $threshold, int $cap): array
    {
        $kinds = implode(',', array_fill(0, count(self::IMPACT_EDGE_KINDS), '?'));
        $pathFilter = $paths === null ? '' : ' AND tf.relative_path IN (' . implode(',', array_fill(0, count($paths), '?')) . ')';
        $sql = <<<SQL
            SELECT tf.relative_path AS path,
                   COUNT(DISTINCT sn.file_id) AS dependent_files,
                   (SELECT GROUP_CONCAT(name, char(31)) FROM (
                        SELECT DISTINCT b.name AS name
                          FROM edges e2
                          JOIN nodes t2 ON t2.id = e2.target_id
                          JOIN nodes s2 ON s2.id = e2.source_id
                          JOIN boundary_memberships bm ON bm.node_id = s2.id
                          JOIN boundaries b ON b.id = bm.boundary_id
                         WHERE t2.file_id = tf.id AND s2.file_id <> tf.id AND e2.project_id = tf.project_id
                           AND e2.kind IN ($kinds) AND t2.kind NOT LIKE 'external\_%' ESCAPE '\'
                         ORDER BY b.name)) AS boundary_names
              FROM edges e
              JOIN nodes tn ON tn.id = e.target_id
              JOIN files tf ON tf.id = tn.file_id
              JOIN nodes sn ON sn.id = e.source_id
             WHERE e.project_id = ? AND e.kind IN ($kinds)
               AND sn.file_id IS NOT NULL AND sn.file_id <> tn.file_id
               AND tn.kind NOT LIKE 'external\_%' ESCAPE '\'
               $pathFilter
             GROUP BY tf.id
            HAVING COUNT(DISTINCT sn.file_id) >= ?
             ORDER BY dependent_files DESC, path ASC
             LIMIT ?
            SQL;
        $statement = $this->pdo->prepare($sql);
        $this->bindAll($statement, [...self::IMPACT_EDGE_KINDS, $projectId, ...self::IMPACT_EDGE_KINDS, ...($paths ?? []), $threshold, $cap]);
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $names = (string) ($row['boundary_names'] ?? '');
            $rows[(string) $row['path']] = [
                'path' => (string) $row['path'],
                'dependent_files' => (int) $row['dependent_files'],
                'boundaries' => $names === '' ? [] : explode("\x1F", $names),
            ];
        }
        return $rows;
    }

    /**
     * Up to $limit dependent file paths of $path, alphabetical.
     *
     * @return list<string>
     */
    private function dependents(string $projectId, string $path, int $limit): array
    {
        $kinds = implode(',', array_fill(0, count(self::IMPACT_EDGE_KINDS), '?'));
        $statement = $this->pdo->prepare(<<<SQL
            SELECT DISTINCT sf.relative_path
              FROM edges e
              JOIN nodes tn ON tn.id = e.target_id
              JOIN files tf ON tf.id = tn.file_id
              JOIN nodes sn ON sn.id = e.source_id
              JOIN files sf ON sf.id = sn.file_id
             WHERE e.project_id = ? AND e.kind IN ($kinds) AND tf.relative_path = ? AND sf.id <> tf.id
               AND tn.kind NOT LIKE 'external\_%' ESCAPE '\'
             ORDER BY sf.relative_path
             LIMIT ?
            SQL);
        $this->bindAll($statement, [$projectId, ...self::IMPACT_EDGE_KINDS, $path, $limit]);
        $statement->execute();
        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Bind positional parameters keeping integers as integers. PDO's execute()
     * sends every value as text, and SQLite orders an integer below any text,
     * so a bare `COUNT(...) >= '1'` would never hold.
     *
     * @param list<int|string> $values
     */
    private function bindAll(\PDOStatement $statement, array $values): void
    {
        foreach ($values as $index => $value) {
            $statement->bindValue($index + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
    }
}
