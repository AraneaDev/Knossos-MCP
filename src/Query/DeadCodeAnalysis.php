<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * Decides which components with no inbound reference are worth reporting.
 *
 * Absence of a static edge is weak evidence on its own: reflection, dispatch
 * tables, framework conventions, and inheritance all reach code without leaving
 * one. Most of this class is therefore the set of exclusions that keep the
 * report honest — inherited and contract members, engine-invoked constructors,
 * suppressed names, annotated false positives — plus the queries needed to
 * establish them.
 *
 * Split out of GraphTopologyQueryService, where it was the largest thing in a
 * 1,456-line file and made architectureHealth a 274-line method up against the
 * repository's own 275-line budget. The topology service asks it a question;
 * how the answer is qualified is this class's own concern.
 */
final readonly class DeadCodeAnalysis extends AbstractArchitectureQueryService
{
    /**
     * The TypeScript/JavaScript constructor. PHP and Python both spell their
     * engine-dispatched members with a leading `__` instead, which
     * `isEngineInvokedMemberOfReferencedType` matches by prefix.
     */
    private const CONSTRUCTOR_MEMBER_NAME = 'constructor';

    /**
     * Classify provisionally unreferenced components, dropping the ones nothing
     * could act on and labelling the confidence of what remains.
     *
     * The exclusions are the point: a member reached by inheritance, a contract
     * an implementation carries, a constructor the engine invokes, a name the
     * project has suppressed or annotated as a false positive — each has an
     * in-degree of zero by construction, and reporting it as dead code trains a
     * reader to ignore the whole list.
     *
     * @param array<string, array{component: array<string, mixed>, row: array<string, mixed>, roles: list<array<string, mixed>>, out_degree: int}> $provisional
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, array{in_degree: int, out_degree: int, cross_boundary_degree: int}> $metrics
     * @param array<string, int> $inheritanceInDegree
     *
     * @return array{candidates: list<array<string, mixed>>, excluded: array<string, int>}
     */
    public function classify(string $projectId, array $provisional, array $nodes, array $metrics, array $inheritanceInDegree): array
    {
        $candidates = [];
        $methodNames = [];
        foreach ($provisional as $id => $candidate) {
            if ($candidate['row']['kind'] === 'method') {
                $methodNames[$id] = (string) $candidate['row']['display_name'];
            }
        }
        $inheritance = $this->inheritedMethodContext($projectId, array_keys($methodNames), $methodNames);
        $excludedInherited = 0;
        $idsByCanonicalName = self::indexByCanonicalName($nodes);
        $excludedConstructors = 0;
        $excludedContracts = 0;
        $excludedEntryScripts = 0;
        $suppressions = $this->deadCodeSuppressions($projectId);
        $suppressedCount = 0;
        $annotationsByName = $this->componentAnnotations($projectId);
        $annotatedFalsePositives = 0;
        foreach ($provisional as $id => $candidate) {
            if (self::isSuppressed((string) $candidate['row']['canonical_name'], $suppressions)) {
                ++$suppressedCount;
                continue;
            }
            $annotation = $annotationsByName[(string) $candidate['row']['canonical_name']] ?? null;
            if ($annotation !== null && $annotation['kind'] === 'false_positive') {
                ++$annotatedFalsePositives;
                continue;
            }
            $context = $inheritance[$id] ?? [
                'inherited' => false,
                'implemented' => false,
                'declaring_type' => null,
                'external_ancestor' => null,
            ];
            if ($context['inherited']) {
                ++$excludedInherited;
                continue;
            }
            // A contract an implementation carries. Gated on the declaring type
            // being referenced for the same reason constructors are: when
            // nothing uses the type, the type is the unit worth deleting and
            // both it and its members stay reportable.
            if ($context['implemented'] && $context['declaring_type'] !== null) {
                $declaringType = $context['declaring_type'];
                $declaringUses = ($metrics[$declaringType]['in_degree'] ?? 0)
                    - ($inheritanceInDegree[$declaringType] ?? 0);
                if ($declaringUses > 0) {
                    ++$excludedContracts;
                    continue;
                }
            }
            if ($this->isEngineInvokedMemberOfReferencedType($candidate['row'], $idsByCanonicalName, $metrics)) {
                ++$excludedConstructors;
                continue;
            }
            // A script's body is run by something outside the graph, so nothing
            // in the codebase references it and "unreferenced" carries no
            // information about whether it is wanted. An ordinary module that
            // nothing imports stays reportable — an orphaned one is precisely
            // what this analysis exists to surface.
            if (ReportableComponent::isExecutableScript((string) $candidate['row']['kind'], $candidate['row']['attributes_json'] ?? null)) {
                ++$excludedEntryScripts;
                continue;
            }
            $dynamicRisk = $candidate['row']['origin'] !== 'ast' || $this->hasFrameworkRole($candidate['roles']);
            $confidence = $dynamicRisk ? 'possible' : 'probable';
            $reason = 'No inbound static reference was found among the selected edge kinds.';
            if ($context['external_ancestor'] !== null) {
                $confidence = 'possible';
                $reason = sprintf(
                    'No inbound static reference was found, but the declaring type extends or implements %s, whose members are not statically visible; dispatch may reach this method.',
                    $context['external_ancestor'],
                );
            }
            $entry = [
                'component' => $candidate['component'],
                'confidence' => $confidence,
                'reason' => $reason,
                'out_degree' => $candidate['out_degree'],
            ];
            if ($annotation !== null) {
                $entry['annotation'] = $annotation;
            }
            $candidates[] = $entry;
        }

        return [
            'candidates' => $candidates,
            'excluded' => [
                'inherited' => $excludedInherited,
                'contracts' => $excludedContracts,
                'constructors' => $excludedConstructors,
                'entry_scripts' => $excludedEntryScripts,
                'suppressed' => $suppressedCount,
                'annotated_false_positives' => $annotatedFalsePositives,
            ],
        ];
    }


    /**
     * True when the candidate is a member the language runtime invokes, rather
     * than one a call site names, whose declaring type IS referenced somewhere.
     *
     * `new Foo(...)` is recorded as a `constructs` edge to the class `Foo`, not
     * to `Foo::__construct`, so a constructor's in-degree is 0 for every class
     * in the graph — including heavily used ones. Reporting those as
     * unreferenced code drowned the real signal: on a scan of a 109-file
     * TypeScript project, five of the thirteen surviving candidates were
     * constructors of classes the same graph showed being instantiated.
     *
     * Constructors are only the most common case. `__destruct` runs when the
     * last reference drops, `__toString` on a string cast, `__invoke` on a
     * call, and Python's protocol methods (`__repr__`, `__enter__`, `__eq__`)
     * likewise — none is ever written at a call site, so every one of them is
     * structurally unreferenced. Both languages reserve the `__` prefix for
     * exactly this dispatch, which is why the prefix is the test.
     *
     * The declaring type having ANY inbound reference is enough. Such a member
     * on a type that is itself unreferenced stays a candidate — that type (and
     * with it the member) really may be dead, and it is reported through the
     * type, which is the more useful unit to delete.
     *
     * @param array<string, mixed>  $node
     * @param array<string, string> $idsByCanonicalName
     * @param array<string, array{in_degree: int, out_degree: int, cross_boundary_degree: int}> $metrics
     */
    private function isEngineInvokedMemberOfReferencedType(array $node, array $idsByCanonicalName, array $metrics): bool
    {
        if ($node['kind'] !== 'method') {
            return false;
        }
        $displayName = (string) $node['display_name'];
        if ($displayName !== self::CONSTRUCTOR_MEMBER_NAME && !str_starts_with($displayName, '__')) {
            return false;
        }
        $owner = self::owningTypeName((string) $node['canonical_name']);
        if ($owner === null) {
            return false;
        }
        $ownerId = $idsByCanonicalName[$owner] ?? null;

        return $ownerId !== null && ($metrics[$ownerId]['in_degree'] ?? 0) > 0;
    }

    /**
     * Whether nothing references a node. Absence of evidence, not proof: reflection is invisible here.
     *
     * @param array<string, mixed> $node @param list<array<string, mixed>> $roles
     */
    public function isCandidate(array $node, array $roles): bool
    {
        if (!in_array($node['kind'], ['class', 'interface', 'trait', 'enum', 'function', 'method', 'module'], true)) {
            return false;
        }
        if (ReportableComponent::isExternal((string) $node['kind'], $node['origin'])) {
            return false;
        }

        return !ReportableComponent::isDiscoveredByConvention(array_column($roles, 'role'));
    }
    /**
     * Member names declared by the internal types that implement or extend
     * each of `$typeIds`.
     *
     * Only direct subtypes are read. A grandchild that redeclares a member its
     * own parent already declares is reached through that parent, so one level
     * answers the question this asks: does some implementation carry this
     * contract?
     *
     * @param list<string> $typeIds
     * @return array<string, array<string, true>> type id => member display names
     */
    private function subtypeMemberNames(string $projectId, array $typeIds): array
    {
        $subtypesOf = [];
        foreach (array_chunk($typeIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                "SELECT source_id, target_id FROM edges WHERE project_id = ? AND kind IN ('implements', 'extends') " .
                sprintf('AND target_id IN (%s)', $placeholders),
            );
            $statement->execute([$projectId, ...$chunk]);
            foreach ($statement->fetchAll() as $row) {
                $subtypesOf[$row['target_id']][] = $row['source_id'];
            }
        }
        if ($subtypesOf === []) {
            return [];
        }

        $memberNames = [];
        $subtypeIds = array_values(array_unique(array_merge(...array_values($subtypesOf))));
        foreach (array_chunk($subtypeIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                'SELECT e.source_id, n.display_name FROM edges e JOIN nodes n ON n.id = e.target_id ' .
                "WHERE e.project_id = ? AND e.kind = 'contains' " .
                sprintf('AND e.source_id IN (%s)', $placeholders),
            );
            $statement->execute([$projectId, ...$chunk]);
            foreach ($statement->fetchAll() as $row) {
                $memberNames[$row['source_id']][(string) $row['display_name']] = true;
            }
        }

        $result = [];
        foreach ($subtypesOf as $typeId => $subtypes) {
            foreach ($subtypes as $subtypeId) {
                foreach ($memberNames[$subtypeId] ?? [] as $name => $_) {
                    $result[$typeId][$name] = true;
                }
            }
        }

        return $result;
    }

    /**
     * Resolve, for candidate methods, how dispatch could reach them without
     * leaving a direct inbound edge — in either direction of the hierarchy.
     *
     * Upwards: an ancestor of the containing type declares a same-named member,
     * so the ancestor carries the contract and the override is reached through
     * it; or the hierarchy leaves an external type whose members static
     * analysis cannot see.
     *
     * Downwards: an internal type implements or extends the containing type and
     * declares a same-named member, so this is the declaration and the
     * implementation is what call sites reach. An edge lands on the declaration
     * only when a receiver is typed as the contract; iterating an untyped array
     * of implementations types nothing, which is why a heavily used interface
     * method can carry an in-degree of zero.
     *
     * @param list<string> $methodIds
     * @param array<string, string> $methodNames method node id => display_name
     * @return array<string, array{inherited: bool, implemented: bool, declaring_type: ?string, external_ancestor: ?string}>
     */
    private function inheritedMethodContext(string $projectId, array $methodIds, array $methodNames): array
    {
        if ($methodIds === []) {
            return [];
        }
        $classOfMethod = [];
        foreach (array_chunk($methodIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                "SELECT source_id, target_id FROM edges WHERE project_id = ? AND kind = 'contains' " .
                sprintf('AND target_id IN (%s)', $placeholders),
            );
            $statement->execute([$projectId, ...$chunk]);
            foreach ($statement->fetchAll() as $row) {
                $classOfMethod[$row['target_id']] = $row['source_id'];
            }
        }
        // Walk the extends/implements closure transitively (bounded depth) so a
        // method overriding a grandparent's member is recognized as inherited,
        // not just one overriding a direct parent's.
        $parents = [];
        $edgesResolved = [];
        $frontier = array_values(array_unique(array_values($classOfMethod)));
        $maxAncestorDepth = 20;
        for ($depth = 0; $depth < $maxAncestorDepth && $frontier !== []; $depth++) {
            $pending = array_values(array_filter($frontier, static fn(string $id): bool => !isset($edgesResolved[$id])));
            if ($pending === []) {
                break;
            }
            $discovered = [];
            foreach (array_chunk($pending, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                // `returns` joins the walk because a factory returning an object
                // literal is how a language without classes writes an
                // implementation: the literal's members are contained by the
                // FUNCTION, and a call site typed as the interface resolves to
                // the interface's member, so the literal's member has no inbound
                // edge and reads as dead. The function's declared return type is
                // the contract it satisfies, which is exactly what `extends` and
                // `implements` say for a class. Only a function carries a
                // `returns` edge, so the class case is untouched.
                $statement = $this->pdo->prepare(
                    "SELECT source_id, target_id FROM edges WHERE project_id = ? AND kind IN ('implements', 'extends', 'returns') " .
                    sprintf('AND source_id IN (%s)', $placeholders),
                );
                $statement->execute([$projectId, ...$chunk]);
                foreach ($statement->fetchAll() as $row) {
                    $parents[$row['source_id']][] = $row['target_id'];
                    $discovered[] = $row['target_id'];
                }
            }
            foreach ($pending as $id) {
                $edgesResolved[$id] = true;
            }
            $frontier = array_values(array_unique($discovered));
        }
        $ancestorIds = array_values(array_unique(array_merge(...array_values($parents) ?: [[]])));
        $ancestorMeta = [];
        foreach (array_chunk($ancestorIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                sprintf('SELECT id, kind, display_name, origin FROM nodes WHERE project_id = ? AND id IN (%s)', $placeholders),
            );
            $statement->execute([$projectId, ...$chunk]);
            foreach ($statement->fetchAll() as $row) {
                $ancestorMeta[$row['id']] = $row;
            }
        }
        $internalAncestors = array_values(array_filter(
            $ancestorIds,
            static fn(string $id): bool => isset($ancestorMeta[$id])
                && !str_starts_with((string) $ancestorMeta[$id]['kind'], 'external_')
                && !in_array($ancestorMeta[$id]['origin'], ['external', 'unresolved'], true),
        ));
        $memberNames = [];
        foreach (array_chunk($internalAncestors, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                'SELECT e.source_id, n.display_name FROM edges e JOIN nodes n ON n.id = e.target_id ' .
                "WHERE e.project_id = ? AND e.kind = 'contains' " .
                sprintf('AND e.source_id IN (%s)', $placeholders),
            );
            $statement->execute([$projectId, ...$chunk]);
            foreach ($statement->fetchAll() as $row) {
                $memberNames[$row['source_id']][(string) $row['display_name']] = true;
            }
        }

        // Iterative transitive-closure of ancestors for a class, memoized.
        $closureCache = [];
        $closureOf = static function (string $classId) use ($parents, &$closureCache): array {
            if (isset($closureCache[$classId])) {
                return $closureCache[$classId];
            }
            $seen = [];
            $stack = $parents[$classId] ?? [];
            while ($stack !== []) {
                $id = array_pop($stack);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                foreach ($parents[$id] ?? [] as $parentId) {
                    if (!isset($seen[$parentId])) {
                        $stack[] = $parentId;
                    }
                }
            }
            $closureCache[$classId] = array_keys($seen);
            return $closureCache[$classId];
        };

        $subtypeMembers = $this->subtypeMemberNames($projectId, array_values(array_unique(array_values($classOfMethod))));

        $result = [];
        foreach ($methodIds as $methodId) {
            $classId = $classOfMethod[$methodId] ?? null;
            $ancestors = $classId === null ? [] : $closureOf($classId);
            $inherited = false;
            $externalAncestor = null;
            sort($ancestors, SORT_STRING);
            foreach ($ancestors as $ancestorId) {
                $meta = $ancestorMeta[$ancestorId] ?? null;
                $isExternal = $meta === null
                    || str_starts_with((string) $meta['kind'], 'external_')
                    || in_array($meta['origin'], ['external', 'unresolved'], true);
                if ($isExternal) {
                    $externalAncestor ??= $meta === null ? 'an unresolved type' : (string) $meta['display_name'];
                    continue;
                }
                if (isset($memberNames[$ancestorId][$methodNames[$methodId]])) {
                    $inherited = true;
                    break;
                }
            }
            $result[$methodId] = [
                'inherited' => $inherited,
                'implemented' => $classId !== null
                    && isset($subtypeMembers[$classId][$methodNames[$methodId]]),
                'declaring_type' => $classId,
                'external_ancestor' => $externalAncestor,
            ];
        }
        return $result;
    }

    /**
     * Undo, from the whole edge table, what a bounded graph walk under-counted.
     *
     * Two distinct things go wrong once the node/edge/time caps drop edges. A
     * dropped inbound edge to a candidate makes a referenced symbol look
     * unreferenced; those candidates are removed here. Less obviously, the
     * exclusions in {@see self::classify()} read the DECLARING type's degrees —
     * a constructor is only excused when its class is used somewhere, a contract
     * method only when its type is used for something other than being
     * implemented — so an under-counted owner promotes an ordinary constructor
     * to a `probable` dead-code claim. Scanning this repository under the CLI's
     * default 20,000-edge cap did exactly that to
     * `LaravelContainerFactCollector::__construct`, which is instantiated one
     * file away. Owner degrees are therefore re-read authoritatively too.
     *
     * @param array<string, array{component: array<string, mixed>, row: array<string, mixed>, roles: list<array<string, mixed>>, out_degree: int}> $provisional
     * @param array<string, array<string, mixed>> $nodes
     * @param list<string> $edgeKinds
     * @param array<string, array{in_degree: int, out_degree: int, cross_boundary_degree: int}> $metrics
     * @param array<string, int> $inheritanceInDegree
     *
     * @return array<string, array{component: array<string, mixed>, row: array<string, mixed>, roles: list<array<string, mixed>>, out_degree: int}> the surviving candidates
     */
    public function reconcileBoundedWalk(string $projectId, array $provisional, array $nodes, array $edgeKinds, int $minConfidenceRank, array &$metrics, array &$inheritanceInDegree): array
    {
        foreach ($this->referencedNodes($projectId, array_keys($provisional), $edgeKinds, $minConfidenceRank) as $referencedId) {
            unset($provisional[$referencedId]);
        }
        $idsByCanonicalName = self::indexByCanonicalName($nodes);
        $owners = [];
        foreach ($provisional as $candidate) {
            $ownerId = $idsByCanonicalName[self::owningTypeName((string) $candidate['row']['canonical_name']) ?? ''] ?? null;
            if ($ownerId !== null && isset($metrics[$ownerId]) && $metrics[$ownerId]['in_degree'] === 0) {
                $owners[$ownerId] = $metrics[$ownerId];
            }
        }
        foreach ($this->inboundDegrees($projectId, array_keys($owners), $edgeKinds, $minConfidenceRank) as $ownerId => $degrees) {
            $metrics[$ownerId] = ['in_degree' => $degrees['in_degree']] + $owners[$ownerId];
            $inheritanceInDegree[$ownerId] = $degrees['inheritance_in_degree'];
        }

        return $provisional;
    }

    /**
     * The canonical name of the type a member belongs to, or null when the name
     * is not a member name at all.
     *
     * The LAST `::` separates them: a canonical name may carry one earlier (a
     * closure declared inside a method, for instance), and splitting on the
     * first would name a type that does not exist.
     */
    private static function owningTypeName(string $canonicalName): ?string
    {
        $separator = strrpos($canonicalName, '::');

        return $separator === false || $separator === 0 ? null : substr($canonicalName, 0, $separator);
    }

    /**
     * Node ids keyed by canonical name, for resolving an owning type to its node.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @return array<string, string>
     */
    private static function indexByCanonicalName(array $nodes): array
    {
        $ids = [];
        foreach ($nodes as $nodeId => $nodeRow) {
            $ids[(string) $nodeRow['canonical_name']] = $nodeId;
        }

        return $ids;
    }

    /**
     * Authoritative inbound-edge counts for the given nodes, read from the whole
     * edge table rather than the slice a bounded walk managed to read.
     *
     * Inheritance is counted separately because the contract exclusion has to
     * discount it: a type being implemented is not evidence that anything uses it.
     *
     * @param list<string> $nodeIds
     * @param list<string> $edgeKinds
     * @return array<string, array{in_degree: int, inheritance_in_degree: int}>
     */
    private function inboundDegrees(string $projectId, array $nodeIds, array $edgeKinds, int $minConfidenceRank): array
    {
        $degrees = [];
        foreach (array_chunk($nodeIds, 500) as $chunk) {
            $targets = implode(',', array_fill(0, count($chunk), '?'));
            $kinds = implode(',', array_fill(0, count($edgeKinds), '?'));
            $statement = $this->pdo->prepare(
                'SELECT target_id, kind, COUNT(*) AS edge_count FROM edges WHERE project_id = ? ' .
                sprintf('AND kind IN (%s) ', $kinds) .
                "AND CASE confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
                sprintf('AND target_id IN (%s) GROUP BY target_id, kind', $targets),
            );
            $statement->execute([$projectId, ...$edgeKinds, $minConfidenceRank, ...$chunk]);
            foreach ($statement->fetchAll() as $row) {
                $id = (string) $row['target_id'];
                $count = (int) $row['edge_count'];
                $degrees[$id] ??= ['in_degree' => 0, 'inheritance_in_degree' => 0];
                $degrees[$id]['in_degree'] += $count;
                if (in_array((string) $row['kind'], ['implements', 'extends'], true)) {
                    $degrees[$id]['inheritance_in_degree'] += $count;
                }
            }
        }

        return $degrees;
    }

    /**
     * Which of the given nodes have at least one inbound edge in the full edge
     * table, unconstrained by the scan's node/edge budget. Used to clear
     * dead-code candidates whose in-degree was only zero because the slice the
     * health scan read stopped short of the edges pointing at them.
     *
     * @param list<string> $nodeIds
     * @param list<string> $edgeKinds
     * @return list<string>
     */
    private function referencedNodes(string $projectId, array $nodeIds, array $edgeKinds, int $minConfidenceRank): array
    {
        $referenced = [];
        foreach (array_chunk($nodeIds, 500) as $chunk) {
            $targets = implode(',', array_fill(0, count($chunk), '?'));
            $kinds = implode(',', array_fill(0, count($edgeKinds), '?'));
            $statement = $this->pdo->prepare(
                'SELECT DISTINCT target_id FROM edges WHERE project_id = ? ' .
                sprintf('AND kind IN (%s) ', $kinds) .
                "AND CASE confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
                sprintf('AND target_id IN (%s)', $targets),
            );
            $statement->execute([$projectId, ...$edgeKinds, $minConfidenceRank, ...$chunk]);
            foreach ($statement->fetchAll() as $row) {
                $referenced[] = (string) $row['target_id'];
            }
        }
        return $referenced;
    }
    /**
     * Canonical names the project's own configuration suppresses, exactly or by prefix.
     *
     * @return list<string>
     */
    private function deadCodeSuppressions(string $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT config_json FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        $raw = $statement->fetchColumn();
        if (!is_string($raw)) {
            return [];
        }
        $config = json_decode($raw, true);
        $list = is_array($config) ? ($config['dead_code_suppressions'] ?? []) : [];
        if (!is_array($list) || !array_is_list($list)) {
            return [];
        }
        return array_values(array_filter($list, 'is_string'));
    }

    /**
     * Durable agent judgements recorded against a component.
     *
     * @return array<string, array{kind: string, value: string}> keyed by canonical name; false_positive wins over confirmed_dead
     */
    private function componentAnnotations(string $projectId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT canonical_name, kind, value FROM annotations WHERE project_id = :project AND kind IN ('false_positive', 'confirmed_dead') " .
            'ORDER BY canonical_name, kind DESC', // 'false_positive' > 'confirmed_dead' alphabetically DESC
        );
        $statement->execute(['project' => $projectId]);
        $byName = [];
        foreach ($statement->fetchAll() as $row) {
            $byName[$row['canonical_name']] ??= ['kind' => $row['kind'], 'value' => $row['value']];
        }
        return $byName;
    }

    /**
     * Whether an annotation excludes this component from dead-code reporting.
     *
     * @param list<string> $suppressions
     */
    private static function isSuppressed(string $canonicalName, array $suppressions): bool
    {
        foreach ($suppressions as $pattern) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($canonicalName, substr($pattern, 0, -1))) {
                    return true;
                }
            } elseif ($pattern === $canonicalName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a framework convention explains the node, which usually means a framework calls it.
     *
     * @param list<array<string, mixed>> $roles
     */
    private function hasFrameworkRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if (str_starts_with($role['role'], 'laravel.') || str_starts_with($role['origin'], 'framework_')) {
                return true;
            }
        }
        return false;
    }
}
