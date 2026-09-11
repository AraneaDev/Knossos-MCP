<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The context budget: what the caller may ask for, how the total is divided,
 * and what the answer says it did.
 *
 * ArchitectureContextService scored 53% under mutation testing. Its tests ask
 * for a context and read sections out of it, so the advertised input ranges, the
 * proportions the budget is split into, the subject of the summary sentence, and
 * the caveats attached to a source-bearing answer could all change with them
 * green.
 *
 * The split is asserted at 4003 characters rather than a round number on
 * purpose. Every proportion of a round budget lands on a whole number, where
 * rounding down, to nearest, and up cannot be told apart; at 4003 each one lands
 * on a fraction and the three readings disagree.
 */
final class ArchitectureContextBoundsTest extends KnossosTestCase
{
    /** Every advertised input range is accepted at both ends and refused outside. */
    #[Group('query')]
    public function testTheAdvertisedInputRangesAreEnforced(): void
    {
        $queries = $this->queries();

        assertSame(4_000, $queries->architectureContext($this->project, 'refunds', maxChars: 4_000)->data['budget']['max_chars']);
        assertSame(100_000, $queries->architectureContext($this->project, 'refunds', maxChars: 100_000)->data['budget']['max_chars']);
        foreach ([3_999, 100_001] as $outside) {
            assertThrows(fn() => $queries->architectureContext($this->project, 'refunds', maxChars: $outside), InvalidArgumentException::class);
        }

        // The timeout range, at both ends and just outside each.
        assertSame(true, is_array($queries->architectureContext($this->project, 'refunds', timeoutMs: 1)->data));
        assertSame(true, is_array($queries->architectureContext($this->project, 'refunds', timeoutMs: 5_000)->data));
        foreach ([0, 5_001] as $outside) {
            assertThrows(fn() => $queries->architectureContext($this->project, 'refunds', timeoutMs: $outside), InvalidArgumentException::class);
        }

        // A task description of exactly the ceiling is accepted.
        assertSame(true, is_array($queries->architectureContext($this->project, str_repeat('a', 2_000))->data));
        assertThrows(fn() => $queries->architectureContext($this->project, str_repeat('a', 2_001)), InvalidArgumentException::class);

        // Neither a task nor files is nothing to build a context from.
        assertThrows(fn() => $queries->architectureContext($this->project), InvalidArgumentException::class);
        assertThrows(fn() => $queries->architectureContext($this->project, '   '), InvalidArgumentException::class);
    }

    /** The budget is split into fifths and a remainder, rounded down. */
    #[Group('query')]
    public function testTheBudgetIsSplitIntoTheAdvertisedProportions(): void
    {
        $result = $this->queries()->architectureContext($this->project, 'refunds', maxChars: 4_003);

        assertSame(
            ['summary' => 800, 'locations' => 800, 'change_impact' => 1_200, 'dossiers' => 1_201],
            $result->data['budget']['allocations'],
            'A fifth each to summary and locations, three tenths to impact, and whatever is left to dossiers.',
        );
        assertSame(4_003, $result->data['budget']['max_chars']);
        // Two characters short of the budget, and deliberately stated: the three
        // proportions are each rounded down on their own while the dossier share
        // is the remainder after a single seven-tenths, so the roundings do not
        // meet in the middle. Nothing is lost by it, because the total is
        // enforced against the encoded context rather than against this sum.
        assertSame(4_001, array_sum($result->data['budget']['allocations']));
    }

    /** A context built from a task names the task; one built from files names the files. */
    #[Group('query')]
    public function testTheAnswerNamesWhateverItWasAskedAbout(): void
    {
        $queries = $this->queries();

        $byTask = $queries->architectureContext($this->project, 'checkout refunds');
        assertSame('checkout refunds', $byTask->data['context']['task_description']);
        assertSame('Built bounded architecture context for checkout refunds.', $byTask->summary);

        $byFiles = $queries->architectureContext($this->project, '', ['src/Checkout.php']);
        assertSame(null, $byFiles->data['context']['task_description'], 'No task was given, so none is reported.');
        assertSame('Built bounded architecture context for src/Checkout.php.', $byFiles->summary);
        assertSame(['status' => 'not_requested'], $byFiles->data['context']['sections']['locations']);
    }

    /** Reading source from the working tree earns its own caveat. */
    #[Group('query')]
    public function testSourceSnippetsCarryTheirOwnCaveat(): void
    {
        $queries = $this->queries();
        $standing = 'Context sections are bounded static evidence and may omit dynamic runtime behavior.';

        assertSame([$standing], $queries->architectureContext($this->project, 'refunds')->warnings);
        assertSame(
            [$standing, 'Source snippets are read from the working tree now and may differ from the scanned graph.'],
            $queries->architectureContext($this->project, 'refunds', includeSource: true)->warnings,
        );
    }

    private string $project = '';

    private function queries(): ArchitectureQueryService
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $this->project = $ids['project'];

        return new ArchitectureQueryService($pdo);
    }
}
