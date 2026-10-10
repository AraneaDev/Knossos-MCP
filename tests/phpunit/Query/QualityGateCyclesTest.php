<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `new_cycles` was the active graph's cycle count minus the baseline's, so a
 * change that merged two cycles, or broke one while creating another, read 0
 * and passed. It is now the cycles whose members were not all one cycle in the
 * baseline, the rule the branch comparison uses. A loop closed only by
 * type-only imports is not a cycle, as in dependency_cycles.
 */
final class QualityGateCyclesTest extends KnossosTestCase
{
    #[Group('query')]
    public function testMergingTwoCyclesIntoOneIsANewCycle(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $n = $this->classes($repository, $ids, ['A', 'B', 'C', 'D']);
        foreach ([['A', 'B'], ['B', 'A'], ['C', 'D'], ['D', 'C']] as [$from, $to]) {
            $this->edge($repository, $ids, $ids['scan'], 'calls', $n[$from], $n[$to]);
        }
        $next = $this->nextScan($repository, $ids);
        foreach ([['B', 'C'], ['D', 'A']] as [$from, $to]) {
            $this->edge($repository, $ids, $next, 'calls', $n[$from], $n[$to]);
        }
        $repository->completeScan($ids['project'], $next);
        $queries = ArchitectureQueryService::forDatabase($pdo);

        $gate = $queries->qualityGate($ids['project'], $ids['scan'], ['new_cycles' => 0]);

        self::assertSame(1, $gate->data['metrics']['new_cycles'], 'Counts 2 then 1; the difference read 0.');
        self::assertFalse($gate->data['passed']);
        $branch = $queries->branchComparison($ids['project'], $ids['scan'], []);
        self::assertSame(1, $branch['cycles']['count'], 'The branch comparison applies the same membership rule.');
        self::assertSame(4, $branch['cycles']['items'][0]['size']);
    }

    #[Group('query')]
    public function testBreakingOneCycleWhileCreatingAnotherStillFails(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $n = $this->classes($repository, $ids, ['A', 'B', 'E', 'F']);
        $this->edge($repository, $ids, $ids['scan'], 'calls', $n['A'], $n['B']);
        $back = $this->edge($repository, $ids, $ids['scan'], 'calls', $n['B'], $n['A']);
        $next = $this->nextScan($repository, $ids);
        $repository->pruneGraph($ids['project'], ['edges' => [$back => true]], ['edges' => []]);
        $this->edge($repository, $ids, $next, 'calls', $n['E'], $n['F']);
        $this->edge($repository, $ids, $next, 'calls', $n['F'], $n['E']);
        $repository->completeScan($ids['project'], $next);

        $gate = ArchitectureQueryService::forDatabase($pdo)->qualityGate($ids['project'], $ids['scan'], ['new_cycles' => 0]);

        self::assertSame(1, $gate->data['metrics']['new_cycles'], 'A swap kept the count and read 0.');
        self::assertFalse($gate->data['passed']);
    }

    #[Group('query')]
    public function testATypeOnlyImportLoopIsNotACycle(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $next = $this->nextScan($repository, $ids);
        $modules = [];
        foreach (['E', 'F'] as $name) {
            $modules[$name] = StableId::symbol($project, 'ts', 'module', 'web#' . $name);
            $repository->saveNode($modules[$name], $project, 'ts', 'module', 'web#' . $name, $name, null, $ids['file'], 1, 1, 'ast', 'certain', [], 'ts:file:' . $name . '.ts', $next);
        }
        $this->edge($repository, $ids, $next, 'imports', $modules['E'], $modules['F'], ['type_only' => true]);
        $this->edge($repository, $ids, $next, 'imports', $modules['F'], $modules['E'], ['type_only' => true]);
        $repository->completeScan($project, $next);

        $gate = ArchitectureQueryService::forDatabase($pdo)->qualityGate($project, $ids['scan'], ['new_cycles' => 0]);

        self::assertSame(0, $gate->data['metrics']['new_cycles']);
        self::assertTrue($gate->data['passed']);
    }

    /**
     * @param array<string, string> $ids
     * @param list<string> $names
     * @return array<string, string>
     */
    private function classes(GraphRepository $repository, array $ids, array $names): array
    {
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = StableId::symbol($ids['project'], 'php', 'class', 'App\\' . $name);
            $repository->saveNode($saved[$name], $ids['project'], 'php', 'class', 'App\\' . $name, $name, null, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/' . $name . '.php', $ids['scan']);
        }
        return $saved;
    }

    /**
     * @param array<string, string> $ids
     * @param array<string, mixed> $attributes
     */
    private function edge(GraphRepository $repository, array $ids, string $scan, string $kind, string $source, string $target, array $attributes = []): string
    {
        $id = StableId::edge($ids['project'], $kind, $source, $target, 'cycle');
        $repository->saveEdge($id, $ids['project'], $kind, $source, $target, $ids['file'], 1, 1, 'ast', 'certain', $attributes, 'php:file:src/Cycle.php', $scan);
        return $id;
    }

    /**
     * Complete and archive the fixture's scan as the baseline, and open the next one.
     *
     * @param array<string, string> $ids
     */
    private function nextScan(GraphRepository $repository, array $ids): string
    {
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $next = StableId::scan($ids['project'], 'scan-2');
        $repository->createScan($next, $ids['project'], 'incremental', hash('sha256', 'scanner-set'));
        return $next;
    }
}
