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
     * Node kinds an unreferenced-code candidate can be. Anything else — a route,
     * a config value, a file — is not a unit anyone deletes on this evidence.
     */
    public const CANDIDATE_KINDS = ['class', 'interface', 'trait', 'enum', 'function', 'method', 'module'];

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
     * @param array<string, array{component: array<string, mixed>, row: array<string, mixed>, roles: list<array<string, mixed>>, out_degree: int, reachability: string}> $provisional
     * @param list<string> $edgeKinds
     * @param bool $includeTests Treat test-only member reachability as live.
     *
     * @return array{candidates: list<array<string, mixed>>, excluded: array<string, int>}
     */
    public function classify(string $projectId, array $provisional, CandidateGraphFacts $facts, array $edgeKinds, int $minConfidenceRank, bool $includeTests = false): array
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
        $untypedCalls = $facts->untypedMemberNames();
        $excludedConstructors = 0;
        $excludedContracts = 0;
        $excludedEntryScripts = 0;
        $excludedTypeDeclarations = 0;
        $suppressions = $facts->deadCodeSuppressions();
        $suppressedCount = 0;
        $annotationsByName = $facts->componentAnnotations();
        $annotatedFalsePositives = 0;
        $memberReachability = $this->containerMemberReachability($projectId, $provisional, $edgeKinds, $minConfidenceRank);
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
                'uncontracted_literal' => false,
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
                $declaringUses = $facts->inDegree($declaringType) - $facts->inheritanceInDegree($declaringType);
                if ($declaringUses > 0) {
                    ++$excludedContracts;
                    continue;
                }
            }
            // Marked by its scanner as called by a runtime or a foreign host
            // (`Drop::drop`, a `#[no_mangle]` export): no source names it,
            // however live it is. Counted with the engine-invoked members.
            if (self::isEngineInvokedMemberOfReferencedType($candidate['row'], $facts)
                || ReportableComponent::isRuntimeInvoked($candidate['row']['attributes_json'] ?? null)) {
                ++$excludedConstructors;
                continue;
            }
            // A script's body is run by something outside the graph, so nothing
            // in the codebase references it and "unreferenced" carries no
            // information about whether it is wanted. An ordinary module that
            // nothing imports stays reportable — an orphaned one is precisely
            // what this analysis exists to surface.
            if (ReportableComponent::isExecutableScript((string) $candidate['row']['kind'], $candidate['row']['attributes_json'] ?? null)
                || self::isPackageInitWithModules($candidate['row'], $facts)) {
                ++$excludedEntryScripts;
                continue;
            }
            // A `.d.ts` declares what some other file implements. Its symbols
            // have an in-degree of zero by construction — call sites resolve to
            // the implementation — so reporting them is noise, and acting on
            // the report would break the build.
            if (ReportableComponent::isTypeDeclaration($candidate['row']['attributes_json'] ?? null)) {
                ++$excludedTypeDeclarations;
                continue;
            }
            // A class/module is often reached through a member call rather than
            // by a direct edge to the container. Direct in-degree alone then
            // reports the live container as dead beside its live method. Ignore
            // member references originating inside the same container: internal
            // self-use does not make an otherwise orphaned type live.
            $memberUse = $memberReachability[$id] ?? null;
            if ($memberUse !== null && ($memberUse['production'] || $includeTests)) {
                continue;
            }
            $dynamicRisk = $candidate['row']['origin'] !== 'ast' || $this->hasFrameworkRole($candidate['roles']);
            $confidence = $dynamicRisk ? 'possible' : 'probable';
            $reachability = $candidate['reachability'] ?? 'unreferenced';
            if ($memberUse !== null && $reachability === 'unreferenced') {
                $reachability = 'test_only';
            }
            $reason = $reachability === 'test_only'
                ? 'The only inbound static references come from test code, so nothing the product runs reaches this.'
                : 'No inbound static reference was found among the selected edge kinds.';
            // A call by this name on a receiver its scanner could not type may
            // be the one that reaches it, so the absence of an edge proves less.
            if ($confidence === 'probable'
                && in_array($candidate['row']['kind'], ['method', 'function'], true)
                && isset($untypedCalls[(string) $candidate['row']['display_name']])) {
                $confidence = 'possible';
                $reason = 'No inbound static reference was found, but a member of this name is called on a receiver the scan could not type, which may be this one.';
            }
            if ($context['uncontracted_literal'] && $confidence === 'probable') {
                $confidence = 'possible';
                $reason = 'No inbound static reference was found, but this method belongs to an object literal handed to other code with no type naming its methods; whatever receives the literal may call it.';
            }
            if ($context['external_ancestor'] !== null) {
                $confidence = 'possible';
                $reason = sprintf(
                    'No inbound static reference was found, but the declaring type extends or implements %s, whose members are not statically visible; dispatch may reach this method.',
                    $context['external_ancestor'],
                );
            }
            $entry = [
                'component' => $candidate['component'],
                'reachability' => $reachability,
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
                'type_declarations' => $excludedTypeDeclarations,
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
     * @param array<string, mixed> $node
     */
    private static function isEngineInvokedMemberOfReferencedType(array $node, CandidateGraphFacts $facts): bool
    {
        if ($node['kind'] !== 'method') {
            return false;
        }
        $displayName = (string) $node['display_name'];
        if ($displayName !== self::CONSTRUCTOR_MEMBER_NAME && !str_starts_with($displayName, '__')) {
            return false;
        }
        $owner = self::owningTypeName((string) $node['canonical_name']);
        $ownerId = $owner === null ? null : $facts->idOf($owner);

        return $ownerId !== null && $facts->inDegree($ownerId) > 0;
    }

    /**
     * A Python package's `__init__` module whose package holds other modules.
     *
     * The import system runs it whenever any module in the package is
     * imported, and nothing names it; if none of those modules is used either,
     * they carry the report.
     *
     * @param array<string, mixed> $node
     */
    private static function isPackageInitWithModules(array $node, CandidateGraphFacts $facts): bool
    {
        if ($node['kind'] !== 'module' || !is_string($node['attributes_json'] ?? null)) {
            return false;
        }
        $attributes = json_decode($node['attributes_json'], true);
        if (!is_array($attributes) || ($attributes['package_init'] ?? false) !== true) {
            return false;
        }
        return $facts->hasCanonicalPrefix($node['canonical_name'] . '.');
    }

    /**
     * Whether nothing references a node. Absence of evidence, not proof: reflection is invisible here.
     *
     * @param array<string, mixed> $node @param list<array<string, mixed>> $roles
     */
    public function isCandidate(array $node, array $roles): bool
    {
        return self::isReportableUnit($node)
            && !ReportableComponent::isDiscoveredByConvention(array_column($roles, 'role'));
    }

    /**
     * Whether a node was kept out of the candidate set ONLY because a role marks
     * it as reached by convention — a controller, command, listener, job, entry
     * point, test, or config.
     *
     * Exists so that exclusion can be COUNTED. {@link isCandidate} runs before
     * {@link classify}, so anything it turns away never reaches the tallies
     * `classify` returns: a role-excluded node was dropped in silence, and
     * `bounds` reported a set of reasons that did not add up to the nodes
     * actually removed. `excluded_entry_scripts` looked like it covered this and
     * did not — that counter is driven by
     * {@link ReportableComponent::isExecutableScript}, which keys off a scanner's
     * `executable` ATTRIBUTE, a different signal from these ROLES. A project
     * whose entry point is marked by role rather than by attribute therefore
     * reported zero exclusions while excluding one.
     *
     * This and {@link isCandidate} are the two halves of one partition, so they
     * share {@link isReportableUnit} rather than restating its guards. Restating
     * them is what let the original bug in: a node turned away for a DIFFERENT
     * reason must not be counted here, and two copies of that test drift apart
     * the moment one of them gains a guard.
     *
     * @param array<string, mixed> $node @param list<array<string, mixed>> $roles
     */
    public function isConventionExcluded(array $node, array $roles): bool
    {
        return self::isReportableUnit($node)
            && ReportableComponent::isDiscoveredByConvention(array_column($roles, 'role'));
    }

    /**
     * Whether a node is the kind of thing this analysis reports on at all,
     * before any question of who references it.
     *
     * @param array<string, mixed> $node
     */
    private static function isReportableUnit(array $node): bool
    {
        return in_array($node['kind'], self::CANDIDATE_KINDS, true)
            && !ReportableComponent::isExternal((string) $node['kind'], $node['origin']);
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
     * An object literal handed around as a value with no type at all is the
     * third way: the code it is handed to calls its methods unseen.
     *
     * @return array<string, array{inherited: bool, implemented: bool, declaring_type: ?string, external_ancestor: ?string, uncontracted_literal: bool}>
     */
    private function inheritedMethodContext(string $projectId, array $methodIds, array $methodNames): array
    {
        if ($methodIds === []) {
            return [];
        }
        $classOfMethod = [];
        $kindOfClass = [];
        foreach (array_chunk($methodIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                "SELECT e.source_id, e.target_id, n.kind FROM edges e JOIN nodes n ON n.id = e.source_id WHERE e.project_id = ? AND e.kind = 'contains' " .
                sprintf('AND e.target_id IN (%s)', $placeholders),
            );
            $statement->execute([$projectId, ...$chunk]);
            foreach ($statement->fetchAll() as $row) {
                $classOfMethod[$row['target_id']] = $row['source_id'];
                $kindOfClass[$row['source_id']] = (string) $row['kind'];
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
        $handedBindings = $this->referencedBindings($projectId, array_keys(array_filter($kindOfClass, static fn(string $kind): bool => $kind === 'variable')));

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
                // An object literal passed, returned or nested as a value,
                // with no type naming its methods: whatever receives it may
                // call them, and no scan of this project sees that call. A
                // binding holding one goes the same way once it is handed on.
                'uncontracted_literal' => $classId !== null
                    && (($kindOfClass[$classId] ?? null) === 'object' || isset($handedBindings[$classId]))
                    && $ancestors === [],
            ];
        }
        return $result;
    }

    /**
     * The bindings among `$bindingIds` something reads as a value.
     *
     * @param list<string> $bindingIds
     * @return array<string, true>
     */
    private function referencedBindings(string $projectId, array $bindingIds): array
    {
        $referenced = [];
        foreach (array_chunk($bindingIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                "SELECT DISTINCT target_id FROM edges WHERE project_id = ? AND kind = 'references' " .
                sprintf('AND target_id IN (%s)', $placeholders),
            );
            $statement->execute([$projectId, ...$chunk]);
            foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $referenced[(string) $id] = true;
            }
        }

        return $referenced;
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
     * Whether a provisional container is reached through one of its members.
     *
     * The health walk measures inbound degree on the target node itself. A
     * call to `Service::run` increments the method's degree, not the class's,
     * even though the class is the unit that owns the executable member. This
     * query lifts that evidence to the container while excluding calls from
     * the container's own member subtree, which only prove internal self-use.
     *
     * @param array<string, array{row: array<string, mixed>}> $provisional
     * @param list<string> $edgeKinds
     * @return array<string, array{any: bool, production: bool}>
     */
    private function containerMemberReachability(string $projectId, array $provisional, array $edgeKinds, int $minConfidenceRank): array
    {
        $containerIds = [];
        foreach ($provisional as $id => $candidate) {
            if (in_array($candidate['row']['kind'], ['class', 'interface', 'trait', 'enum', 'module'], true)) {
                $containerIds[] = (string) $id;
            }
        }
        if ($containerIds === []) {
            return [];
        }

        $reachability = [];
        $kinds = implode(',', array_fill(0, count($edgeKinds), '?'));
        $testRole = $this->pdo->quote(ReportableComponent::TEST_ROLE);
        foreach (array_chunk($containerIds, 500) as $chunk) {
            $containers = implode(',', array_fill(0, count($chunk), '?'));
            // The join order and the indexes are fixed (`CROSS JOIN` keeps
            // `members` outside): without planner statistics, which a freshly
            // scanned store has none of, SQLite put the edge table outside and
            // walked every edge of the project for each member, so a project
            // of 25,000 containers took half a second per chunk.
            $statement = $this->pdo->prepare(
                'WITH RECURSIVE members(container_id, member_id) AS (' .
                'SELECT source_id, target_id FROM edges INDEXED BY edges_project_source_idx WHERE project_id = ? AND kind = \'contains\' AND source_id IN (' . $containers . ') ' .
                'UNION ' .
                'SELECT members.container_id, child.target_id FROM members CROSS JOIN edges child INDEXED BY edges_project_source_idx ON child.project_id = ? AND child.kind = \'contains\' AND child.source_id = members.member_id' .
                ') ' .
                'SELECT members.container_id, COUNT(*) AS any_reference, ' .
                'MAX(CASE WHEN NOT EXISTS (SELECT 1 FROM classifications c WHERE c.node_id = usage.source_id AND c.role = ' . $testRole . ') THEN 1 ELSE 0 END) AS production_reference ' .
                'FROM members CROSS JOIN edges usage INDEXED BY edges_project_target_idx ON usage.project_id = ? AND usage.target_id = members.member_id ' .
                sprintf('AND usage.kind IN (%s) ', $kinds) .
                "AND CASE usage.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
                'WHERE usage.source_id <> members.container_id ' .
                'AND NOT EXISTS (SELECT 1 FROM members internal WHERE internal.container_id = members.container_id AND internal.member_id = usage.source_id) ' .
                'GROUP BY members.container_id',
            );
            $statement->execute([$projectId, ...$chunk, $projectId, $projectId, ...$edgeKinds, $minConfidenceRank]);
            foreach ($statement->fetchAll() as $row) {
                $reachability[(string) $row['container_id']] = [
                    'any' => (int) $row['any_reference'] > 0,
                    'production' => (int) $row['production_reference'] > 0,
                ];
            }
        }

        return $reachability;
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
