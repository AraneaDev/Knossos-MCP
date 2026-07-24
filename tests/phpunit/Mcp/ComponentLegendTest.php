<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\ComponentLegend;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ResultEnvelope;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class ComponentLegendTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testHoistsRepeatedNodeDescriptorsToNameReferences(): void
    {
        $node = [
            'id' => 'symbol_abc', 'kind' => 'method', 'canonical_name' => 'A\\B::c',
            'display_name' => 'c', 'origin' => 'ast', 'confidence' => 'certain',
            'roles' => [['role' => 'application.service', 'origin' => 'derived', 'confidence' => 'probable']],
            'boundaries' => ['boundary_1'],
        ];
        $data = ['direct' => [$node], 'grouped' => [['node' => $node, 'distance' => 1]]];

        [$out, $legend] = ComponentLegend::compress($data);

        // Every inline occurrence becomes the canonical-name string.
        assertSame('A\\B::c', $out['direct'][0]);
        assertSame('A\\B::c', $out['grouped'][0]['node']);
        assertSame(1, $out['grouped'][0]['distance']);
        // The legend holds one identity descriptor with the allowlisted fields
        // preserved (kind/confidence/origin/boundaries/roles); the opaque id and
        // the redundant display_name are dropped.
        assertSame(['A\\B::c'], array_keys($legend));
        assertSame('method', $legend['A\\B::c']['kind']);
        assertSame('certain', $legend['A\\B::c']['confidence']);
        assertSame('ast', $legend['A\\B::c']['origin']);
        assertSame(['boundary_1'], $legend['A\\B::c']['boundaries']);
        assertSame(['application.service'], $legend['A\\B::c']['roles']);
        assertSame(false, array_key_exists('id', $legend['A\\B::c']));
        assertSame(false, array_key_exists('display_name', $legend['A\\B::c']));
    }

    #[Group('mcp')]
    public function testHoistedDescriptorPreservesAttributes(): void
    {
        // find_component / inspect_component nodes carry origin + attributes
        // (visibility, static, abstract, extends, …). Those are allowlisted by
        // IDENTITY_KEYS, so hoisting must keep them, not silently drop them.
        $node = [
            'id' => 'symbol_m', 'kind' => 'method', 'canonical_name' => 'A\\B::m',
            'display_name' => 'm', 'origin' => 'ast',
            'attributes' => ['visibility' => 'public', 'static' => false, 'abstract' => false],
        ];
        [$out, $legend] = ComponentLegend::compress(['items' => [$node]]);

        assertSame('A\\B::m', $out['items'][0]);
        assertSame(['visibility' => 'public', 'static' => false, 'abstract' => false], $legend['A\\B::m']['attributes']);
        assertSame('ast', $legend['A\\B::m']['origin']);
    }

    #[Group('mcp')]
    public function testRichObjectsWithPayloadKeysAreNotCollapsed(): void
    {
        // Regression: component objects shaped like inspect_component's root component
        // have id+kind+canonical_name PLUS payload keys like outgoing/children.
        // They must NOT be collapsed to a string; nested pure descriptors inside them ARE hoisted.
        $pureDescriptor = [
            'id' => 'symbol_child', 'kind' => 'class', 'canonical_name' => 'Child\\Class',
            'display_name' => 'Class', 'origin' => 'ast', 'confidence' => 'certain',
        ];
        $richComponent = [
            'id' => 'symbol_parent', 'kind' => 'class', 'canonical_name' => 'Parent\\Component',
            'display_name' => 'Component', 'origin' => 'ast', 'confidence' => 'certain',
            'outgoing' => [
                ['component' => $pureDescriptor, 'distance' => 1],
            ],
            'children' => [
                ['component' => $pureDescriptor],
            ],
        ];
        $data = ['component' => $richComponent];

        [$out, $legend] = ComponentLegend::compress($data);

        // Rich object is NOT collapsed to a string; its structure is preserved (because it has payload keys).
        assertSame(true, is_array($out['component']));
        assertSame('Parent\\Component', $out['component']['canonical_name']);
        // Nested pure descriptors are hoisted to name strings.
        assertSame('Child\\Class', $out['component']['outgoing'][0]['component']);
        assertSame('Child\\Class', $out['component']['children'][0]['component']);
        assertSame(1, $out['component']['outgoing'][0]['distance']);
        // Only the nested pure child descriptor appears in the legend (parent is NOT hoisted due to payload keys).
        assertSame(['Child\\Class'], array_keys($legend));
        assertSame('class', $legend['Child\\Class']['kind']);
    }

    #[Group('mcp')]
    public function testDisplayNameFallbackWhenCanonicalNameAbsent(): void
    {
        // A pure descriptor without canonical_name is hoisted under display_name + last 8 chars of id.
        $node = [
            'id' => 'symbol_xyz_1234', 'kind' => 'function', 'display_name' => 'myFunc',
            'confidence' => 'certain',
        ];
        $data = ['funcs' => [$node]];

        [$out, $legend] = ComponentLegend::compress($data);

        // Name is display_name + suffix.
        $names = array_keys($legend);
        assertSame(1, count($names));
        $name = $names[0];
        assertSame(true, str_starts_with($name, 'myFunc#'));
        assertSame('function', $legend[$name]['kind']);
        assertSame('certain', $legend[$name]['confidence']);
        // Reference in data uses the same key.
        assertSame($name, $out['funcs'][0]);
    }

    #[Group('mcp')]
    public function testStringRolesBranchKeepsRolesAsIs(): void
    {
        // Roles can be either array-of-objects or array-of-strings. Both are extracted as strings.
        $nodeWithStringRoles = [
            'id' => 'symbol_roles', 'kind' => 'class', 'canonical_name' => 'WithStringRoles',
            'roles' => ['role.a', 'role.b'],
        ];
        $data = ['item' => $nodeWithStringRoles];

        [$out, $legend] = ComponentLegend::compress($data);

        assertSame('WithStringRoles', $out['item']);
        assertSame(['role.a', 'role.b'], $legend['WithStringRoles']['roles']);
    }

    #[Group('mcp')]
    public function testCompressesViaEdgeObjectsToKindStrings(): void
    {
        $data = ['rec' => ['node' => [
            'id' => 'symbol_x', 'kind' => 'method', 'canonical_name' => 'X::y',
        ], 'via' => [
            'edge_id' => 'edge_1', 'kind' => 'calls', 'source_id' => 'symbol_x',
            'target_id' => 'symbol_z', 'origin' => 'ast',
            'explanation' => 'y depends through --calls--> z',
        ]]];

        [$out] = ComponentLegend::compress($data);

        assertSame('calls', $out['rec']['via']);
        assertSame('X::y', $out['rec']['node']);
    }

    #[Group('mcp')]
    public function testEnrichCompactHoistsComponentsButFullDoesNot(): void
    {
        $enricher = new ResultEnricher(
            new StalenessProbe($this->storeFixture()[0]),
            new NextStepPlanner(),
        );
        $node = ['id' => 'symbol_q', 'kind' => 'class', 'canonical_name' => 'Q', 'display_name' => 'Q', 'confidence' => 'certain'];
        $envelope = new ResultEnvelope('p', 's', 'sum', ['items' => [$node, $node]]);

        $compact = $enricher->enrich($envelope, 'find_component', 'compact')->jsonSerialize();
        assertSame(['Q', 'Q'], $compact['data']['items']);
        assertSame('class', $compact['data']['component_legend']['Q']['kind']);

        $full = $enricher->enrich($envelope, 'find_component', 'full')->jsonSerialize();
        assertSame('symbol_q', $full['data']['items'][0]['id']); // full keeps descriptors + ids
        assertSame(false, array_key_exists('component_legend', $full['data']));
    }

    #[Group('mcp')]
    public function testEveryNameReferenceResolvesInTheComponentsLegend(): void
    {
        // The brief's literal test walks toolServiceWithScannedFixture() (the
        // tests/Fixtures/mixed fixture), but that fixture's files carry no
        // cross-file references, so impact_analysis there returns zero
        // dependants and the round-trip walk below would assert nothing --
        // a vacuous pass. storeFixture() ships a real Checkout->InvoiceService
        // `calls` edge (see McpTest::testImpactAnalysisReturnsFlatDependantsAndCounts),
        // so impact_analysis on InvoiceService yields a genuine distance-1
        // dependant whose `node` is hoisted to a canonical-name string by
        // ComponentLegend in the compact (default) envelope.
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $svc = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );
        $env = $svc->call(
            'impact_analysis',
            ['project_id' => $ids['project'], 'symbol' => 'InvoiceService'],
            new CancellationToken(static fn(): bool => false),
        );
        $data = $env->jsonSerialize()['data'];

        // Collect every name-reference the compact envelope emits: `node` keys
        // (dependants[].node) and any `target` keys, mirroring how
        // ComponentLegend::compress() hoists descriptors throughout the tree.
        $names = [];
        array_walk_recursive($data, static function ($value, $key) use (&$names): void {
            if (($key === 'node' || $key === 'target') && is_string($value)) {
                $names[] = $value;
            }
        });

        // Non-vacuousness guard: this fixture's edge must actually surface at
        // least one name-reference for the resolution assertions below to mean
        // anything.
        assertSame(true, count($names) > 0, 'Expected at least one compacted name-reference to walk.');

        foreach ($names as $name) {
            assertSame(true, array_key_exists('component_legend', $data), 'component_legend missing from response data.');
            assertSame(true, array_key_exists($name, $data['component_legend']), $name . ' missing from component_legend.');
            assertSame(true, array_key_exists('kind', $data['component_legend'][$name]), $name . ' legend entry is not a descriptor (no kind).');
        }
    }

    #[Group('mcp')]
    public function testCompactImpactAnalysisRewritesDependantIdToName(): void
    {
        // storeFixture() ships a real Checkout->InvoiceService `calls` edge, so
        // impact_analysis('InvoiceService') yields a distance-1 dependant
        // (Checkout) with edge-level evidence. In compact mode, ComponentLegend
        // hoists that dependant's node to a bare name string, so its evidence's
        // dependant_id (which pointed at the now-gone inline node) must be
        // rewritten to a `dependant` name reference that resolves in
        // component_legend -- otherwise the evidence path:line is unjoinable.
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $svc = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );
        $env = $svc->call(
            'impact_analysis',
            ['project_id' => $ids['project'], 'symbol' => 'InvoiceService'],
            new CancellationToken(static fn(): bool => false),
        );
        $json = $env->jsonSerialize();
        $data = $json['data'];
        $evidence = $json['evidence'];

        assertSame(true, count($evidence) > 0, 'Expected at least one evidence entry to exercise the rewrite.');
        assertSame(true, array_key_exists('component_legend', $data), 'component_legend missing from response data.');

        foreach ($evidence as $entry) {
            assertSame(false, array_key_exists('dependant_id', $entry), 'dependant_id should not dangle in compact evidence.');
            assertSame(true, array_key_exists('dependant', $entry), 'dependant name reference missing from compact evidence.');
            assertSame(true, array_key_exists($entry['dependant'], $data['component_legend']), $entry['dependant'] . ' missing from component_legend.');
        }
    }

    #[Group('mcp')]
    public function testFindComponentCompactCandidatesAreNamesWithResolvableEvidence(): void
    {
        // Drives find_component through the real compact dispatch path
        // (ToolService::call, default verbosity). Its `components` list entries
        // must be hoisted to canonical-name strings resolving in
        // component_legend, and (Fix 1) its evidence must reference names, not
        // dangling component_id values.
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $svc = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );
        $env = $svc->call(
            'find_component',
            ['project_id' => $ids['project'], 'name' => 'Checkout'],
            new CancellationToken(static fn(): bool => false),
        );
        $json = $env->jsonSerialize();
        $data = $json['data'];
        $evidence = $json['evidence'];

        assertSame(true, count($data['components']) > 0, 'Expected at least one find_component candidate.');
        assertSame(true, array_key_exists('component_legend', $data), 'component_legend missing from response data.');

        foreach ($data['components'] as $component) {
            assertSame(true, is_string($component), 'Compact find_component candidate should be a hoisted name string.');
            assertSame(true, array_key_exists($component, $data['component_legend']), $component . ' missing from component_legend.');
        }

        assertSame(true, count($evidence) > 0, 'Expected at least one evidence entry to exercise the rewrite.');
        foreach ($evidence as $entry) {
            assertSame(false, array_key_exists('component_id', $entry), 'component_id should not dangle in compact evidence.');
            assertSame(true, array_key_exists('component', $entry), 'component name reference missing from compact evidence.');
            assertSame(true, array_key_exists($entry['component'], $data['component_legend']), $entry['component'] . ' missing from component_legend.');
        }
    }
}
