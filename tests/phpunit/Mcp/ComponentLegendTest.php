<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\ComponentLegend;
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
        // The legend holds one identity descriptor, no opaque id / display_name / origin.
        assertSame(['A\\B::c'], array_keys($legend));
        assertSame('method', $legend['A\\B::c']['kind']);
        assertSame('certain', $legend['A\\B::c']['confidence']);
        assertSame(['boundary_1'], $legend['A\\B::c']['boundaries']);
        assertSame(['application.service'], $legend['A\\B::c']['roles']);
        assertSame(false, array_key_exists('id', $legend['A\\B::c']));
        assertSame(false, array_key_exists('display_name', $legend['A\\B::c']));
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
}
