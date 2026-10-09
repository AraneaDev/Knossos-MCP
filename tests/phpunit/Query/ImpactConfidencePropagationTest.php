<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Impact analysis raised a dependant's recorded confidence when a second,
 * equally short path was stronger, but the dependant had already been queued
 * with the weaker first path's confidence. Its own dependants then inherited
 * that weaker value, so the answer contradicted itself: X shown as certain,
 * while Y, reached only through X, shown as possible.
 */
final class ImpactConfidencePropagationTest extends KnossosTestCase
{
    #[Group('query')]
    public function testAnUpgradedDependantPassesItsUpgradedConfidenceToItsOwnDependants(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $node = static function (string $name) use ($repository, $ids, $project): string {
            $id = StableId::symbol($project, 'php', 'class', 'App\\' . $name);
            $repository->saveNode($id, $project, 'php', 'class', 'App\\' . $name, $name, null, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/T.php', $ids['scan']);
            return $id;
        };
        $edge = static function (string $kind, string $source, string $target, string $confidence) use ($repository, $ids, $project): void {
            $repository->saveEdge(StableId::edge($project, $kind, $source, $target, 'test'), $project, $kind, $source, $target, $ids['file'], 1, 1, 'ast', $confidence, [], 'php:file:src/T.php', $ids['scan']);
        };
        $t = $node('T');
        $p1 = $node('P1');
        $p2 = $node('P2');
        $x = $node('X');
        $y = $node('Y');
        // `calls` sorts before `references`, so P1 is dequeued before P2 and
        // discovers X first, over the possible edge.
        $edge('calls', $p1, $t, 'certain');
        $edge('references', $p2, $t, 'certain');
        $edge('calls', $x, $p1, 'possible');
        $edge('calls', $x, $p2, 'certain');
        $edge('calls', $y, $x, 'certain');
        $repository->completeScan($project, $ids['scan']);

        $result = (new ArchitectureQueryService($pdo))->impactAnalysis($project, 'App\\T');
        $byName = [];
        foreach ($result->data['dependants'] as $record) {
            $byName[$record['node']['canonical_name']] = $record;
        }
        self::assertSame('certain', $byName['App\\X']['path_confidence'], 'X is reached for certain through P2.');
        self::assertSame(3, $byName['App\\Y']['distance']);
        self::assertSame('certain', $byName['App\\Y']['path_confidence'], 'Y inherits the upgraded certainty of X, not the weaker first path.');
        self::assertSame(0, $result->data['counts']['by_confidence']['possible']);
    }
}
