<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `suggest_location` refuses out-of-range arguments at exactly the documented edges.
 *
 * Every guard was exercised only from well inside its range, so each upper
 * comparison could become `>=` (rejecting its own last legal value) without a
 * test noticing, and the description's `trim()` could be dropped, letting a
 * whitespace-only feature through as if it described something.
 */
final class SuggestionArgumentsTest extends KnossosTestCase
{
    #[Group('query')]
    public function testTheFeatureDescriptionIsBoundedAtTwoThousandBytes(): void
    {
        [$queries, $project] = $this->queries();

        $atLimit = 'checkout' . str_repeat('x', 1992);
        assertSame(2000, strlen($atLimit));
        $queries->suggestLocation($project, $atLimit);
        assertThrows(static fn() => $queries->suggestLocation($project, $atLimit . 'x'), InvalidArgumentException::class);
    }

    /**
     * An empty description fails the length check; a whitespace-only one fails
     * the token check, because it has bytes but says nothing.
     *
     * Each gets the message that is true of it. The length guard used to trim
     * first, so "   " was told it needed "between 1 and 2000 bytes", which it
     * already had.
     */
    #[Group('query')]
    public function testEachEmptyDescriptionIsRefusedWithTheReasonThatAppliesToIt(): void
    {
        [$queries, $project] = $this->queries();

        $empty = captureThrows(static fn() => $queries->suggestLocation($project, ''), InvalidArgumentException::class);
        assertContains('between 1 and 2000 bytes', $empty->getMessage());

        $blank = captureThrows(static fn() => $queries->suggestLocation($project, "   \t\n"), InvalidArgumentException::class);
        assertContains('meaningful letter or number token', $blank->getMessage());
    }

    #[Group('query')]
    public function testLimitAcceptsOneThroughTwenty(): void
    {
        [$queries, $project] = $this->queries();

        $queries->suggestLocation($project, 'checkout refunds', 1);
        $queries->suggestLocation($project, 'checkout refunds', 20);
        assertThrows(static fn() => $queries->suggestLocation($project, 'checkout refunds', 0), InvalidArgumentException::class);
        assertThrows(static fn() => $queries->suggestLocation($project, 'checkout refunds', 21), InvalidArgumentException::class);
    }

    #[Group('query')]
    public function testMaxMembersAcceptsOneThroughFiftyThousand(): void
    {
        [$queries, $project] = $this->queries();

        $queries->suggestLocation($project, 'checkout refunds', 5, 1);
        $queries->suggestLocation($project, 'checkout refunds', 5, 50_000);
        assertThrows(static fn() => $queries->suggestLocation($project, 'checkout refunds', 5, 0), InvalidArgumentException::class);
        assertThrows(static fn() => $queries->suggestLocation($project, 'checkout refunds', 5, 50_001), InvalidArgumentException::class);
    }

    #[Group('query')]
    public function testMaxEdgesAcceptsOneThroughAHundredThousand(): void
    {
        [$queries, $project] = $this->queries();

        $queries->suggestLocation($project, 'checkout refunds', 5, 20_000, 1);
        $queries->suggestLocation($project, 'checkout refunds', 5, 20_000, 100_000);
        assertThrows(static fn() => $queries->suggestLocation($project, 'checkout refunds', 5, 20_000, 0), InvalidArgumentException::class);
        assertThrows(static fn() => $queries->suggestLocation($project, 'checkout refunds', 5, 20_000, 100_001), InvalidArgumentException::class);
    }

    #[Group('query')]
    public function testTimeoutAcceptsOneThroughFiveThousandMilliseconds(): void
    {
        [$queries, $project] = $this->queries();

        $queries->suggestLocation($project, 'checkout refunds', 5, 20_000, 100_000, 1);
        $queries->suggestLocation($project, 'checkout refunds', 5, 20_000, 100_000, 5000);
        assertThrows(static fn() => $queries->suggestLocation($project, 'checkout refunds', 5, 20_000, 100_000, 0), InvalidArgumentException::class);
        assertThrows(static fn() => $queries->suggestLocation($project, 'checkout refunds', 5, 20_000, 100_000, 5001), InvalidArgumentException::class);
    }

    /** @return array{0: ArchitectureQueryService, 1: string} */
    private function queries(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        return [new ArchitectureQueryService($pdo), $ids['project']];
    }
}
