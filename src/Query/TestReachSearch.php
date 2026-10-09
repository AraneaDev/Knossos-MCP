<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use PDO;
use PDOStatement;

/**
 * Which test components statically reach a set of changed components.
 *
 * A reverse breadth-first search that visits every dependant, production and
 * test alike, because a test usually reaches changed code through production
 * code, and keeps only the components classified as test code. Production
 * dependants cost a visit but never take a result slot. test_impact used to
 * filter test roles out of an impact analysis capped at 100 dependants, so a
 * hub's production callers filled that window before any test was met and the
 * answer read "0 test files".
 *
 * The search is level-synchronous: each level's frontier is sorted by id and
 * read in chunks with one ordered query per chunk, so a node's distance is its
 * shortest hop count, and which nodes a bound cuts is the same on every run.
 * Its bounds are its own: visited nodes, edge rows examined and one deadline,
 * each named in `truncation_reasons` when it cut the walk. The depth horizon is
 * the caller's question, not a cut, so reaching it is not a truncation.
 */
final readonly class TestReachSearch extends AbstractArchitectureQueryService
{
    /** The largest node bound any query accepts (`max_nodes` in architecture_health). */
    public const MAX_VISITED = 50_000;

    /** The largest `max_edges` any query accepts. */
    public const MAX_EDGES = 100_000;

    /** Frontier ids per query, well under SQLite's 999-variable floor with the edge kinds added. */
    private const CHUNK = 500;

    /** Rows read between two clock reads, after the first row of every chunk. */
    private const CLOCK_EVERY = 64;

    public function __construct(PDO $pdo, ?Closure $clock = null, private int $maxVisited = self::MAX_VISITED, private int $maxEdges = self::MAX_EDGES)
    {
        parent::__construct($pdo, $clock);
    }

    /**
     * Walk dependants outward from the start components and collect the test-classified ones.
     *
     * @param list<string> $startIds direct components, distance 0
     * @param list<string> $edgeKinds validated impact edge kinds
     * @return array{tests: array<string, int>, visited: int, edges_examined: int, truncation_reasons: list<string>}
     *         tests: test-role node id => shortest distance
     */
    public function search(string $projectId, array $startIds, int $maxDepth, array $edgeKinds, int $minimumRank, int $deadline): array
    {
        $testIds = $this->testIds($projectId);
        $seen = [];
        $tests = [];
        $frontier = [];
        $reason = null;
        foreach ($startIds as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            if (count($seen) >= $this->maxVisited) {
                $reason = 'visit_limit';
                break;
            }
            $seen[$id] = true;
            $frontier[] = $id;
            if (isset($testIds[$id])) {
                $tests[$id] = 0;
            }
        }
        $examined = 0;
        for ($level = 0; $reason === null && $level < $maxDepth && $frontier !== []; ++$level) {
            sort($frontier, SORT_STRING);
            $next = [];
            foreach (array_chunk($frontier, self::CHUNK) as $chunk) {
                $rows = $this->dependantRows($projectId, $chunk, $edgeKinds, $minimumRank);
                $row = 0;
                while (($sourceId = $rows->fetchColumn()) !== false) {
                    if ($row++ % self::CLOCK_EVERY === 0 && $this->now() > $deadline) {
                        $reason = 'time_limit';
                        break;
                    }
                    if ($examined >= $this->maxEdges) {
                        $reason = 'edge_limit';
                        break;
                    }
                    ++$examined;
                    $sourceId = (string) $sourceId;
                    if (isset($seen[$sourceId])) {
                        continue;
                    }
                    if (count($seen) >= $this->maxVisited) {
                        $reason = 'visit_limit';
                        break;
                    }
                    $seen[$sourceId] = true;
                    $next[] = $sourceId;
                    if (isset($testIds[$sourceId])) {
                        $tests[$sourceId] = $level + 1;
                    }
                }
                // Abandoned mid-result when a bound stops the walk; SQLite holds
                // its read lock until the cursor is closed.
                $rows->closeCursor();
                if ($reason !== null) {
                    break;
                }
            }
            $frontier = $next;
        }

        return ['tests' => $tests, 'visited' => count($seen), 'edges_examined' => $examined, 'truncation_reasons' => $reason === null ? [] : [$reason]];
    }

    /**
     * Every component classified as test code, loaded once per search as a set.
     *
     * @return array<string, true>
     */
    private function testIds(string $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT node_id FROM classifications WHERE project_id = ? AND role = ?');
        $statement->execute([$projectId, ReportableComponent::TEST_ROLE]);
        $ids = [];
        while (($id = $statement->fetchColumn()) !== false) {
            $ids[(string) $id] = true;
        }
        return $ids;
    }

    /**
     * The dependants of one chunk of the frontier, streamed in target, source and edge order.
     *
     * Only the source id is selected: the walk needs nothing else, and the
     * order alone fixes which nodes a bound cuts.
     *
     * @param list<string> $targetIds @param list<string> $edgeKinds
     */
    private function dependantRows(string $projectId, array $targetIds, array $edgeKinds, int $minimumRank): PDOStatement
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT e.source_id FROM edges e WHERE e.project_id = ? AND e.target_id IN (%s) AND e.kind IN (%s) ' .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY e.target_id, e.source_id, e.id',
            implode(',', array_fill(0, count($targetIds), '?')),
            implode(',', array_fill(0, count($edgeKinds), '?')),
        ));
        $statement->execute([$projectId, ...$targetIds, ...$edgeKinds, $minimumRank]);
        return $statement;
    }
}
