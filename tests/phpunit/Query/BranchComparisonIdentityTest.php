<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The branch comparison matched a component across the two graphs by its
 * canonical name alone. Names are not unique: a TypeScript module and a
 * package can both be `core.auth`, so a new dependency on one read as old
 * because the other was already depended on, and two components' in-degrees
 * were merged into one. A component is now identified by language, kind and
 * name.
 */
final class BranchComparisonIdentityTest extends KnossosTestCase
{
    /** Canonical names are not unique: a module and a package can share `core.auth`. */
    #[Group('query')]
    public function testANewDependencyOnASameNamedComponentOfAnotherKindIsNew(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $caller = $this->node($repository, $ids, $ids['scan'], 'php', 'class', 'App\\A');
        $module = $this->node($repository, $ids, $ids['scan'], 'ts', 'module', 'core.auth');
        $this->imports($repository, $ids, $ids['scan'], $caller, $module);
        $this->boundary($repository, $ids, $ids['scan'], 'Web', [$caller]);
        $this->boundary($repository, $ids, $ids['scan'], 'Core', [$module]);
        $next = $this->nextScan($repository, $ids);
        $package = $this->node($repository, $ids, $next, 'ts', 'package', 'core.auth');
        $this->imports($repository, $ids, $next, $caller, $package);
        $this->boundary($repository, $ids, $next, 'Libs', [$package]);
        $repository->completeScan($project, $next);

        $comparison = (new ArchitectureQueryService($pdo))->branchComparison($project, $ids['scan'], []);

        $pairs = array_map(static fn(array $c): string => $c['source']['canonical_name'] . '->' . $c['target']['kind'], $comparison['crossing']['items']);
        self::assertSame(['App\\A->package'], $pairs);
    }

    /** Two components sharing a name keep their own in-degree, and each can grow. */
    #[Group('query')]
    public function testSameNamedComponentsOfDifferentKindsKeepSeparateInDegrees(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $module = $this->node($repository, $ids, $ids['scan'], 'ts', 'module', 'core.auth');
        $package = $this->node($repository, $ids, $ids['scan'], 'ts', 'package', 'core.auth');
        $callers = [];
        foreach (range(1, 15) as $index) {
            $callers[$index] = $this->node($repository, $ids, $ids['scan'], 'php', 'class', sprintf('App\\Caller%02d', $index));
        }
        foreach (range(1, 10) as $index) {
            $this->imports($repository, $ids, $ids['scan'], $callers[$index], $module);
            $this->imports($repository, $ids, $ids['scan'], $callers[$index], $package);
        }
        $next = $this->nextScan($repository, $ids);
        foreach (range(11, 15) as $index) {
            if ($index <= 12) {
                $this->imports($repository, $ids, $next, $callers[$index], $module);
            }
            $this->imports($repository, $ids, $next, $callers[$index], $package);
        }
        $repository->completeScan($project, $next);

        $comparison = (new ArchitectureQueryService($pdo))->branchComparison($project, $ids['scan'], []);

        $grown = array_map(static fn(array $h): array => [$h['component']['kind'], $h['before'], $h['after']], $comparison['hubs']['items']);
        self::assertSame([['package', 10, 15], ['module', 10, 12]], $grown, 'One name, two components, each with its own growth.');
    }

    /** @param array<string, string> $ids */
    private function node(GraphRepository $repository, array $ids, string $scan, string $language, string $kind, string $name): string
    {
        $id = StableId::symbol($ids['project'], $language, $kind, $name);
        $repository->saveNode($id, $ids['project'], $language, $kind, $name, $name, null, $ids['file'], 1, 1, 'ast', 'certain', [], $language . ':file:' . $name, $scan);
        return $id;
    }

    /** @param array<string, string> $ids */
    private function imports(GraphRepository $repository, array $ids, string $scan, string $source, string $target): void
    {
        $repository->saveEdge(StableId::edge($ids['project'], 'imports', $source, $target, 'identity'), $ids['project'], 'imports', $source, $target, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/A.php', $scan);
    }

    /**
     * @param array<string, string> $ids
     * @param list<string> $members
     */
    private function boundary(GraphRepository $repository, array $ids, string $scan, string $name, array $members): void
    {
        $id = StableId::boundary($ids['project'], $name, 'explicit');
        $repository->saveBoundary($id, $ids['project'], $name, ['path_prefix' => strtolower($name)], 'explicit', $scan);
        foreach ($members as $member) {
            $repository->saveBoundaryMembership($id, $ids['project'], $member, $scan);
        }
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
