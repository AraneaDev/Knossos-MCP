<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use PDO;

/**
 * How much each listed boundary depends on each other one: the heat map the
 * architecture pane's Boundaries tab draws.
 *
 * Every component counts under its one label (see {@see BoundaryLabels}), so
 * an edge lands in exactly one cell: row the source's boundary, column the
 * target's. Only dependency edges count, the kinds a policy check walks. The
 * axes are the boundaries that label something, so a declared boundary that
 * shadows an inferred one does not repeat it as an empty row; an edge with
 * either end outside them is left out. The cells a
 * declared policy forbids are listed too, so the pane can mark them whether or
 * not an edge crosses them yet.
 *
 * Bounded like the policy check: at most `$maxEdges` edges within
 * `$timeoutMs`; `truncated` with its reasons when either stopped the count,
 * so the cells are floors.
 */
final readonly class BoundaryMatrix
{
    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param Closure|null $clock nanosecond clock, so the time limit is testable
     */
    public function __construct(
        private PDO $pdo,
        private ?Closure $clock = null,
        private int $maxEdges = ArchitecturePolicyQueryService::DEFAULT_MAX_EDGES,
        private int $timeoutMs = 3000,
    ) {}

    /**
     * The matrix over the boundaries that label at least one component:
     * declared first, then the largest, at most `$limit` of them. Each axis
     * entry carries how many components it labels.
     *
     * @param list<array<string, mixed>> $policies the project's declared policies
     * @return array{boundaries: list<string>, members: list<int>, boundaries_truncated: bool, cells: list<list<int>>, forbidden: list<array{int, int}>, edges: int, truncated: bool, truncation_reasons: list<string>}
     */
    public function build(string $projectId, BoundaryLabels $labels, array $policies, int $limit = 12): array
    {
        $labelled = $labels->forProject($projectId);
        $members = array_count_values($labelled);
        // Ranked as the pane lists boundaries; one that labels nothing would be an empty row.
        $ranked = array_values(array_unique(array_filter(
            array_column($labels->listed(PHP_INT_MAX)['items'], 'name'),
            static fn(string $name): bool => isset($members[$name]),
        )));
        $names = array_slice($ranked, 0, $limit);
        $index = array_flip($names);
        $cells = array_fill(0, count($names), array_fill(0, count($names), 0));
        $nodes = array_filter($labelled, static fn(string $label): bool => isset($index[$label]));
        $counted = 0;
        $reasons = $this->walk($projectId, static function (string $source, string $target) use ($nodes, $index, &$cells, &$counted): void {
            $from = $nodes[$source] ?? null;
            $to = $nodes[$target] ?? null;
            if ($from !== null && $to !== null) {
                ++$cells[$index[$from]][$index[$to]];
                ++$counted;
            }
        });

        return [
            'boundaries' => $names,
            'members' => array_map(static fn(string $name): int => $members[$name], $names),
            'boundaries_truncated' => count($ranked) > $limit,
            'cells' => $cells,
            'forbidden' => self::forbidden($labels, $index, $policies),
            'edges' => $counted,
            'truncated' => $reasons !== [],
            'truncation_reasons' => $reasons,
        ];
    }

    /**
     * What makes up one cell: the component pairs whose dependency edges run
     * from a component labelled `$from` to one labelled `$to`, the most edges
     * first (ties by the two canonical names), the first `$limit` of them, and
     * how many edges the cell holds in all. Counted over the same bounded walk
     * as {@see self::build()}, so `edges` matches the cell and `truncated`
     * says when both are floors.
     *
     * @return array{edges: int, couplings: list<array{source: array{name: string, canonical_name: string, kind: string}, target: array{name: string, canonical_name: string, kind: string}, edges: int}>, truncated: bool, truncation_reasons: list<string>}
     */
    public function couplings(string $projectId, BoundaryLabels $labels, string $from, string $to, int $limit = 5): array
    {
        $labelled = $labels->forProject($projectId);
        $pairs = [];
        $edges = 0;
        $reasons = $this->walk($projectId, static function (string $source, string $target) use ($labelled, $from, $to, &$pairs, &$edges): void {
            if (($labelled[$source] ?? null) === $from && ($labelled[$target] ?? null) === $to) {
                $key = $source . "\0" . $target;
                $pairs[$key] = ($pairs[$key] ?? 0) + 1;
                ++$edges;
            }
        });
        $nodes = $this->nodes(array_unique(array_merge(...array_map(static fn(string $key): array => explode("\0", $key), array_keys($pairs)))));
        $listed = [];
        foreach ($pairs as $key => $count) {
            [$source, $target] = explode("\0", (string) $key);
            if (isset($nodes[$source], $nodes[$target])) {
                $listed[] = ['source' => $nodes[$source], 'target' => $nodes[$target], 'edges' => $count];
            }
        }
        usort($listed, static fn(array $a, array $b): int => $b['edges'] <=> $a['edges']
            ?: [$a['source']['canonical_name'], $a['target']['canonical_name']] <=> [$b['source']['canonical_name'], $b['target']['canonical_name']]);

        return ['edges' => $edges, 'couplings' => array_slice($listed, 0, $limit), 'truncated' => $reasons !== [], 'truncation_reasons' => $reasons];
    }

    /**
     * Every dependency edge of the project handed to `$visit` as its source
     * and target ids, at most `$maxEdges` within `$timeoutMs`; the reasons
     * the walk stopped early, none when it saw every edge.
     *
     * @param callable(string, string): void $visit
     * @return list<string>
     */
    private function walk(string $projectId, callable $visit): array
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT source_id, target_id FROM edges WHERE project_id = ? AND kind IN (%s) LIMIT ?',
            implode(',', array_fill(0, count(AbstractArchitectureQueryService::IMPACT_EDGE_KINDS), '?')),
        ));
        foreach ([$projectId, ...AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, $this->maxEdges + 1] as $position => $value) {
            $statement->bindValue($position + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
        $deadline = $this->now() + $this->timeoutMs * 1_000_000;
        $reasons = [];
        $seen = 0;
        while (($edge = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            if ($seen === $this->maxEdges) {
                $reasons[] = 'edge_limit';
                break;
            }
            // The clock is read on the first edge and every 256th after: a call per edge would cost more than the count.
            if (($seen++ & 255) === 0 && $this->now() > $deadline) {
                $reasons[] = 'time_limit';
                break;
            }
            $visit((string) $edge[0], (string) $edge[1]);
        }
        $statement->closeCursor();
        return $reasons;
    }

    /**
     * The named components among `$ids`, by id, as a coupling lists them.
     *
     * @param list<string> $ids
     * @return array<string, array{name: string, canonical_name: string, kind: string}>
     */
    private function nodes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $statement = $this->pdo->prepare('SELECT id, display_name, canonical_name, kind FROM nodes WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $statement->execute(array_values($ids));
        $nodes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $canonical = (string) $row['canonical_name'];
            $nodes[(string) $row['id']] = ['name' => (string) ($row['display_name'] ?? $canonical), 'canonical_name' => $canonical, 'kind' => (string) $row['kind']];
        }
        return $nodes;
    }

    /**
     * The cells a policy forbids, as [row, column]: each denied target, and
     * with an allow list every other boundary but the policy's own.
     *
     * @param array<string, int> $index each listed name's position
     * @param list<array<string, mixed>> $policies
     * @return list<array{int, int}>
     */
    private static function forbidden(BoundaryLabels $labels, array $index, array $policies): array
    {
        $pairs = [];
        foreach ($policies as $policy) {
            $from = $labels->nameOf((string) ($policy['from_boundary'] ?? ''));
            if ($from === null || !isset($index[$from])) {
                continue;
            }
            $names = static fn(string $key): array => array_values(array_filter(array_map(
                static fn(mixed $reference): ?string => is_string($reference) ? $labels->nameOf($reference) : null,
                is_array($policy[$key] ?? null) ? $policy[$key] : [],
            )));
            $denied = $names('deny_targets');
            $allowed = $names('allow_targets');
            // An allow list forbids everything else, even when none of its names resolve.
            $restricted = is_array($policy['allow_targets'] ?? null) && $policy['allow_targets'] !== [];
            foreach ($index as $name => $column) {
                if (in_array($name, $denied, true) || ($restricted && $name !== $from && !in_array($name, $allowed, true))) {
                    $pairs[$index[$from] . ':' . $column] = [$index[$from], $column];
                }
            }
        }
        ksort($pairs, SORT_NATURAL);

        return array_values($pairs);
    }

    /** The clock, injectable so the time limit is testable. */
    private function now(): int
    {
        return $this->clock === null ? hrtime(true) : ($this->clock)();
    }
}
