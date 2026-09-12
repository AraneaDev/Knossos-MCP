<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;
use PDO;

/**
 * Reads over one project's stored graph: nodes by name, and the edges on
 * either side of a node. Prepared per call, not cached, because the limit and
 * the optional kind filter change the SQL.
 *
 * No parameter has a default: the defaults belong to GraphRepository, and the
 * facade always passes them on. A second copy here could only drift, and no
 * call could ever observe it.
 */
final class SqliteGraphReader
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Look up nodes by canonical or display name.
     *
     * @return list<array<string, mixed>>
     */
    public function findNodesByName(string $projectId, string $name, int $limit): array
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
     * Edges leaving a node.
     *
     * @return list<array<string, mixed>>
     */
    public function outgoing(string $projectId, string $nodeId, ?string $kind, int $limit): array
    {
        return $this->adjacent('source_id', $projectId, $nodeId, $kind, $limit);
    }

    /**
     * Edges arriving at a node.
     *
     * @return list<array<string, mixed>>
     */
    public function incoming(string $projectId, string $nodeId, ?string $kind, int $limit): array
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
}
