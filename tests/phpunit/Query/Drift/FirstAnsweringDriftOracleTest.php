<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\Drift;

use Knossos\Query\Drift\DriftCounts;
use Knossos\Query\Drift\DriftOracle;
use Knossos\Query\Drift\FirstAnsweringDriftOracle;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Null means "I cannot answer", not "nothing changed". Collapsing the two would
 * report a graph as fresh because git happened to be missing, which is the one
 * answer a freshness probe must never give.
 */
final class FirstAnsweringDriftOracleTest extends KnossosTestCase
{
    /** The first non-null answer in oracle order wins, whatever oracle produced it. */
    #[Group('query')]
    public function testItTakesTheFirstNonNullAnswer(): void
    {
        $oracle = new FirstAnsweringDriftOracle(self::answering(null), self::answering(new DriftCounts(1, 2, 3)));

        self::assertSame(6, $oracle->drift('p', 's', '/tmp', null)?->total());
    }

    /**
     * The counting double is declared inline rather than behind a
     * DriftOracle-typed helper (per controller ruling R2): a helper whose
     * return type is the interface hides the public $calls counter from
     * PHPStan, which runs in this project's quality gate.
     */
    #[Group('query')]
    public function testItDoesNotConsultLaterOraclesOnceOneAnswers(): void
    {
        $second = new class implements DriftOracle {
            public int $calls = 0;

            public function drift(string $projectId, string $activeScanId, string $root, ?string $finishedAt): ?DriftCounts
            {
                ++$this->calls;
                return null;
            }
        };
        $oracle = new FirstAnsweringDriftOracle(self::answering(new DriftCounts(0, 0, 0)), $second);

        self::assertSame(0, $oracle->drift('p', 's', '/tmp', null)?->total(), 'Zero is an answer, and must stop the chain.');
        self::assertSame(0, $second->calls, 'A cheap exact answer must not be followed by an expensive approximate one.');
    }

    /** With no oracle able to answer, the chain must say so rather than guess a fresh or stale verdict. */
    #[Group('query')]
    public function testItReturnsNullWhenNoOracleCanAnswer(): void
    {
        self::assertNull((new FirstAnsweringDriftOracle(self::answering(null), self::answering(null)))->drift('p', 's', '/tmp', null));
    }

    /** A fixed answer, standing in for an oracle that could (or could not) decide. */
    private static function answering(?DriftCounts $counts): DriftOracle
    {
        return new class ($counts) implements DriftOracle {
            public function __construct(private readonly ?DriftCounts $counts) {}

            public function drift(string $projectId, string $activeScanId, string $root, ?string $finishedAt): ?DriftCounts
            {
                return $this->counts;
            }
        };
    }
}
