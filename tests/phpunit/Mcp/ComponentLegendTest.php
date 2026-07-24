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
}
