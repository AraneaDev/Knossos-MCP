<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOStatement;

/**
 * Where to look first: hubs, static hotspots, and unreferenced-code candidates.
 *
 * Holds the one definition of a hub the project reports, for both the full
 * `architecture_health` answer and the cheap ranking a session brief shows, so
 * the two cannot come to disagree about which components are hubs. Dead-code
 * answers report absence of evidence rather than proven absence: nothing static
 * analysis sees can rule out reflection.
 */
final readonly class ArchitectureHealthQuery extends AbstractArchitectureQueryService
{
    /**
     * The first in-degree of each bucket of the health check's in-degree
     * histogram: nothing depends on it, a few, some, many, and the hubs.
     */
    private const IN_DEGREE_FROM = [0, 1, 6, 21, 101];

    private DependencyCycleQuery $cycles;
    private DeadCodeCandidates $candidates;

    /**
     * @param DependencyCycleQuery|null $cycles the cycle search whose members
     *   earn a hotspot its cycle bonus; built over the same connection when
     *   the caller holds none.
     * @param DeadCodeCandidates|null $candidates the whole-project candidate
     *   search; built over the same connection when the caller holds none.
     */
    public function __construct(PDO $pdo, ?Closure $clock = null, ?DependencyCycleQuery $cycles = null, ?DeadCodeCandidates $candidates = null)
    {
        parent::__construct($pdo, $clock);
        $this->cycles = $cycles ?? new DependencyCycleQuery($pdo, $clock);
        $this->candidates = $candidates ?? new DeadCodeCandidates($pdo, $clock);
    }

    /**
     * Hubs, hotspots, and unreferenced-code candidates, ranked for where to look first.
     *
     * Reads as its phases: validate the bounds, count degree over a ranked
     * node slice, fold in the cycle signal, rank, find the candidates over the
     * whole project, and report the page.
     *
     * @param list<string> $edgeKinds
     */
    public function architectureHealth(string $projectId, array $edgeKinds = [], string $minConfidence = 'possible', int $limit = 20, int $maxNodes = 50_000, int $maxEdges = 100_000, int $timeoutMs = 1000, bool $includeExternal = false, bool $includeTests = false, string $candidateConfidence = 'possible', int $candidateOffset = 0, int $candidateTimeoutMs = 5000): ResultEnvelope
    {
        $project = $this->project($projectId);
        [$confidenceRank, $edgeKinds] = $this->healthArguments($edgeKinds, $minConfidence, $limit, $maxNodes, $maxEdges, $timeoutMs, $candidateConfidence, $candidateOffset, $candidateTimeoutMs);
        $minimumRank = $confidenceRank[$minConfidence];

        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        $truncation = new Truncation();
        // The walk keeps three facts per node, not its row: names, files,
        // roles and boundaries are loaded for the reported page alone. A full
        // row per node, with its roles and boundaries, needed more than 128 MB
        // well before the 50,000-node default.
        $slice = $this->healthSlice($projectId, $edgeKinds, $minimumRank, ['nodes' => $maxNodes, 'page' => $limit, 'external' => $includeExternal, 'tests' => $includeTests], $deadline);
        $truncation->add(...$slice['truncation_reasons']);
        $walk = $this->walkDegrees($this->healthEdges($projectId, $edgeKinds, $minimumRank, $maxEdges), $slice, $maxEdges, $deadline);
        $truncation->add(...$walk['truncation_reasons']);
        [$cycleMembers, $cycleScanTruncated] = $this->cycleMembers($projectId, $edgeKinds, $minConfidence, $maxNodes, $maxEdges, $deadline, $truncation);

        $ranked = $this->rankNodes($slice, $walk['metrics'], $cycleMembers, $includeExternal, $includeTests, $limit);
        $candidateDeadline = $this->now() + ($candidateTimeoutMs * 1_000_000);
        $found = $this->candidates->find($projectId, $edgeKinds, $minimumRank, $includeTests, $candidateDeadline, $candidateConfidence, $candidateOffset, $limit);
        foreach (['hub_total', 'hotspot_total'] as $total) {
            if ($ranked[$total] > $limit) {
                $truncation->add('result_limit');
            }
        }

        return $this->healthEnvelope(
            $project,
            ['limit' => $limit, 'max_nodes' => $maxNodes, 'max_edges' => $maxEdges, 'timeout_ms' => $timeoutMs, 'candidate_confidence' => $candidateConfidence, 'candidate_offset' => $candidateOffset, 'candidate_timeout_ms' => $candidateTimeoutMs],
            ['slice' => $slice, 'walk' => $walk, 'ranked' => $ranked, 'found' => $found, 'cycle_members' => $cycleMembers, 'cycle_scan_truncated' => $cycleScanTruncated],
            $truncation,
        );
    }

    /**
     * The top hubs on architecture_health's terms, at a session start's price.
     *
     * {@see architectureHealth()} is the authority on what a hub is, and this
     * shares its two exclusions verbatim through {@see ReportableComponent}:
     * vendor code and unresolved references are not this project's structure,
     * and test code is measured by coverage rather than by architecture. It
     * shares the degree definition too — inbound plus outbound over
     * {@see AbstractArchitectureQueryService::IMPACT_EDGE_KINDS}, which is what
     * keeps `contains` out of the tally, a relationship every declaration has
     * with its own members and which therefore ranks nothing.
     *
     * What it does not share is the rest of that method. `architecture_health`
     * also computes hotspots, runs a full cycle detection, and finds dead-code
     * candidates across the whole project, and it pays for all three before it
     * can hand back hubs. Measured against this repository's own graph (6,058 components,
     * 35,162 relationships) that is around 0.4s, against roughly 0.05s here.
     * The session brief is billed on every session start, resume and compact,
     * behind a hook that bounds itself at three seconds, so the whole report is
     * the wrong thing to buy for one ranked list — and the gap widens with the
     * graph, since the parts not needed here are the ones that scale worst.
     *
     * Filtering happens in PHP rather than in SQL so the predicates stay in one
     * place instead of being restated as a WHERE clause that could drift from
     * them. That is affordable because the ranking is consumed lazily: rows
     * arrive in degree order and the loop stops as soon as $limit survivors are
     * found, which on this repository means reading 21 rows to keep 5.
     * $maxRowsExamined bounds the pathological case — a graph whose entire head
     * is vendor code — so a brief can never turn into a full table scan.
     *
     * @return list<array{display_name: string, kind: string, degree: int}>
     */
    public function hubRanking(string $projectId, int $limit = 5, int $maxRowsExamined = 500): array
    {
        $placeholders = implode(',', array_fill(0, count(self::IMPACT_EDGE_KINDS), '?'));
        $degrees = $this->pdo->prepare(
            'SELECT n.id, n.display_name, n.kind, n.origin, d.degree FROM (' .
            'SELECT node_id, COUNT(*) AS degree FROM (' .
            sprintf('SELECT source_id AS node_id FROM edges WHERE project_id = ? AND kind IN (%s) ', $placeholders) .
            'UNION ALL ' .
            sprintf('SELECT target_id AS node_id FROM edges WHERE project_id = ? AND kind IN (%s)', $placeholders) .
            ') GROUP BY node_id) d JOIN nodes n ON n.id = d.node_id AND n.project_id = ? ' .
            'ORDER BY d.degree DESC, n.canonical_name',
        );
        $degrees->execute([$projectId, ...self::IMPACT_EDGE_KINDS, $projectId, ...self::IMPACT_EDGE_KINDS, $projectId]);
        $roles = $this->pdo->prepare('SELECT role FROM classifications WHERE project_id = ? AND node_id = ?');

        $hubs = [];
        $examined = 0;
        while (count($hubs) < $limit && $examined < $maxRowsExamined) {
            $row = $degrees->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                break;
            }
            ++$examined;
            if (ReportableComponent::isExternal((string) $row['kind'], $row['origin'])) {
                continue;
            }
            // Looked up per surviving candidate rather than joined for the
            // whole ranking: the join would classify every component in the
            // graph to report five of them.
            $roles->execute([$projectId, $row['id']]);
            /** @var list<string> $nodeRoles */
            $nodeRoles = $roles->fetchAll(PDO::FETCH_COLUMN);
            if (ReportableComponent::isTest($nodeRoles)) {
                continue;
            }
            $hubs[] = [
                'display_name' => (string) $row['display_name'],
                'kind' => (string) $row['kind'],
                'degree' => (int) $row['degree'],
            ];
        }
        // The cursor is abandoned mid-result on every call that stops early,
        // and SQLite holds its read lock until it is closed.
        $degrees->closeCursor();

        return $hubs;
    }

    /**
     * The validated bounds of an architecture_health call, checked in the
     * order the tool has always reported them, with the confidence ranks and
     * edge kinds the walk then reads.
     *
     * @param list<string> $edgeKinds
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private function healthArguments(array $edgeKinds, string $minConfidence, int $limit, int $maxNodes, int $maxEdges, int $timeoutMs, string $candidateConfidence, int $candidateOffset, int $candidateTimeoutMs): array
    {
        self::assertLimit($limit);
        if (!in_array($candidateConfidence, ['probable', 'possible'], true)) {
            throw new InvalidArgumentException('candidate_confidence must be probable or possible.');
        }
        if ($candidateOffset < 0) {
            throw new InvalidArgumentException('candidate_offset must not be negative.');
        }
        if ($candidateTimeoutMs < 1 || $candidateTimeoutMs > 60_000) {
            throw new InvalidArgumentException('candidate_timeout_ms must be between 1 and 60000.');
        }
        if ($maxNodes < 1 || $maxNodes > 50_000) {
            throw new InvalidArgumentException('max_nodes must be between 1 and 50000.');
        }
        $confidenceRank = $this->confidenceQueryBounds($maxEdges, $timeoutMs, $minConfidence);

        return [$confidenceRank, self::selectedEdgeKinds($edgeKinds, self::IMPACT_EDGE_KINDS, 'dependency')];
    }

    /**
     * The selected edges as bare endpoint pairs, executed and ready to stream
     * through {@see self::walkDegrees()}; one row past `max_edges` is asked for
     * so the walk can tell a cap that was reached from one that was met exactly.
     *
     * @param list<string> $edgeKinds
     */
    private function healthEdges(string $projectId, array $edgeKinds, int $minimumRank, int $maxEdges): PDOStatement
    {
        $placeholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        $edgeStatement = $this->pdo->prepare(
            'SELECT e.source_id, e.target_id FROM edges e WHERE e.project_id = ? ' .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY e.source_id, e.target_id, e.kind, e.id LIMIT ?',
        );
        $edgeStatement->execute([$projectId, ...$edgeKinds, $minimumRank, $maxEdges + 1]);

        return $edgeStatement;
    }

    /**
     * Every component that takes part in a dependency cycle, for the hotspot
     * bonus, and whether that cycle check was cut short.
     *
     * The check gets what is left of the deadline, at most five seconds. With
     * nothing left it does not run at all, which is reported as `time_limit`;
     * either way a cut check is reported as `cycle_scan`, because hotspots
     * without their full cycle signal are a cut ranking too.
     *
     * @param list<string> $edgeKinds
     * @return array{0: array<string, true>, 1: bool}
     */
    private function cycleMembers(string $projectId, array $edgeKinds, string $minConfidence, int $maxNodes, int $maxEdges, int $deadline, Truncation $truncation): array
    {
        $cycleMembers = [];
        $cycleScanTruncated = false;
        if ($this->now() <= $deadline) {
            $remainingMs = max(1, min(5000, (int) (($deadline - $this->now()) / 1_000_000)));
            $cycleResult = $this->cycles->dependencyCycles($projectId, $edgeKinds, $minConfidence, 100, $maxNodes, $maxEdges, $remainingMs);
            $cycleScanTruncated = $cycleResult->truncated;
            foreach ($cycleResult->data['cycles'] as $cycle) {
                // Collect from the full pre-slice membership so participants
                // beyond the 100-member detail cap still earn the cycle signal.
                foreach ($cycle['member_ids'] as $memberId) {
                    $cycleMembers[$memberId] = true;
                }
            }
        } else {
            $truncation->add('time_limit');
            $cycleScanTruncated = true;
        }
        if ($cycleScanTruncated) {
            $truncation->add('cycle_scan');
        }

        return [$cycleMembers, $cycleScanTruncated];
    }

    /**
     * The architecture_health envelope: the ranked page and the candidates,
     * with the bounds and tallies that say how complete each list is.
     *
     * @param array<string, mixed> $project
     * @param array{limit: int, max_nodes: int, max_edges: int, timeout_ms: int, candidate_confidence: string, candidate_offset: int, candidate_timeout_ms: int} $bounds the caller's bounds, reported first and in this order
     * @param array{slice: array<string, mixed>, walk: array{metrics: array{in: list<int>, out: list<int>, cross: list<int>}, edges_examined: int}, ranked: array<string, mixed>, found: array<string, mixed>, cycle_members: array<string, true>, cycle_scan_truncated: bool} $result
     */
    private function healthEnvelope(array $project, array $bounds, array $result, Truncation $truncation): ResultEnvelope
    {
        ['slice' => $slice, 'ranked' => $ranked, 'found' => $found] = $result;
        $deadCandidates = $found['candidates'];
        $excluded = $found['excluded'] + [
            'inherited' => 0, 'contracts' => 0, 'constructors' => 0, 'entry_scripts' => 0,
            'type_declarations' => 0, 'suppressed' => 0, 'annotated_false_positives' => 0, 'annotated_intentional' => 0,
        ];
        // Tallied on the FULL list, not the page: ordering test_only last means
        // the page hides them first, and a summary built from it would then
        // report 0 test-only findings whenever there were enough unreferenced
        // ones to fill the limit on their own.
        $testOnlyCandidates = $found['test_only'];
        $candidatesTotal = $found['total'];
        // The candidate list reports its own truncation: `truncation_reasons`
        // describe the hub ranking, and a full page of candidates said the
        // hubs had been cut when they had not.
        $candidateTruncationReasons = [];
        if ($found['truncated']) {
            $candidateTruncationReasons[] = 'time_limit';
        }
        if ($candidatesTotal > $bounds['candidate_offset'] + $bounds['limit']) {
            $candidateTruncationReasons[] = 'result_limit';
        }
        [$hubs, $hotspots, $evidence] = $this->reportedPage($ranked, $slice, $result['walk']['metrics'], $deadCandidates, $result['cycle_members']);
        $truncationReasons = $truncation->reasons();

        return new ResultEnvelope(
            $project['id'],
            $project['active_scan_id'],
            self::healthSummary(count($hubs), count($hotspots), $candidatesTotal, count($deadCandidates), $testOnlyCandidates, $truncationReasons, $candidateTruncationReasons),
            [
                'hubs' => $hubs, 'static_hotspots' => $hotspots, 'dead_code_candidates' => $deadCandidates, 'in_degree_histogram' => $ranked['in_degree'],
                'bounds' => $bounds + [
                    'candidates_total' => $candidatesTotal,
                    'candidates_truncated' => $candidateTruncationReasons !== [],
                    'candidate_truncation_reasons' => $candidateTruncationReasons,
                    'nodes_examined' => count($slice['ids']), 'edges_examined' => $result['walk']['edges_examined'],
                    'excluded_external_components' => $ranked['excluded_external'], 'excluded_test_components' => $ranked['excluded_tests'],
                    'excluded_inherited_methods' => $excluded['inherited'],
                    'excluded_contract_methods' => $excluded['contracts'],
                    'excluded_constructors' => $excluded['constructors'],
                    'excluded_entry_scripts' => $excluded['entry_scripts'],
                    'excluded_type_declarations' => $excluded['type_declarations'],
                    'excluded_convention_discovered' => $found['convention_excluded'],
                    'suppressed_candidates' => $excluded['suppressed'],
                    'annotated_false_positives' => $excluded['annotated_false_positives'],
                    'annotated_intentional' => $excluded['annotated_intentional'],
                    'cycle_scan_truncated' => $result['cycle_scan_truncated'], 'truncation_reasons' => $truncationReasons,
                ],
            ],
            $evidence,
            [
                'Hotspots are static structural signals, not change-frequency or defect predictions.',
                'Dead-code results are candidates only; reflection, configuration, templates, registry arrays, callbacks, dispatch tables, and framework conventions may reference a component without a visible static edge.',
                'Each candidate carries a reachability class: `unreferenced` means nothing references it at all, `test_only` means the only references come from test code. Components reached by convention — controllers, commands, entry points, config — are excluded rather than reported, and counted in bounds.excluded_convention_discovered.',
            ],
            $truncation->any(),
        );
    }

    /**
     * The hubs and hotspots as reported, and the evidence for them and the
     * candidates, from rows loaded for the reported page only.
     *
     * @param array{hubs: array<int, int>, hotspots: array<int, int>} $ranked
     * @param array{ids: list<string>} $slice
     * @param array{in: list<int>, out: list<int>, cross: list<int>} $metrics
     * @param list<array<string, mixed>> $deadCandidates
     * @param array<string, true> $cycleMembers
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function reportedPage(array $ranked, array $slice, array $metrics, array $deadCandidates, array $cycleMembers): array
    {
        $reported = [];
        foreach ([...array_keys($ranked['hubs']), ...array_keys($ranked['hotspots'])] as $index) {
            $reported[$slice['ids'][$index]] = true;
        }
        foreach ($deadCandidates as $item) {
            $reported[$item['component']['id']] = true;
        }
        // Rows for the reported page only: the ranked components and the candidates.
        $rows = $this->candidates->rows(array_map('strval', array_keys($reported)));
        [$hubs, $hotspots] = $this->rankedComponents($ranked, $slice, $metrics, $rows, $cycleMembers);
        $evidence = [];
        foreach (array_keys($reported) as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null) {
                continue;
            }
            if ($row['relative_path'] !== null) {
                $evidence[] = [
                    'component_id' => $id, 'path' => $row['relative_path'],
                    'start_line' => $row['start_line'], 'end_line' => $row['end_line'],
                ];
            }
        }

        return [$hubs, $hotspots, $evidence];
    }

    /**
     * The nodes architecture_health ranks, as the facts the ranking reads.
     *
     * Each node in the slice is numbered in rank order and keeps its in and
     * out degree, whether it is external, whether it is test code, and which
     * boundaries it belongs to; nothing else about it is held. Rank order puts
     * the components the ranking keeps first (external and test ones last,
     * unless the caller includes them), then the highest degree, then name.
     * Degree is counted in SQL over every selected edge of the project, so a
     * node's callers count whether or not they are in the slice, and
     * `max_nodes` drops the lowest-ranked nodes, which cannot be hubs while
     * the window holds a rankable one. The slice used to be the first
     * `max_nodes` by name, which dropped a hub named late together with every
     * edge from outside the window. The boundaries are kept as one of a few
     * distinct sets (most nodes share their set with many others), with the
     * repository-wide boundaries left out because they partition nothing.
     *
     * Degree is two grouped counts, one per end, so each reads a covering
     * index (`edges_project_target_idx`, `edges_project_source_idx`) when no
     * confidence filter is asked for. The counts finish before the first row
     * arrives, so the first `page` rows (the hubs a caller asked for) are kept
     * even when the deadline has passed by then; the deadline is honoured from
     * the next row on, and still reported.
     *
     * @param list<string> $edgeKinds
     * @param array{nodes: int, page: int, external: bool, tests: bool} $bounds max_nodes, the page size, and whether externals and tests are ranked
     * @return array{ids: list<string>, index: array<string, int>, in: list<int>, out: list<int>, external: list<bool>, test: array<int, true>, boundary_set: array<int, int>, boundary_sets: list<list<string>>, truncation_reasons: list<string>}
     */
    private function healthSlice(string $projectId, array $edgeKinds, int $minimumRank, array $bounds, int $deadline): array
    {
        $slice = ['ids' => [], 'index' => [], 'in' => [], 'out' => [], 'external' => [], 'test' => [], 'boundary_set' => [], 'boundary_sets' => [], 'truncation_reasons' => []];
        $filter = sprintf('project_id = ? AND kind IN (%s)', implode(',', array_fill(0, count($edgeKinds), '?')));
        $filterValues = [$projectId, ...$edgeKinds];
        if ($minimumRank > 1) {
            $filter .= " AND CASE confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER)";
            $filterValues[] = $minimumRank;
        }
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.kind, n.origin, COALESCE(i.degree, 0) AS in_degree, COALESCE(o.degree, 0) AS out_degree FROM nodes n ' .
            sprintf('LEFT JOIN (SELECT target_id AS node_id, COUNT(*) AS degree FROM edges WHERE %s GROUP BY target_id) i ON i.node_id = n.id ', $filter) .
            sprintf('LEFT JOIN (SELECT source_id AS node_id, COUNT(*) AS degree FROM edges WHERE %s GROUP BY source_id) o ON o.node_id = n.id ', $filter) .
            'WHERE n.project_id = ? ORDER BY CASE WHEN (? = 1 AND (substr(n.kind, 1, 9) = \'external_\' OR n.origin IN (\'external\', \'unresolved\'))) ' .
            'OR (? = 1 AND n.id IN (SELECT node_id FROM classifications WHERE project_id = ? AND role = ?)) THEN 1 ELSE 0 END, ' .
            'COALESCE(i.degree, 0) + COALESCE(o.degree, 0) DESC, n.canonical_name, n.id LIMIT ?',
        );
        $values = [...$filterValues, ...$filterValues, $projectId, $bounds['external'] ? 0 : 1, $bounds['tests'] ? 0 : 1, $projectId, ReportableComponent::TEST_ROLE];
        foreach ($values as $position => $value) {
            $statement->bindValue($position + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(count($values) + 1, $bounds['nodes'] + 1, PDO::PARAM_INT);
        $statement->execute();
        // Streamed: max_nodes reaches 50,000 rows, and fetchAll() read every
        // one of them before the deadline was ever consulted.
        $seen = 0;
        try {
            while (($row = $statement->fetch()) !== false) {
                if (++$seen > $bounds['nodes']) {
                    $slice['truncation_reasons'] = ['node_limit'];
                    break;
                }
                if ($seen > $bounds['page'] && ($seen - $bounds['page']) % 64 === 1 && $this->now() > $deadline) {
                    $slice['truncation_reasons'] = ['time_limit'];
                    break;
                }
                $slice['index'][$row['id']] = count($slice['ids']);
                $slice['ids'][] = (string) $row['id'];
                $slice['in'][] = (int) $row['in_degree'];
                $slice['out'][] = (int) $row['out_degree'];
                $slice['external'][] = ReportableComponent::isExternal((string) $row['kind'], $row['origin']);
            }
        } finally {
            $statement->closeCursor();
        }

        $tests = $this->pdo->prepare('SELECT DISTINCT node_id FROM classifications WHERE project_id = ? AND role = ?');
        $tests->execute([$projectId, ReportableComponent::TEST_ROLE]);
        while (($nodeId = $tests->fetchColumn()) !== false) {
            if (isset($slice['index'][$nodeId])) {
                $slice['test'][$slice['index'][$nodeId]] = true;
            }
        }

        $repositoryWide = $this->repositoryWideBoundaryIds($projectId);
        $memberships = $this->pdo->prepare(
            'SELECT bm.node_id, b.id FROM boundary_memberships bm JOIN boundaries b ON b.id = bm.boundary_id ' .
            'WHERE bm.project_id = ? ORDER BY bm.node_id, b.id',
        );
        $memberships->execute([$projectId]);
        // Rows arrive grouped by node: each group becomes that node's set.
        $setIndex = [];
        $current = null;
        $members = [];
        while (($row = $memberships->fetch()) !== false) {
            $nodeId = (string) $row['node_id'];
            if ($nodeId !== $current) {
                if ($current !== null) {
                    self::assignBoundarySet($slice, $setIndex, $current, $members);
                }
                $current = $nodeId;
                $members = [];
            }
            if (!isset($repositoryWide[(string) $row['id']])) {
                $members[] = (string) $row['id'];
            }
        }
        if ($current !== null) {
            self::assignBoundarySet($slice, $setIndex, $current, $members);
        }

        return $slice;
    }

    /**
     * Gives a node of the slice its boundary set, adding the set when it is the first node to hold it.
     *
     * A node outside the slice, or one whose boundaries are all repository-wide, gets none.
     *
     * @param array{index: array<string, int>, boundary_set: array<int, int>, boundary_sets: list<list<string>>} $slice
     * @param array<string, int> $setIndex the sets so far, keyed by their members
     * @param list<string> $members the node's boundary ids, sorted
     */
    private static function assignBoundarySet(array &$slice, array &$setIndex, string $nodeId, array $members): void
    {
        if ($members === [] || !isset($slice['index'][$nodeId])) {
            return;
        }
        $key = implode("\0", $members);
        if (!isset($setIndex[$key])) {
            $setIndex[$key] = count($slice['boundary_sets']);
            $slice['boundary_sets'][] = $members;
        }
        $slice['boundary_set'][$slice['index'][$nodeId]] = $setIndex[$key];
    }

    /**
     * One streamed pass over the edge slice, producing the cross-boundary
     * degree hotspots rank on. In and out degree come from the slice, counted
     * in SQL over every selected edge; they are passed through unchanged.
     * Cross-boundary degree still needs both ends of an edge in the node slice
     * (an edge to a node outside it has no boundary set to compare) and is
     * read from the edge stream `max_edges` bounds.
     *
     * Extracted from architectureHealth because that method is up against the
     * repository's own function-length budget, and this is the seam that pays:
     * everything here is the edge walk and the counters it fills, and nothing
     * here decides what any of it means. Dead-code candidates are decided over
     * the whole graph by DeadCodeCandidates, not from this slice.
     *
     * @param array{index: array<string, int>, in: list<int>, out: list<int>, boundary_set: array<int, int>, boundary_sets: list<list<string>>} $slice
     * @return array{
     *     metrics: array{in: list<int>, out: list<int>, cross: list<int>},
     *     edges_examined: int,
     *     truncation_reasons: list<string>,
     * }
     */
    private function walkDegrees(PDOStatement $edges, array $slice, int $maxEdges, int $deadline): array
    {
        $count = count($slice['index']);
        $metrics = ['in' => $slice['in'], 'out' => $slice['out'], 'cross' => array_fill(0, $count, 0)];
        $edgesExamined = 0;
        // Two nodes cross a boundary when both belong to some boundary and
        // they share none; decided once per pair of distinct sets.
        $disjoint = [];
        $reasons = $this->streamBounded($edges, $maxEdges, $deadline, static function (array $edge) use (&$edgesExamined, &$metrics, &$disjoint, $slice): bool {
            ++$edgesExamined;
            $source = $slice['index'][$edge['source_id']] ?? null;
            $target = $slice['index'][$edge['target_id']] ?? null;
            if ($source === null || $target === null) {
                return true;
            }
            $a = $slice['boundary_set'][$source] ?? null;
            $b = $slice['boundary_set'][$target] ?? null;
            if ($a !== null && $b !== null && $a !== $b) {
                $pair = $a < $b ? $a . ':' . $b : $b . ':' . $a;
                $disjoint[$pair] ??= array_intersect($slice['boundary_sets'][$a], $slice['boundary_sets'][$b]) === [];
                if ($disjoint[$pair]) {
                    ++$metrics['cross'][$source];
                    ++$metrics['cross'][$target];
                }
            }

            return true;
        });

        return ['metrics' => $metrics, 'edges_examined' => $edgesExamined, 'truncation_reasons' => $reasons];
    }

    /**
     * One pass over the node slice, scoring each component for the two
     * rankings architecture_health reports: hubs and static hotspots. Every
     * component the rankings could hold (degree zero included) is also counted
     * into the in-degree histogram, so a bucket says how many there are and
     * the ranking which few are listed.
     *
     * Only the first `$limit` of each ranking are kept, by score and then in
     * the slice's own order (degree, then name); the totals say how many there were.
     * Dead-code candidates are not drawn from the slice; DeadCodeCandidates
     * finds them over the whole project. Nothing here reads the database.
     *
     * @param array{ids: list<string>, external: list<bool>, test: array<int, true>} $slice
     * @param array{in: list<int>, out: list<int>, cross: list<int>} $metrics
     * @param array<string, true> $cycleMembers
     * @return array{hubs: array<int, int>, hotspots: array<int, int>, hub_total: int, hotspot_total: int, excluded_external: int, excluded_tests: int, in_degree: list<array{from: int, to: int|null, components: int}>}
     */
    private function rankNodes(array $slice, array $metrics, array $cycleMembers, bool $includeExternal, bool $includeTests, int $limit): array
    {
        $hubs = $hotspots = [];
        $excludedExternal = $excludedTests = 0;
        $histogram = array_fill(0, count(self::IN_DEGREE_FROM), 0);
        foreach ($slice['ids'] as $index => $id) {
            $degree = $metrics['in'][$index] + $metrics['out'][$index];
            $external = !$includeExternal && $slice['external'][$index];
            $test = !$external && !$includeTests && isset($slice['test'][$index]);
            if (!$external && !$test) {
                ++$histogram[self::inDegreeBucket($metrics['in'][$index])];
            }
            if ($degree === 0) {
                continue;
            }
            if ($external) {
                ++$excludedExternal;
            } elseif ($test) {
                ++$excludedTests;
            } else {
                $hubs[$index] = $degree;
                $hotspots[$index] = $degree + (2 * $metrics['cross'][$index]) + (isset($cycleMembers[$id]) ? 3 : 0);
            }
        }
        // Stable: equal scores keep the slice's order, degree then name.
        arsort($hubs);
        arsort($hotspots);

        return [
            'hubs' => array_slice($hubs, 0, $limit, true), 'hotspots' => array_slice($hotspots, 0, $limit, true),
            'hub_total' => count($hubs), 'hotspot_total' => count($hotspots),
            'excluded_external' => $excludedExternal, 'excluded_tests' => $excludedTests, 'in_degree' => self::histogram($histogram),
        ];
    }

    /**
     * The ranked page as reported: each component with its row, roles and boundaries, loaded for the page alone.
     *
     * A component whose row is gone (a scan replaced it since the walk) is left out.
     *
     * @param array{hubs: array<int, int>, hotspots: array<int, int>} $ranked
     * @param array{ids: list<string>} $slice
     * @param array{in: list<int>, out: list<int>, cross: list<int>} $metrics
     * @param array<string, array<string, mixed>> $rows
     * @param array<string, true> $cycleMembers
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function rankedComponents(array $ranked, array $slice, array $metrics, array $rows, array $cycleMembers): array
    {
        $ids = [];
        foreach ([...array_keys($ranked['hubs']), ...array_keys($ranked['hotspots'])] as $index) {
            $ids[$slice['ids'][$index]] = true;
        }
        $ids = array_map('strval', array_keys($ids));
        $roles = $this->roles($ids);
        $boundaries = $this->boundaryNames($ids);
        $entries = [];
        foreach (['hubs', 'hotspots'] as $list) {
            $entries[$list] = [];
            foreach ($ranked[$list] as $index => $score) {
                $id = $slice['ids'][$index];
                $row = $rows[$id] ?? null;
                if ($row === null) {
                    continue;
                }
                $component = [
                    'id' => $id, 'kind' => $row['kind'], 'canonical_name' => $row['canonical_name'],
                    'display_name' => $row['display_name'], 'origin' => $row['origin'], 'confidence' => $row['confidence'],
                    'roles' => $roles[$id] ?? [], 'boundaries' => $boundaries[$id] ?? [],
                ];
                $degrees = ['in_degree' => $metrics['in'][$index], 'out_degree' => $metrics['out'][$index], 'cross_boundary_degree' => $metrics['cross'][$index]];
                $entries[$list][] = $list === 'hubs'
                    ? ['component' => $component, 'metrics' => $degrees, 'score' => $score]
                    : ['component' => $component, 'factors' => $degrees + ['cycle_participant' => isset($cycleMembers[$id])], 'score' => $score];
            }
        }

        return [$entries['hubs'], $entries['hotspots']];
    }

    /** The bucket of the in-degree histogram `$inDegree` falls in: the last whose lower bound it reaches. */
    private static function inDegreeBucket(int $inDegree): int
    {
        $bucket = 0;
        foreach (self::IN_DEGREE_FROM as $index => $from) {
            if ($inDegree >= $from) {
                $bucket = $index;
            }
        }

        return $bucket;
    }

    /**
     * The histogram's counts as buckets: each one's first and last in-degree
     * (null for the open top bucket) and how many components fall in it.
     *
     * @param list<int> $counts
     * @return list<array{from: int, to: int|null, components: int}>
     */
    private static function histogram(array $counts): array
    {
        $buckets = [];
        foreach (self::IN_DEGREE_FROM as $index => $from) {
            $next = self::IN_DEGREE_FROM[$index + 1] ?? null;
            $buckets[] = ['from' => $from, 'to' => $next === null ? null : $next - 1, 'components' => $counts[$index] ?? 0];
        }

        return $buckets;
    }

    /**
     * The one-line summary for architecture_health, naming the bound when the
     * ranking was truncated.
     *
     * A bounded ranking must not read as an exhaustive one: "Ranked 0 hubs, 0
     * static hotspots, and 0 unreferenced-code candidates" is the same sentence
     * a genuinely clean project gets, and a hub sitting beyond the node, edge,
     * time, or result cap is invisible in it. Naming the bound here is what lets
     * a caller tell the two apart without reading bounds.truncation_reasons —
     * the same contract {@see DependencyCycleQuery::dependencyCycles()} keeps.
     *
     * Extracted rather than inlined because architectureHealth is up against
     * the repository's own function-length budget.
     *
     * The test-only tally is named separately because it is the half of the
     * list worth acting on first: a symbol nothing references may be waiting on
     * a caller nobody has written yet, but one its own test is the sole caller
     * of is finished work no product path reaches, and both it and the test
     * guarding it can go.
     *
     * `$testOnlyCandidates` has to be counted by the caller BEFORE `limit`
     * slices the candidate list, not passed in as the (already sliced) list
     * itself: candidates are ordered unreferenced-first, so once there are
     * enough of those to fill the limit on their own, every test_only finding
     * sits past the cut and a tally taken from the slice reads as zero while
     * the full list still has some.
     *
     * `cycle_scan` among the reasons means the cycle check was cut short
     * (by its own bounds or by the deadline), so a hotspot may be missing the
     * cycle-participant bonus it would otherwise carry.
     *
     * The hub walk's truncation and the candidate search's are reported
     * apart: the node bound limits the ranking only, and a candidate search
     * that ran out of time must not read as a complete list.
     *
     * The candidate count is the whole list's, the one the test-only tally is
     * taken from; the page's own size is named apart when it differs. A page
     * of 1 once read "1 unreferenced-code candidates, 87 of them reached only
     * by tests".
     *
     * @param list<string> $truncationReasons
     * @param list<string> $candidateTruncationReasons
     */
    private static function healthSummary(int $hubs, int $hotspots, int $candidatesTotal, int $candidatesOnPage, int $testOnlyCandidates, array $truncationReasons, array $candidateTruncationReasons): string
    {
        $summary = sprintf(
            'Ranked %d hubs, %d static hotspots, and %d unreferenced-code candidates, %d of them reached only by tests.',
            $hubs,
            $hotspots,
            $candidatesTotal,
            $testOnlyCandidates,
        );
        if ($candidatesOnPage !== $candidatesTotal) {
            $summary .= sprintf(' This page lists %d of them.', $candidatesOnPage);
        }
        if ($truncationReasons !== []) {
            $summary .= sprintf(' The ranking was truncated (%s), so hubs and hotspots beyond that bound are not reported.', implode(', ', $truncationReasons));
        }
        if (in_array('time_limit', $candidateTruncationReasons, true)) {
            $summary .= ' The candidate search ran out of time, so the candidate list is partial.';
        }
        if (in_array('result_limit', $candidateTruncationReasons, true)) {
            $summary .= ' More candidates follow this page; candidate_offset pages past it.';
        }

        return $summary;
    }
}
