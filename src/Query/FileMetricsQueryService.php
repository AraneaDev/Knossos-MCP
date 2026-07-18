<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use PDO;

/**
 * Read-only per-file structural metrics (byte size and physical line count),
 * filterable by path/language and sortable by path or line count. Reports the
 * active snapshot identity so rankings are reproducible.
 */
final readonly class FileMetricsQueryService extends AbstractArchitectureQueryService
{
    private const SORT_COLUMNS = ['path' => 'relative_path', 'line_count' => 'line_count'];

    public function fileMetrics(
        string $projectId,
        ?string $pathContains = null,
        ?string $language = null,
        string $sortBy = 'line_count',
        string $order = 'desc',
        int $limit = 50,
        int $offset = 0,
    ): ResultEnvelope {
        self::assertLimit($limit);
        if ($offset < 0 || $offset > 100_000) {
            throw new InvalidArgumentException('Offset must be between 0 and 100000.');
        }
        if (!isset(self::SORT_COLUMNS[$sortBy])) {
            throw new InvalidArgumentException('sort_by must be path or line_count.');
        }
        if (!in_array($order, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('order must be asc or desc.');
        }
        $project = $this->project($projectId);

        $conditions = ['project_id = :project'];
        $parameters = ['project' => $projectId];
        if ($pathContains !== null && $pathContains !== '') {
            $conditions[] = "relative_path LIKE :path ESCAPE '\\'";
            $parameters['path'] = '%' . self::escapeLike($pathContains) . '%';
        }
        if ($language !== null && $language !== '') {
            $conditions[] = 'language = :language';
            $parameters['language'] = $language;
        }
        $where = implode(' AND ', $conditions);

        $total = $this->countFiles($where, $parameters);

        $direction = $order === 'asc' ? 'ASC' : 'DESC';
        $column = self::SORT_COLUMNS[$sortBy];
        $statement = $this->pdo->prepare(sprintf(
            'SELECT relative_path, language, size, line_count FROM files WHERE %s ORDER BY %s %s, relative_path ASC LIMIT :limit OFFSET :offset',
            $where,
            $column,
            $direction,
        ));
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $files = array_map(static fn(array $row): array => [
            'path' => $row['relative_path'],
            'language' => $row['language'],
            'bytes' => (int) $row['size'],
            'line_count' => (int) $row['line_count'],
        ], $statement->fetchAll());

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('%d of %d files by %s %s.', count($files), $total, $sortBy, $order),
            [
                'files' => $files,
                'total' => $total,
                'returned' => count($files),
                'limit' => $limit,
                'offset' => $offset,
                'sort_by' => $sortBy,
                'order' => $order,
            ],
            [],
            [],
            $offset + count($files) < $total,
        );
    }

    /** @param array<string, mixed> $parameters */
    private function countFiles(string $where, array $parameters): int
    {
        $statement = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM files WHERE %s', $where));
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
