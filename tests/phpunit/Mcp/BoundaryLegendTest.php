<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\BoundaryLegend;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Query\ResultEnvelope;
use Knossos\Query\StalenessProbe;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class BoundaryLegendTest extends KnossosTestCase
{
    private const BOUNDARY = ['id' => 'boundary_a', 'name' => 'module:src', 'source' => 'inferred'];

    #[Group('mcp')]
    public function testCompressHoistsRepeatedBoundaryObjects(): void
    {
        $data = ['hubs' => [
            ['component' => ['id' => 'n1', 'boundaries' => [self::BOUNDARY]]],
            ['component' => ['id' => 'n2', 'boundaries' => [self::BOUNDARY]]],
        ]];
        [$compressed, $legend] = BoundaryLegend::compress($data);
        assertSame(['boundary_a'], $compressed['hubs'][0]['component']['boundaries']);
        assertSame(['boundary_a' => ['name' => 'module:src', 'source' => 'inferred']], $legend);
    }

    #[Group('mcp')]
    public function testCompressLeavesNonBoundaryShapesAlone(): void
    {
        $data = ['boundaries' => [['id' => 'b1', 'name' => 'x', 'source' => 'inferred', 'members' => 3]]];
        [$compressed, $legend] = BoundaryLegend::compress($data);
        assertSame($data, $compressed);
        assertSame([], $legend);
    }

    #[Group('mcp')]
    public function testCompressPassesThroughListBoundariesShapeButCompressesComponentBoundaries(): void
    {
        $listBoundariesData = ['boundaries' => [[
            'id' => 'boundary_a',
            'name' => 'module:src',
            'source' => 'inferred',
            'matcher' => 'src/**',
            'member_count' => 3,
            'sample_members' => ['src/Foo.php'],
        ]]];
        [$compressed, $legend] = BoundaryLegend::compress($listBoundariesData);
        assertSame($listBoundariesData, $compressed);
        assertSame([], $legend);

        $componentBoundariesData = ['component' => ['boundaries' => [self::BOUNDARY]]];
        [$compressedComponent, $componentLegend] = BoundaryLegend::compress($componentBoundariesData);
        assertSame(['boundary_a'], $compressedComponent['component']['boundaries']);
        assertSame(['boundary_a' => ['name' => 'module:src', 'source' => 'inferred']], $componentLegend);
    }

    #[Group('mcp')]
    public function testEnricherAppliesLegendOnlyInCompactMode(): void
    {
        $pdo = $this->freshTestDatabase();
        $enricher = new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner());
        $envelope = new ResultEnvelope('project_x', 'scan_x', 'ok', ['hubs' => [
            ['component' => ['id' => 'n1', 'boundaries' => [self::BOUNDARY]]],
        ]]);
        $compact = $enricher->enrich($envelope, 'architecture_health', 'compact');
        assertSame(['boundary_a'], $compact->data['hubs'][0]['component']['boundaries']);
        assertSame('module:src', $compact->data['boundary_legend']['boundary_a']['name']);
        $full = $enricher->enrich($envelope, 'architecture_health', 'full');
        assertSame([self::BOUNDARY], $full->data['hubs'][0]['component']['boundaries']);
    }

    /** After hoisting one boundary list, the walk carries on to the keys beside it. */
    #[Group('mcp')]
    public function testTheWalkCarriesOnPastABoundaryListToTheKeysBesideIt(): void
    {
        $second = ['id' => 'boundary_b', 'name' => 'module:lib', 'source' => 'explicit'];
        $data = [
            'boundaries' => [self::BOUNDARY],
            'component' => ['boundaries' => [$second]],
        ];

        [$compressed, $legend] = BoundaryLegend::compress($data);

        assertSame(['boundary_a'], $compressed['boundaries']);
        assertSame(['boundary_b'], $compressed['component']['boundaries'], 'A boundary list after the first must be hoisted too.');
        assertSame(['boundary_a', 'boundary_b'], array_keys($legend));
    }

    /** Boundaries given as a map are not a boundary list, and are left as they are. */
    #[Group('mcp')]
    public function testAMapOfBoundariesIsNotABoundaryList(): void
    {
        $data = ['boundaries' => ['first' => self::BOUNDARY]];

        [$compressed, $legend] = BoundaryLegend::compress($data);

        assertSame($data, $compressed, 'A keyed map is not the list shape this rewrites.');
        assertSame([], $legend);
    }

    /**
     * An entry of the right size but the wrong types is not a boundary.
     *
     * Each field is checked on its own, so a triple with one non-string field
     * has to be refused even though its other two are strings.
     */
    #[Group('mcp')]
    public function testAnEntryWithAWrongTypedFieldIsNotABoundary(): void
    {
        foreach ([
            'id' => ['id' => 5, 'name' => 'module:src', 'source' => 'inferred'],
            'name' => ['id' => 'boundary_a', 'name' => 5, 'source' => 'inferred'],
            'source' => ['id' => 'boundary_a', 'name' => 'module:src', 'source' => 5],
        ] as $field => $entry) {
            $data = ['boundaries' => [$entry]];

            [$compressed, $legend] = BoundaryLegend::compress($data);

            assertSame($data, $compressed, $field);
            assertSame([], $legend, $field);
        }
    }
}
