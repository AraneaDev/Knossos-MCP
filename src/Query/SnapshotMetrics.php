<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * The figures a snapshot's graph yields for the quality gate, the branch
 * comparison and the trends: reportable components, degree, cycles,
 * diagnostics and unreferenced candidates, computed from the facts alone.
 *
 * Nothing here reads the database; every figure is a function of the facts
 * a caller hands in, which is what lets {@see SnapshotMetricsCache} keep the
 * figures of a retained archive for as long as the archive and this code
 * are unchanged. It extends the query base for the shared edge-kind sets and
 * cycle search, so these figures and `dependency_cycles` agree on both.
 */
final readonly class SnapshotMetrics extends AbstractArchitectureQueryService
{
    /**
     * A snapshot's fact counts and quality metrics, as a trend reports them.
     *
     * @param array<string, list<array<string, mixed>>> $facts
     * @return array{counts: array<string, int>, metrics: array<string, int>}
     */
    public function snapshotFigures(array $facts): array
    {
        return [
            'counts' => [
                'components' => count($facts['nodes'] ?? []), 'relationships' => count($facts['edges'] ?? []),
                'roles' => count($facts['classifications'] ?? []), 'boundaries' => count($facts['boundaries'] ?? []),
                'diagnostics' => count($facts['diagnostics'] ?? []),
            ],
            'metrics' => self::qualityMetrics($this->snapshotAnalysis($facts)),
        ];
    }

    /**
     * What a branch comparison asks of the base graph, by identity key (see
     * {@see self::identityKeys()}): its impact edges (`source\0target`), the cycle each member of one was in,
     * how many reportable components depended on each one, and the
     * unreferenced candidates.
     *
     * @param array<string, list<array<string, mixed>>> $facts
     * @return array{edges: array<string, true>, cycle_of: array<string, int>, in: array<string, int>, dead: array<string, true>}
     */
    public function baseFigures(array $facts): array
    {
        $before = $this->snapshotAnalysis($facts);
        $names = self::identityKeys($facts);
        unset($facts);

        return [
            'edges' => self::edgePairs($before['adjacency'], $names),
            'cycle_of' => self::cycleIndex($before['sccs'], $names),
            'in' => self::inDegrees($before, $names),
            'dead' => array_fill_keys(array_map(static fn(string $id): string => $names[$id], $before['unreferenced']), true),
        ];
    }

    /**
     * What the quality gate compares of one graph: its metrics, its public
     * surface, which cycle each cycle member is in (by identity key), and,
     * given the baseline's, how many of its cycles are new.
     *
     * @param array<string, list<array<string, mixed>>> $facts
     * @param array<string, int>|null $baseCycleOf the baseline's cycle index, for the active graph
     * @return array{metrics: array<string, int>, surface: array<string, true>, cycle_of: array<string, int>, new_cycles: int}
     */
    public function gateFigures(array $facts, ?array $baseCycleOf = null): array
    {
        $analysis = $this->snapshotAnalysis($facts);
        $keys = self::identityKeys($facts);

        return [
            'metrics' => self::qualityMetrics($analysis), 'surface' => self::surfaceIds($facts),
            'cycle_of' => self::cycleIndex($analysis['sccs'], $keys),
            'new_cycles' => $baseCycleOf === null ? 0 : count(self::newCycles($baseCycleOf, $analysis['sccs'], $keys)),
        ];
    }

    /**
     * One snapshot's graph read for the gate and the branch comparison: the
     * reportable components, each one's degree among them and its impact
     * edges both ways, the strongly connected components, the diagnostics by
     * severity, and the unreferenced candidates the gate counts.
     *
     * @param array<string, list<array<string, mixed>>> $facts
     * @return array{reportable: array<string, true>, degree: array<string, int>, adjacency: array<string, list<string>>, reverse: array<string, list<string>>, sccs: list<list<string>>, errors: int, warnings: int, unreferenced: list<string>}
     */
    public function snapshotAnalysis(array $facts): array
    {
        $nodes = array_fill_keys(array_column($facts['nodes'] ?? [], 'id'), true);
        $roles = $this->snapshotRoles($facts);
        // Hub scope: vendor code and test code are not this architecture.
        $reportable = [];
        foreach ($facts['nodes'] ?? [] as $node) {
            if (!ReportableComponent::isExternal((string) $node['kind'], $node['origin'] ?? null)
                && !ReportableComponent::isTest($roles[$node['id']] ?? [])) {
                $reportable[$node['id']] = true;
            }
        }
        $adjacency = $reverse = [];
        $degree = array_fill_keys(array_keys($nodes), 0);
        foreach (array_keys($nodes) as $id) {
            $adjacency[$id] = $reverse[$id] = [];
        }
        // `contains` is not an impact relationship, so it never contributes a
        // degree, but it is what says which type a method belongs to — needed
        // below to tell a contract member from an orphan.
        $members = $contracts = $inheritanceInDegree = [];
        // Erased type-only imports, counted per pair: they stay in the
        // adjacency (reachability, degrees, crossings) but not in the cycle
        // search, as in dependency_cycles.
        $erased = [];
        foreach ($facts['edges'] ?? [] as $edge) {
            if (!isset($nodes[$edge['source_id']], $nodes[$edge['target_id']])) {
                continue;
            }
            if ($edge['kind'] === 'contains') {
                $members[$edge['source_id']][] = $edge['target_id'];
            }
            if (in_array($edge['kind'], self::CONTRACT_EDGE_KINDS, true)) {
                $contracts[$edge['source_id']][] = $edge['target_id'];
                $inheritanceInDegree[$edge['target_id']] = ($inheritanceInDegree[$edge['target_id']] ?? 0) + 1;
            }
            if (!in_array($edge['kind'], self::IMPACT_EDGE_KINDS, true)) {
                continue;
            }
            $adjacency[$edge['source_id']][] = $edge['target_id'];
            $reverse[$edge['target_id']][] = $edge['source_id'];
            if (ErasedTypeEdge::matches($edge)) {
                $erased[$edge['source_id']][$edge['target_id']] = ($erased[$edge['source_id']][$edge['target_id']] ?? 0) + 1;
            }
            // Only a relationship between two reportable components is part of
            // the architecture this degree describes. Excluding test and vendor
            // components from BEING hubs was not enough on its own: a test
            // referencing a production hub still raised that hub's degree, so a
            // commit that only added tests spent hub_degree_growth it had no way
            // to reclaim, which is the failure the scope comment above exists to
            // prevent. Found by running this gate against Knossos itself, where
            // adding twenty-five test files moved the budget by 57.
            //
            // Reachability is deliberately left alone: $adjacency and $reverse
            // still record the edge, so a component a test references stays
            // referenced rather than becoming an unreferenced candidate.
            if (isset($reportable[$edge['source_id']], $reportable[$edge['target_id']])) {
                ++$degree[$edge['source_id']];
                ++$degree[$edge['target_id']];
            }
        }
        $declaringType = [];
        foreach ($members as $type => $held) {
            foreach ($held as $member) {
                $declaringType[$member] = $type;
            }
        }
        $displayNames = [];
        foreach ($facts['nodes'] ?? [] as $node) {
            $displayNames[$node['id']] = (string) $node['display_name'];
        }
        // Self-loops are ordinary recursion, not architectural cycles;
        // dependency_cycles excludes them by default, so mirror that here.
        $sccs = $erased === []
            ? $this->stronglyConnectedComponents($adjacency, $reverse)['components']
            : $this->stronglyConnectedComponents(...self::withoutErased($adjacency, $erased))['components'];
        $errors = $warnings = 0;
        foreach ($facts['diagnostics'] ?? [] as $diagnostic) {
            $errors += $diagnostic['severity'] === 'error' ? 1 : 0;
            $warnings += $diagnostic['severity'] === 'warning' ? 1 : 0;
        }
        // Count only the declaration kinds architecture_health treats as
        // dead-code candidates, and only the components it reports on at all —
        // see ReportableComponent for why counting the rest made this budget
        // unusable rather than merely imprecise. Health layers further,
        // database-backed exclusions on top (inherited and contract members,
        // annotations, suppressions), so among components with NO inbound edge
        // this count is the larger of the two by design; what it may not do is
        // count a component health drops for a reason this loop can see for
        // itself.
        //
        // Health also reports a class this budget deliberately does not charge
        // for: a component reached only from test code is `test_only` there and
        // referenced here. It is worth deleting, but it is not a regression the
        // way a newly orphaned component is, and a budget that moved when a
        // caller was replaced by a test would punish the wrong change.
        $candidateKinds = ['class', 'interface', 'trait', 'enum', 'function', 'method', 'module'];
        $unreferenced = [];
        foreach ($facts['nodes'] ?? [] as $node) {
            if (($reverse[$node['id']] ?? []) !== [] || !isset($reportable[$node['id']])) {
                continue;
            }
            if (!in_array($node['kind'], $candidateKinds, true)) {
                continue;
            }
            // A runtime-invoked lifecycle method, a method fulfilling a
            // supertype's member, an executable script's module, a type
            // declaration, or a convention-discovered component has no inbound edge by
            // construction, so counting it would charge the budget for
            // something no maintainer can act on.
            if (ReportableComponent::isRuntimeLifecycleMethod((string) $node['kind'], (string) $node['display_name'])
                || ReportableComponent::isRuntimeInvoked($node['attributes_json'] ?? null)
                || ReportableComponent::isDeclaredOverride((string) $node['kind'], $node['attributes_json'] ?? null)
                || ReportableComponent::isExecutableScript((string) $node['kind'], $node['attributes_json'] ?? null)
                || ReportableComponent::isTypeDeclaration($node['attributes_json'] ?? null)
                || ReportableComponent::isDiscoveredByConvention($roles[$node['id']] ?? [])) {
                continue;
            }
            if ($this->isContractMemberOfUsedType($node, $declaringType, $contracts, $members, $displayNames, $reverse, $inheritanceInDegree)) {
                continue;
            }
            $unreferenced[] = (string) $node['id'];
        }
        return ['reportable' => $reportable, 'degree' => $degree, 'adjacency' => $adjacency, 'reverse' => $reverse, 'sccs' => $sccs,
            'errors' => $errors, 'warnings' => $warnings, 'unreferenced' => $unreferenced];
    }

    /**
     * Which cycle each member of a cycle is in, by identity key.
     *
     * @param list<list<string>> $sccs
     * @param array<string, string> $keys identity keys by id
     * @return array<string, int>
     */
    private static function cycleIndex(array $sccs, array $keys): array
    {
        $cycleOf = [];
        foreach ($sccs as $index => $members) {
            if (count($members) > 1) {
                foreach ($members as $member) {
                    $cycleOf[$keys[$member]] = $index;
                }
            }
        }

        return $cycleOf;
    }

    /**
     * The active graph's cycles that are new: a cycle is new unless all its
     * members (by identity key) were in one baseline cycle.
     *
     * The quality gate counts these and the branch comparison lists them, so
     * the two agree on what a new cycle is. A count difference, which the gate
     * used before, read 0 when two cycles merged into one or when one cycle
     * was broken while another was made.
     *
     * @param array<string, int> $baseCycleOf the baseline's cycle index ({@see self::cycleIndex()})
     * @param list<list<string>> $afterSccs the active graph's strongly connected components
     * @param array<string, string> $keysNow the active graph's identity keys by id
     * @return list<list<string>> each new cycle's member ids
     */
    public static function newCycles(array $baseCycleOf, array $afterSccs, array $keysNow): array
    {
        $new = [];
        foreach ($afterSccs as $members) {
            if (count($members) < 2) {
                continue;
            }
            $old = array_unique(array_map(static fn(string $member): int => $baseCycleOf[$keysNow[$member]] ?? -1, $members));
            if (count($old) > 1 || $old[array_key_first($old)] < 0) {
                $new[] = $members;
            }
        }

        return $new;
    }

    /**
     * Each node's identity across two graphs, by id: `language\0kind\0canonical_name`.
     *
     * This is the tuple a node id is a hash of ({@see \Knossos\Store\StableId::symbol()},
     * with the project), so it names the same component across two graphs
     * that the id does, and can be printed and compared without the hash.
     * The defect it replaces was keying by the full name alone: a module and
     * a package, or a class and a function, can share one, and keying by it
     * merged two components into one in every comparison.
     *
     * @param array<string, list<array<string, mixed>>> $facts
     * @return array<string, string>
     */
    public static function identityKeys(array $facts): array
    {
        $keys = [];
        foreach ($facts['nodes'] ?? [] as $node) {
            $keys[(string) $node['id']] = (string) ($node['language'] ?? '') . "\0" . (string) $node['kind'] . "\0" . (string) $node['canonical_name'];
        }
        return $keys;
    }

    /**
     * Each node as the pane opens it, by id: its shown and full name, kind, file and line.
     *
     * @param array<string, list<array<string, mixed>>> $facts
     * @return array<string, array{name: string, canonical_name: string, kind: string, path: string|null, line: int|null}>
     */
    public static function placedNodes(array $facts): array
    {
        $paths = [];
        foreach ($facts['files'] ?? [] as $file) {
            $paths[(string) $file['id']] = (string) $file['relative_path'];
        }
        $nodes = [];
        foreach ($facts['nodes'] ?? [] as $node) {
            $nodes[(string) $node['id']] = [
                'name' => (string) $node['display_name'], 'canonical_name' => (string) $node['canonical_name'], 'kind' => (string) $node['kind'],
                'path' => $paths[(string) ($node['file_id'] ?? '')] ?? null, 'line' => isset($node['start_line']) ? (int) $node['start_line'] : null,
            ];
        }
        return $nodes;
    }

    /**
     * The impact edges as pairs of identity keys, `source\0target`.
     *
     * @param array<string, list<string>> $adjacency
     * @param array<string, string> $names
     * @return array<string, true>
     */
    private static function edgePairs(array $adjacency, array $names): array
    {
        $pairs = [];
        foreach ($adjacency as $source => $targets) {
            foreach ($targets as $target) {
                $pairs[$names[$source] . "\0" . $names[$target]] = true;
            }
        }
        return $pairs;
    }

    /**
     * How many reportable components depend on each reportable one, by identity key.
     *
     * @param array{reportable: array<string, true>, reverse: array<string, list<string>>} $analysis
     * @param array<string, string> $names
     * @return array<string, int>
     */
    public static function inDegrees(array $analysis, array $names): array
    {
        $degrees = [];
        foreach ($analysis['reverse'] as $target => $sources) {
            if (isset($analysis['reportable'][$target])) {
                $degrees[$names[$target]] = count(array_filter(array_unique($sources), static fn(string $s): bool => isset($analysis['reportable'][$s])));
            }
        }
        return $degrees;
    }

    /**
     * The roles each of the snapshot's nodes carries, keyed by node id.
     *
     * @param array<string, list<array<string, mixed>>> $facts @return array<string, list<string>>
     */
    private function snapshotRoles(array $facts): array
    {
        $roles = [];
        foreach ($facts['classifications'] ?? [] as $classification) {
            $roles[$classification['node_id']][] = (string) $classification['role'];
        }

        return $roles;
    }

    /** Relationships by which a type takes on another type's members. */
    private const CONTRACT_EDGE_KINDS = ['implements', 'extends', 'uses_trait'];

    /**
     * Whether a method is reached through a contract its type carries.
     *
     * A call to an interface method lands on the interface's declaration, so
     * every implementation of it has an in-degree of zero however heavily the
     * interface is used. `architecture_health` discounts these; counting them
     * here charged the budget for every implementation of every interface,
     * which no maintainer could pay down without deleting the contract.
     *
     * Gated on the declaring type being used for something other than being
     * implemented, exactly as health gates it: when nothing else references
     * the interface, the interface is the unit worth deleting and its members
     * stay reportable.
     *
     * @param array<string, mixed> $node
     * @param array<string, string> $declaringType
     * @param array<string, list<string>> $contracts
     * @param array<string, list<string>> $members
     * @param array<string, string> $displayNames
     * @param array<string, list<string>> $reverse
     * @param array<string, int> $inheritanceInDegree
     */
    private function isContractMemberOfUsedType(
        array $node,
        array $declaringType,
        array $contracts,
        array $members,
        array $displayNames,
        array $reverse,
        array $inheritanceInDegree,
    ): bool {
        if ($node['kind'] !== 'method') {
            return false;
        }
        $owner = $declaringType[$node['id']] ?? null;
        if ($owner === null) {
            return false;
        }
        $name = (string) $node['display_name'];
        foreach ($contracts[$owner] ?? [] as $contract) {
            $declares = false;
            foreach ($members[$contract] ?? [] as $member) {
                if (($displayNames[$member] ?? null) === $name) {
                    $declares = true;
                    break;
                }
            }
            if (!$declares) {
                continue;
            }
            $uses = count($reverse[$contract] ?? []) - ($inheritanceInDegree[$contract] ?? 0);
            if ($uses > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The metrics a snapshot reports: cycles, diagnostics, hub degree, unreferenced candidates.
     *
     * @param array{reportable: array<string, true>, degree: array<string, int>, sccs: list<list<string>>, errors: int, warnings: int, unreferenced: list<string>} $analysis
     * @return array<string, int>
     */
    private static function qualityMetrics(array $analysis): array
    {
        $cycles = count(array_filter($analysis['sccs'], static fn(array $component): bool => count($component) > 1));
        // Hub size is likewise a statement about the architecture, so a test-only
        // hub must not move it: otherwise every commit that adds tests spends
        // hub_degree_growth budget it has no way to reclaim.
        $reportableDegrees = array_intersect_key($analysis['degree'], $analysis['reportable']);
        return ['cycles' => $cycles, 'max_degree' => $reportableDegrees === [] ? 0 : max($reportableDegrees), 'error_diagnostics' => $analysis['errors'],
            'warning_diagnostics' => $analysis['warnings'], 'unreferenced_candidates' => count($analysis['unreferenced'])];
    }

    /**
     * The impact graph without its erased type-only edges, both ways, for the cycle search.
     *
     * Built only when such edges exist, so a graph without them shares its
     * adjacency with the analysis instead of holding a second copy.
     *
     * @param array<string, list<string>> $adjacency
     * @param array<string, array<string, int>> $erased how many erased edges join each source and target
     * @return array{0: array<string, list<string>>, 1: array<string, list<string>>}
     */
    private static function withoutErased(array $adjacency, array $erased): array
    {
        $forward = $reverse = array_fill_keys(array_keys($adjacency), []);
        foreach ($adjacency as $source => $targets) {
            foreach ($targets as $target) {
                if (($erased[$source][$target] ?? 0) > 0) {
                    --$erased[$source][$target];
                    continue;
                }
                $forward[$source][] = $target;
                $reverse[$target][] = $source;
            }
        }

        return [$forward, $reverse];
    }

    /**
     * The ids of a graph's public API surface, the components whose addition or removal is most likely to break a consumer.
     *
     * @param array<string, list<array<string, mixed>>> $facts
     * @return array<string, true>
     */
    private static function surfaceIds(array $facts): array
    {
        $ids = [];
        foreach ($facts['nodes'] ?? [] as $node) {
            if (in_array($node['kind'], ['route', 'command', 'endpoint', 'export'], true)) {
                $ids[(string) $node['id']] = true;
            }
        }
        foreach ($facts['classifications'] ?? [] as $role) {
            if (str_contains($role['role'], 'entry_point') || str_contains($role['role'], 'public')) {
                $ids[(string) $role['node_id']] = true;
            }
        }

        return $ids;
    }
}
