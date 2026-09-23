<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\CandidateGraphFacts;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The project-wide facts dead-code classification reads, answered from the
 * whole graph rather than from whichever nodes a bounded walk happened to read.
 */
#[Group('query')]
final class CandidateGraphFactsTest extends KnossosTestCase
{
    public function testAnswersFromTheWholeProject(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $node = function (string $kind, string $name, array $attributes = []) use ($repository, $ids, $project): string {
            $id = StableId::symbol($project, 'ts', $kind, $name);
            $repository->saveNode($id, $project, 'ts', $kind, $name, basename(str_replace('::', '/', $name)), null, $ids['file'], 1, 2, 'ast', 'certain', $attributes, 'ts:file:x', $ids['scan']);

            return $id;
        };
        $edge = function (string $kind, string $from, string $to, string $key) use ($repository, $ids, $project): void {
            $repository->saveEdge(StableId::edge($project, $kind, $from, $to, $key), $project, $kind, $from, $to, $ids['file'], 1, 1, 'ast', 'certain', [], 'ts:file:x', $ids['scan']);
        };
        $base = $node('class', 'src/a.ts#Base');
        $child = $node('class', 'src/a.ts#Child');
        $user = $node('function', 'src/b.ts#use');
        $node('module', 'pkg.ünï');
        $node('module', 'pkg.ünï.sub');
        $node('module', 'src/loop.js', ['unresolved_member_calls' => ['label', 'save']]);
        $node('module', 'src/other.js', ['unresolved_member_calls' => ['save', 'open']]);
        $edge('extends', $child, $base, '1');
        $edge('calls', $user, $base, '2');
        $edge('calls', $user, $child, '3');
        $repository->completeScan($project, $ids['scan']);

        $facts = new CandidateGraphFacts($pdo, $project, ['calls', 'extends'], 1);

        self::assertSame($base, $facts->idOf('src/a.ts#Base'));
        self::assertNull($facts->idOf('src/a.ts#Missing'));
        self::assertTrue($facts->hasCanonicalPrefix('pkg.ünï.'));
        self::assertFalse($facts->hasCanonicalPrefix('pkg.ünïx.'));
        self::assertFalse($facts->hasCanonicalPrefix('pkg.ünï.sub.'));
        self::assertSame(2, $facts->inDegree($base));
        self::assertSame(1, $facts->inheritanceInDegree($base));
        self::assertSame(2, $facts->outDegree($user));
        self::assertSame(0, $facts->inDegree($user));
        self::assertSame(['in_degree' => 0, 'inheritance_in_degree' => 0, 'out_degree' => 0], $facts->degrees(['no-such-node'])['no-such-node']);
        self::assertSame(['label' => true, 'open' => true, 'save' => true], $facts->untypedMemberNames());
    }

    public function testAnEdgeBelowTheConfidenceFloorOrOfAnotherKindDoesNotCount(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $a = StableId::symbol($project, 'ts', 'function', 'a');
        $b = StableId::symbol($project, 'ts', 'function', 'b');
        foreach ([$a, $b] as $index => $id) {
            $repository->saveNode($id, $project, 'ts', 'function', $index === 0 ? 'a' : 'b', $index === 0 ? 'a' : 'b', null, $ids['file'], 1, 2, 'ast', 'certain', [], 'ts:file:x', $ids['scan']);
        }
        $repository->saveEdge(StableId::edge($project, 'calls', $a, $b, '1'), $project, 'calls', $a, $b, $ids['file'], 1, 1, 'ast', 'possible', [], 'ts:file:x', $ids['scan']);
        $repository->saveEdge(StableId::edge($project, 'contains', $a, $b, '2'), $project, 'contains', $a, $b, $ids['file'], 1, 1, 'ast', 'certain', [], 'ts:file:x', $ids['scan']);
        $repository->completeScan($project, $ids['scan']);

        self::assertSame(0, (new CandidateGraphFacts($pdo, $project, ['calls'], 2))->inDegree($b));
        self::assertSame(1, (new CandidateGraphFacts($pdo, $project, ['calls'], 1))->inDegree($b));
    }

    public function testASharedCanonicalNameResolvesToTheLowestId(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $one = StableId::symbol($project, 'php', 'class', 'Shared');
        $two = StableId::symbol($project, 'ts', 'module', 'Shared');
        $repository->saveNode($one, $project, 'php', 'class', 'Shared', 'Shared', null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:x', $ids['scan']);
        $repository->saveNode($two, $project, 'ts', 'module', 'Shared', 'Shared', null, $ids['file'], 1, 2, 'ast', 'certain', [], 'ts:file:x', $ids['scan']);
        $repository->completeScan($project, $ids['scan']);

        self::assertSame(min($one, $two), (new CandidateGraphFacts($pdo, $project, ['calls'], 1))->idOf('Shared'));
    }
}
