<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ReportableComponent;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Impact analysis listed entry points from a kind and role list of its own,
 * so the dependants it called ways into the system differed from the ones the
 * briefs name for the same graph: a queued job counted, a declared endpoint did
 * not, and a command stub in test code did. It now asks the shared criteria.
 */
final class ImpactEntryPointsTest extends KnossosTestCase
{
    #[Group('query')]
    public function testImpactEntryPointsUseTheSharedCriteria(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $component = static function (string $name, string $kind, ?string $role) use ($repository, $ids, $project): string {
            $id = StableId::symbol($project, 'php', $kind, 'App\\' . $name);
            $repository->saveNode($id, $project, 'php', $kind, 'App\\' . $name, $name, null, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Impact.php', $ids['scan']);
            if ($role !== null) {
                $repository->saveClassification(StableId::classification($project, $id, $role, 'test.roles'), $project, $id, $role, 'user_rule', 'certain', 'test.roles', $ids['file'], 1, 1, [], $ids['scan']);
            }
            return $id;
        };
        $target = $component('T', 'class', null);
        foreach ([['E', 'endpoint', null], ['J', 'class', 'laravel.job'], ['Q', 'command', ReportableComponent::TEST_ROLE]] as [$name, $kind, $role]) {
            $caller = $component($name, $kind, $role);
            $repository->saveEdge(StableId::edge($project, 'calls', $caller, $target, 'test'), $project, 'calls', $caller, $target, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Impact.php', $ids['scan']);
        }
        $repository->completeScan($project, $ids['scan']);

        $impact = ArchitectureQueryService::forDatabase($pdo)->impactAnalysis($project, 'App\\T');

        self::assertCount(3, $impact->data['dependants'], 'All three callers are dependants; only the entry-point judgement differs.');
        $names = array_map(static fn(array $r): string => $r['node']['canonical_name'], $impact->data['entry_points']);
        self::assertSame(['App\\E'], $names);
    }
}
