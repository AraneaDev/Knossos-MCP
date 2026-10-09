<?php

declare(strict_types=1);

namespace Knossos\Boundary;

use InvalidArgumentException;
use Knossos\Store\StableId;
use PDO;

/**
 * The one resolver for a boundary reference in a policy: `from_boundary`,
 * `allow_targets` and `deny_targets`, and every reader that shows or matches
 * them.
 *
 * A reference is, in this order: a boundary's stable id; its exact name; or a
 * name it was known by before a manifest renamed it ({@see BoundaryAliases}),
 * or the stable id such a name had. Exactly one boundary must answer at the
 * first step that finds any; several are ambiguous and listed by id, so the
 * policy can name one of them by its stable id instead.
 */
final readonly class BoundaryReferences
{
    /**
     * @param array<string, string> $names each boundary's name, by id
     * @param array<string, list<string>> $byName the ids of each exact name
     * @param array<string, list<string>> $byFormer the ids each former name, or the
     *        stable id a former name had, stands for
     */
    private function __construct(private array $names, private array $byName, private array $byFormer) {}

    /** The project's boundaries, read once. */
    public static function load(PDO $pdo, string $projectId): self
    {
        $statement = $pdo->prepare('SELECT id, name, source, matcher_json FROM boundaries WHERE project_id = :project ORDER BY id');
        $statement->execute(['project' => $projectId]);

        return self::fromRows($projectId, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The resolver over boundary rows as stored: `id`, `name`, `source` and `matcher_json`.
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public static function fromRows(string $projectId, iterable $rows): self
    {
        $names = [];
        $byName = [];
        $byFormer = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $names[$id] = (string) $row['name'];
            $byName[(string) $row['name']][] = $id;
            $matcher = json_decode((string) ($row['matcher_json'] ?? '{}'), true);
            [, $aliases] = BoundaryAliases::split(is_array($matcher) ? $matcher : []);
            foreach ($aliases as $alias) {
                $byFormer[$alias][] = $id;
                // Only an inferred boundary is ever renamed, and its old id
                // was derived from the name it had.
                if (($row['source'] ?? null) === 'inferred') {
                    $byFormer[StableId::boundary($projectId, $alias, 'inferred')][] = $id;
                }
            }
        }

        return new self($names, $byName, array_map(static fn(array $ids): array => array_values(array_unique($ids)), $byFormer));
    }

    /**
     * The id a reference resolves to.
     *
     * @throws InvalidArgumentException when it names no boundary, or several
     */
    public function resolve(string $reference): string
    {
        $candidates = $this->candidates($reference);
        if ($candidates === []) {
            throw new InvalidArgumentException('Unknown policy boundary: ' . $reference);
        }
        if (count($candidates) > 1) {
            sort($candidates, SORT_STRING);
            throw new InvalidArgumentException(sprintf(
                'Ambiguous policy boundary name; use its stable ID: %s (candidates: %s)',
                $reference,
                implode(', ', $candidates),
            ));
        }

        return $candidates[0];
    }

    /** The id a reference resolves to, or null when it names no boundary or several. */
    public function find(string $reference): ?string
    {
        $candidates = $this->candidates($reference);

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /** The current name of the boundary a reference resolves to, or null when it resolves to none. */
    public function nameOf(string $reference): ?string
    {
        $id = $this->find($reference);

        return $id === null ? null : $this->names[$id];
    }

    /**
     * The ids at the first step that finds any: the id, the exact name, a former name.
     *
     * @return list<string>
     */
    private function candidates(string $reference): array
    {
        if (isset($this->names[$reference])) {
            return [$reference];
        }

        return $this->byName[$reference] ?? $this->byFormer[$reference] ?? [];
    }
}
