<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\ComponentLegend;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the legend treats as a component, what it treats as an edge, and what it
 * leaves alone.
 *
 * ComponentLegend scored 74% under mutation testing. Its tests feed it
 * well-formed nodes and assert the hoisting, so the predicates that decide
 * whether a map is a descriptor at all, the three shapes an edge can take, the
 * fallback key for a node with no canonical name, and the flattening of role
 * entries could all change with them green. Every case here is about the
 * boundary of those predicates rather than the compression itself.
 */
final class ComponentLegendShapeTest extends KnossosTestCase
{
    /** An edge is shortened to its kind under `via`, and nowhere else. */
    #[Group('mcp')]
    public function testOnlyViaShortensAnEdgeToItsKind(): void
    {
        $edge = ['kind' => 'calls', 'source_id' => 'symbol_a', 'target_id' => 'symbol_b'];

        [$out] = ComponentLegend::compress(['via' => $edge, 'relationship' => $edge]);

        assertSame('calls', $out['via']);
        assertSame($edge, $out['relationship'], 'The same edge under another key is left as it was.');
    }

    /** The keys after `via` are still walked, rather than the walk stopping there. */
    #[Group('mcp')]
    public function testKeysAfterViaAreStillCompressed(): void
    {
        $data = [
            'via' => ['kind' => 'calls', 'source_id' => 'symbol_a', 'target_id' => 'symbol_b'],
            'component' => ['id' => 'symbol_c', 'kind' => 'class', 'canonical_name' => 'App\\Thing'],
        ];

        [$out, $legend] = ComponentLegend::compress($data);

        assertSame('calls', $out['via']);
        assertSame('App\\Thing', $out['component'], 'A descriptor after the via key must still be hoisted.');
        assertSame(['App\\Thing'], array_keys($legend));
    }

    /**
     * A map is a descriptor only if both its id and its kind are strings.
     *
     * Every key here is allowlisted, so nothing but the type check stands
     * between this and being hoisted under a legend key of `123`.
     */
    #[Group('mcp')]
    public function testAMapWhoseKindIsNotAStringIsNotADescriptor(): void
    {
        $notADescriptor = ['id' => 'symbol_a', 'kind' => 123, 'canonical_name' => 'App\\Thing'];

        [$out, $legend] = ComponentLegend::compress(['item' => $notADescriptor]);

        assertSame($notADescriptor, $out['item']);
        assertSame([], $legend);
    }

    /** An edge is recognised by its endpoints, by an edge id, or by an `edge_` id. */
    #[Group('mcp')]
    public function testAnEdgeIsRecognisedByAnyOfItsThreeShapes(): void
    {
        $shapes = [
            'endpoints' => ['kind' => 'calls', 'source_id' => 'symbol_a', 'target_id' => 'symbol_b'],
            'edge id' => ['kind' => 'calls', 'edge_id' => 'edge_1'],
            'prefixed id' => ['kind' => 'calls', 'id' => 'edge_1'],
        ];
        foreach ($shapes as $label => $edge) {
            [$out] = ComponentLegend::compress(['via' => $edge]);
            assertSame('calls', $out['via'], $label);
        }

        // A kind on its own identifies nothing, so there is nothing to shorten.
        $kindOnly = ['kind' => 'calls'];
        [$out] = ComponentLegend::compress(['via' => $kindOnly]);
        assertSame($kindOnly, $out['via']);
    }

    /**
     * A node with no canonical name is keyed by its display name and the tail of
     * its id, which is what keeps two same-named nodes apart.
     */
    #[Group('mcp')]
    public function testANodeWithoutACanonicalNameIsKeyedByItsDisplayNameAndIdTail(): void
    {
        $node = ['id' => 'symbol_0123456789abcdef', 'kind' => 'class', 'display_name' => 'Thing'];

        [$out, $legend] = ComponentLegend::compress(['component' => $node]);

        assertSame('Thing#89abcdef', $out['component']);
        assertSame(['Thing#89abcdef'], array_keys($legend));
        assertSame('class', $legend['Thing#89abcdef']['kind']);
    }

    /** An empty canonical name falls back the same way a missing one does. */
    #[Group('mcp')]
    public function testAnEmptyCanonicalNameFallsBackToTheDisplayName(): void
    {
        $node = ['id' => 'symbol_0123456789abcdef', 'kind' => 'class', 'canonical_name' => '', 'display_name' => 'Thing'];

        [$out] = ComponentLegend::compress(['component' => $node]);

        assertSame('Thing#89abcdef', $out['component']);
    }

    /** Roles are flattened from either shape, and anything else is dropped. */
    #[Group('mcp')]
    public function testRoleNamesTakeBothShapesAndSkipTheRest(): void
    {
        $node = [
            'id' => 'symbol_a',
            'kind' => 'class',
            'canonical_name' => 'App\\Thing',
            'roles' => [
                ['role' => 'application.service', 'confidence' => 'certain'],
                'quality.test_module',
                ['confidence' => 'probable'],
                ['role' => 123],
            ],
        ];

        [, $legend] = ComponentLegend::compress(['component' => $node]);

        assertSame(['application.service', 'quality.test_module'], $legend['App\\Thing']['roles']);
    }

    /** The index maps every hoisted id to the name that replaced it. */
    #[Group('mcp')]
    public function testTheIndexMapsEachHoistedIdToItsName(): void
    {
        $data = [
            'first' => ['id' => 'symbol_a', 'kind' => 'class', 'canonical_name' => 'App\\One'],
            'second' => ['id' => 'symbol_0123456789abcdef', 'kind' => 'class', 'display_name' => 'Two'],
        ];

        [, , $index] = ComponentLegend::compressWithIndex($data);

        assertSame(['symbol_a' => 'App\\One', 'symbol_0123456789abcdef' => 'Two#89abcdef'], $index);
    }
}
