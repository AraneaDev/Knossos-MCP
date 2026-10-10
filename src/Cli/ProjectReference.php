<?php

declare(strict_types=1);

namespace Knossos\Cli;

use InvalidArgumentException;
use Knossos\Scan\ProjectPathResolver;
use PDO;

/**
 * What one CLI argument names: a project by its id, or by a path inside it.
 *
 * An id wins only when that row exists, so a directory that happens to be
 * named like one is still a path. A path resolves the way the briefs resolve
 * it, walking up from a subdirectory to the project that holds it.
 */
final readonly class ProjectReference
{
    /**
     * @param PDO $pdo the graph database the argument is looked up in
     * @param string $databasePath where that database lives, for the error that names it
     */
    public function __construct(private PDO $pdo, private string $databasePath) {}

    /**
     * The project `$argument` names; refused with the database it looked in.
     *
     * @return array{id: string, root: string}
     */
    public function resolve(string $argument): array
    {
        return $this->find($argument) ?? throw new InvalidArgumentException(sprintf('Project not found: %s (database: %s)', $argument, $this->databasePath));
    }

    /**
     * The project `$argument` names, or null.
     *
     * @return array{id: string, root: string}|null
     */
    public function find(string $argument): ?array
    {
        $row = $this->byId($argument) ?? (new ProjectPathResolver($this->pdo))->resolve($argument);

        return is_array($row) ? ['id' => (string) $row['id'], 'root' => (string) ($row['root'] ?? $row['root_realpath'])] : null;
    }

    /**
     * The project whose id is exactly `$id`, or null. Asked alone where a
     * path that is not on disk must stay a path, as the briefs report it.
     *
     * @return array{id: string, root: string}|null
     */
    public function byId(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, root_realpath FROM projects WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ['id' => (string) $row['id'], 'root' => (string) $row['root_realpath']] : null;
    }
}
