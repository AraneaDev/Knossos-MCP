<?php

declare(strict_types=1);

namespace Knossos\Scan;

use PDO;

/**
 * What the previous scan cached for a project, reduced to what decides whether
 * an entry is still current: each owner's file and hash, and every file the
 * owner or its shared read group read. Contribution payloads are not loaded;
 * only the entries that survive invalidation need them.
 */
final readonly class CachedReads
{
    /**
     * @param array<string, array{scanner_id: string, file_path: string, content_hash: string, read_attribution: bool, read_group: ?string}> $rows keyed by owner key
     * @param array<string, array<string, ?string>> $ownerReads owner key to its own reads
     * @param array<string, array<string, ?string>> $groupReads group id to its reads
     */
    public function __construct(
        public array $rows,
        public array $ownerReads,
        public array $groupReads,
    ) {}

    /** Load the cached read sets of one project. */
    public static function load(PDO $pdo, string $projectId): self
    {
        $statement = $pdo->prepare('SELECT owner_key, scanner_id, file_path, content_hash, read_attribution, read_group FROM contribution_cache WHERE project_id = :project');
        $statement->execute(['project' => $projectId]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['owner_key']] = [
                'scanner_id' => (string) $row['scanner_id'],
                'file_path' => (string) $row['file_path'],
                'content_hash' => (string) $row['content_hash'],
                'read_attribution' => (int) $row['read_attribution'] === 1,
                'read_group' => $row['read_group'] === null ? null : (string) $row['read_group'],
            ];
        }

        return new self(
            $rows,
            self::readsBy($pdo, 'SELECT owner_key, read_path, read_hash FROM contribution_reads WHERE project_id = :project', $projectId),
            self::readsBy($pdo, 'SELECT group_id, read_path, read_hash FROM contribution_read_groups WHERE project_id = :project', $projectId),
        );
    }

    /**
     * Group `(key, read_path, read_hash)` rows by their first column.
     *
     * @return array<string, array<string, ?string>>
     */
    private static function readsBy(PDO $pdo, string $sql, string $projectId): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute(['project' => $projectId]);
        $reads = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$key, $path, $hash]) {
            $reads[(string) $key][(string) $path] = $hash === null ? null : (string) $hash;
        }

        return $reads;
    }
}
