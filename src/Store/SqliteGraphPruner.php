<?php

declare(strict_types=1);

namespace Knossos\Store;

/**
 * Brings a project's stored graph in line with a rescan by difference.
 *
 * Reads what exists, deletes what the scan no longer produced, and restamps
 * what survived. The alternative, clearing and rewriting everything, cost the
 * size of the project on every rescan rather than the size of the change.
 */
final class SqliteGraphPruner
{
    public function __construct(private readonly SqliteStatementCache $statements) {}

    /**
     * The graph tables a scan owns and the column identifying a row in each,
     * child-first so a delete never orphans a row it has not reached yet.
     */
    private const GRAPH_TABLES = [
        'boundary_memberships' => 'boundary_id',
        'boundaries' => 'id',
        'classifications' => 'id',
        'edges' => 'id',
        'nodes' => 'id',
        'files' => 'id',
    ];

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
            $statement = $this->statements->prepare(sprintf('SELECT id FROM %s WHERE project_id = :project', $table));
            $statement->execute(['project' => $projectId]);
            $seen = [];
            while (($row = $statement->fetch()) !== false) {
                $seen[(string) $row['id']] = true;
            }
            $ids[$table] = $seen;
        }
        // A membership is identified by the pair it joins, not by an id column.
        $statement = $this->statements->prepare('SELECT boundary_id, node_id FROM boundary_memberships WHERE project_id = :project');
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
                $statement = $this->statements->prepare(sprintf('DELETE FROM %s WHERE project_id = ? AND id IN (%s)', $table, $placeholders));
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
                $statement = $this->statements->prepare(sprintf(
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
            $statement = $this->statements->prepare(sprintf('UPDATE %s SET last_scan_id = :scan WHERE project_id = :project AND last_scan_id <> :scan', $table));
            $statement->execute(['scan' => $scanId, 'project' => $projectId]);
        }
    }

    /** Drop a project's diagnostics, which belong to the scan that produced them. */
    public function clearProjectDiagnostics(string $projectId): void
    {
        $this->statements->prepare('DELETE FROM diagnostics WHERE project_id = :project')->execute(['project' => $projectId]);
    }
}
