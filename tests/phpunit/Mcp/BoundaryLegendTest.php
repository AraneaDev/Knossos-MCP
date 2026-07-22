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
}
