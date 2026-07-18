<?php

declare(strict_types=1);

namespace Knossos\Bundle;

use InvalidArgumentException;

final class BundleIdMapBuilder
{
    /** @param array<string, mixed> $payload @return array{files: array<string, string>, nodes: array<string, string>, boundaries: array<string, string>} */
    public function build(string $projectId, array $payload): array
    {
        $maps = ['files' => [], 'nodes' => [], 'boundaries' => []];
        foreach (array_keys($maps) as $table) {
            $rows = $payload[$table];
            foreach ($rows as $row) {
                if (!is_array($row) || array_is_list($row)) {
                    throw new InvalidArgumentException('Bundle ' . $table . ' row must be an object.');
                }
                if (!is_string($row['id'] ?? null) || $row['id'] === '') {
                    throw new InvalidArgumentException('Bundle entity ID is invalid.');
                }
                $maps[$table][$row['id']] = self::mappedId($projectId, $table, $row['id']);
            }
        }
        return $maps;
    }

    public static function mappedId(string $projectId, string $kind, string $old): string
    {
        return 'bundle-' . substr(hash('sha256', $projectId . "\0" . $kind . "\0" . $old), 0, 48);
    }
}
