<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\RenameMatching;
use Knossos\Query\SnapshotMetrics;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A cycle was new unless all its members, by identity key, were one baseline
 * cycle. Moving a recursion to another namespace renames every member, so a
 * refactor that only moved code failed the gate with the old cycle. A member
 * absent from the baseline now stands for the baseline component it was
 * uniquely moved from, when that one is absent from the active graph.
 */
final class MovedCycleTest extends KnossosTestCase
{
    #[Group('query')]
    public function testARecursionThatOnlyMovedIsNotNew(): void
    {
        $base = ['Old\\Walk::walk', 'Old\\Walk::visit'];
        $now = ['New\\Walk::walk', 'New\\Walk::visit'];

        self::assertSame([], $this->newCycles([$base], $base, [$now], $now));
    }

    #[Group('query')]
    public function testAMovedRecursionThatGainedAMemberIsNew(): void
    {
        $base = ['Old\\Walk::walk', 'Old\\Walk::visit'];
        $now = ['New\\Walk::walk', 'New\\Walk::visit', 'New\\Walk::enter'];

        self::assertCount(1, $this->newCycles([$base], $base, [$now], $now));
    }

    #[Group('query')]
    public function testAMoveThatCannotBeToldApartIsNotFollowed(): void
    {
        // Two removed `Walk::walk` methods: which one moved is a guess, so the cycle reads as new.
        $base = ['Old\\Walk::walk', 'Old\\Walk::visit'];
        $all = [...$base, 'Other\\Walk::walk'];
        $now = ['New\\Walk::walk', 'New\\Walk::visit'];

        self::assertCount(1, $this->newCycles([$base], $all, [$now], $now));
        // The same when the ambiguity is on the added side.
        self::assertCount(1, $this->newCycles([$base], $base, [$now], [...$now, 'Third\\Walk::walk']));
    }

    #[Group('query')]
    public function testMovedMembersOfTwoBaselineCyclesMergedAreNew(): void
    {
        $first = ['Old\\A::a', 'Old\\A::b'];
        $second = ['Old\\C::c', 'Old\\C::d'];
        $now = ['New\\A::a', 'New\\A::b', 'New\\C::c', 'New\\C::d'];

        self::assertCount(1, $this->newCycles([$first, $second], [...$first, ...$second], [$now], $now));
    }

    #[Group('query')]
    public function testAMemberPresentInBothGraphsKeepsItsIdentity(): void
    {
        // `Old\Walk::walk` still exists outside any cycle, so `New\Walk::walk` is a new component, not it moved.
        $base = ['Old\\Walk::walk', 'Old\\Walk::visit'];
        $now = ['New\\Walk::walk', 'New\\Walk::visit'];

        self::assertCount(1, $this->newCycles([$base], $base, [$now], [...$now, 'Old\\Walk::walk']));
    }

    #[Group('query')]
    public function testTheMoveSignatureKeepsTheOwnerAndDropsTheNamespace(): void
    {
        self::assertSame("py\0method\0ProjectModuleIndex::module_declarations", RenameMatching::moveSignature(
            "py\0method\0workers.python.bin.knossos_python.module_index.ProjectModuleIndex::module_declarations",
        ));
        self::assertSame("rust\0method\0Walk::walk_item", RenameMatching::moveSignature("rust\0method\0knossos_rust_worker::visit::state::Walk::walk_item"));
        self::assertSame("php\0class\0Walk", RenameMatching::moveSignature("php\0class\0App\\Old\\Walk"));
        self::assertSame("py\0function\0load", RenameMatching::moveSignature("py\0function\0pkg.mod.load"));
        self::assertSame("ts\0method\0Registry::defer", RenameMatching::moveSignature("ts\0method\0e2e/fixtures.ts#Registry::defer"));
    }

    #[Group('query')]
    public function testUniquePairsMatchesTheDiffRuleOrBothSides(): void
    {
        $removed = ['r1' => 'x', 'r2' => 'x', 'r3' => 'y'];
        $added = ['a1' => 'x', 'a2' => 'y', 'a3' => 'y'];

        self::assertSame(['r1' => 'a1', 'r2' => 'a1'], RenameMatching::uniquePairs($removed, $added, false));
        self::assertSame([], RenameMatching::uniquePairs($removed, $added, true));
        self::assertSame(['r3' => 'a2'], RenameMatching::uniquePairs($removed, ['a2' => 'y'], true));
    }

    /**
     * New cycles of the active graph, given PHP methods by canonical name.
     *
     * @param list<list<string>> $baseCycles the baseline's cycles
     * @param list<string> $baseNames every baseline component
     * @param list<list<string>> $nowCycles the active graph's cycles
     * @param list<string> $nowNames every active component
     * @return list<list<string>>
     */
    private function newCycles(array $baseCycles, array $baseNames, array $nowCycles, array $nowNames): array
    {
        $key = static fn(string $name): string => "php\0method\0" . $name;
        $baseCycleOf = [];
        foreach ($baseCycles as $index => $members) {
            foreach ($members as $member) {
                $baseCycleOf[$key($member)] = $index;
            }
        }
        $keysNow = [];
        foreach ($nowNames as $name) {
            $keysNow['id:' . $name] = $key($name);
        }
        $sccs = array_map(static fn(array $members): array => array_map(static fn(string $name): string => 'id:' . $name, $members), $nowCycles);

        return SnapshotMetrics::newCycles($baseCycleOf, $sccs, $keysNow, SnapshotMetrics::cycleKin($baseCycleOf, array_map($key, $baseNames)));
    }
}
