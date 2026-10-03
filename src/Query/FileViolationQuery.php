<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Configuration\ProjectConfigurationLoader;
use PDO;
use Throwable;

/**
 * The boundary-policy violations whose source component lives in given files.
 *
 * Taken once before a scan and once after, two of these subtract to what the
 * scan introduced: a violation that was already there before the edit is in
 * both and drops out. Keyed by policy and the two canonical names, which
 * survive a rescan; node ids need not.
 *
 * The underlying check stops at its own result cap (100 violations across the
 * whole project) and its time limit; `truncated` says so, because a violation
 * past that bound is missing from one side or the other and the difference is
 * then a lower or an upper bound rather than exact.
 */
final readonly class FileViolationQuery
{
    public function __construct(private PDO $pdo) {}

    /**
     * The policies to check: those supplied, else the project's `knossos.json`.
     * A configuration that cannot be read declares none.
     *
     * @param list<array<string, mixed>>|null $policies
     * @return list<array<string, mixed>>
     */
    public static function policies(string $root, ?array $policies): array
    {
        if ($policies !== null) {
            return $policies;
        }
        try {
            return ProjectConfigurationLoader::load($root, [$root])->policies;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Violations whose source component is declared in one of `$files`, or
     * null when the policies cannot be evaluated (none declared, or invalid).
     *
     * @param list<array<string, mixed>> $policies
     * @param list<string> $files project-relative paths
     * @return array{violations: array<string, array<string, mixed>>, truncated: bool}|null
     */
    public function inFiles(string $projectId, array $policies, array $files): ?array
    {
        if ($policies === []) {
            return null;
        }
        if ($files === []) {
            return ['violations' => [], 'truncated' => false];
        }
        try {
            $check = (new ArchitectureQueryService($this->pdo))->checkArchitecture($projectId, $policies);
        } catch (InvalidArgumentException) {
            return null;
        }
        $violations = $check->data['violations'];
        $local = $this->nodesIn($projectId, array_values(array_unique(array_map(
            static fn(array $v): string => (string) $v['source']['id'],
            $violations,
        ))), $files);
        $found = [];
        foreach ($violations as $v) {
            if (!isset($local[(string) $v['source']['id']])) {
                continue;
            }
            $record = [
                'policy_id' => (string) $v['policy_id'],
                'source' => (string) $v['source']['canonical_name'],
                'target' => (string) $v['target']['canonical_name'],
                'source_boundaries' => $v['source_boundaries'],
                'target_boundaries' => $v['target_boundaries'],
            ];
            $found[implode("\0", [$record['policy_id'], $record['source'], $record['target']])] ??= $record;
        }
        return ['violations' => $found, 'truncated' => $check->truncated];
    }

    /**
     * Which of the node ids are declared in one of the files.
     *
     * @param list<string> $nodeIds
     * @param list<string> $files
     * @return array<string, true>
     */
    private function nodesIn(string $projectId, array $nodeIds, array $files): array
    {
        if ($nodeIds === []) {
            return [];
        }
        $wanted = array_fill_keys($files, true);
        $found = [];
        // Chunked to stay far below SQLite's bound-variable limit.
        foreach (array_chunk($nodeIds, 500) as $chunk) {
            $statement = $this->pdo->prepare(
                'SELECT n.id, f.relative_path FROM nodes n JOIN files f ON f.id = n.file_id ' .
                'WHERE n.project_id = ? AND n.id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
            );
            $statement->execute([$projectId, ...$chunk]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($wanted[(string) $row['relative_path']])) {
                    $found[(string) $row['id']] = true;
                }
            }
        }
        return $found;
    }
}
