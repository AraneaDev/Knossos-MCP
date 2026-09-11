<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\SessionBrief;
use Knossos\Query\SessionBriefRenderer;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClassConstant;

/**
 * The session brief's wording on its boundaries: each age unit where it
 * starts, one list item against several, the unscanned brief's two lines, and
 * a section that fills the budget to the byte.
 *
 * The renderer's tests checked what each state says. Mutation testing showed
 * the age could round across a unit boundary, the fresh verdict could lose its
 * full stop, the unscanned brief could grow an identity line, and the budget
 * could let one byte too many through, all with those tests green.
 */
final class SessionBriefRendererBoundaryTest extends KnossosTestCase
{
    private const POINTER = 'Ask before grepping for structure: the `knossos` skill.';

    /** @return iterable<string, array{int|null, string}> */
    public static function ages(): iterable
    {
        yield 'no age' => [null, 'unknown'];
        yield 'under a minute' => [59, '1m'];
        yield 'two minutes' => [120, '2m'];
        yield 'just under an hour' => [3_599, '59m'];
        yield 'an hour' => [3_600, '1h'];
        yield 'two hours' => [7_200, '2h'];
        yield 'just under a day' => [86_399, '23h'];
        yield 'a day' => [86_400, '1d'];
        yield 'two days' => [172_800, '2d'];
    }

    #[DataProvider('ages')]
    #[Group('query')]
    public function testTheAgeIsOneCoarseToken(?int $seconds, string $shown): void
    {
        $first = strtok((new SessionBriefRenderer())->render(self::brief('fresh', ageSeconds: $seconds)), "\n");

        assertSame(sprintf('FRESH (scanned %s ago).', $shown), $first);
    }

    /** An unscanned path gets the verdict and the pointer, and nothing between them. */
    #[Group('query')]
    public function testAnUnscannedBriefIsTheVerdictAndThePointer(): void
    {
        $text = (new SessionBriefRenderer())->render(self::brief('unscanned', rules: ['core -x-> tests']));

        assertSame("NOT SCANNED. Run scan_project path=/work/shop to map this repository.\n" . self::POINTER, $text);
    }

    /** One item sits on its label's line; several go one per indented line. */
    #[Group('query')]
    public function testASectionPutsOneItemInlineAndSeveralBelow(): void
    {
        $one = (new SessionBriefRenderer())->render(self::brief('stale', rules: ['core -x-> tests']));
        $two = (new SessionBriefRenderer())->render(self::brief('stale', rules: ['core -x-> tests', 'app --> only core']));

        assertSame(true, str_contains($one, "\nRules: core -x-> tests\n"));
        assertSame(true, str_contains($two, "\nRules:\n  core -x-> tests\n  app --> only core\n"));
    }

    /**
     * A section that brings the brief to exactly its budget is kept; one byte
     * more and it is dropped whole.
     */
    #[Group('query')]
    public function testASectionThatFillsTheBudgetExactlyIsKept(): void
    {
        $budget = (new ReflectionClassConstant(SessionBriefRenderer::class, 'BUDGETS'))->getValue()['stale'];
        $bare = (new SessionBriefRenderer())->render(self::brief('stale'));
        // Room left for "\nRules: <rule>" before the pointer's line.
        $room = $budget - strlen($bare) - strlen("\nRules: ");

        $exact = (new SessionBriefRenderer())->render(self::brief('stale', rules: [str_repeat('r', $room)]));
        $over = (new SessionBriefRenderer())->render(self::brief('stale', rules: [str_repeat('r', $room + 1)]));

        assertSame($budget, strlen($exact));
        assertSame(true, str_contains($exact, 'Rules: '));
        assertSame($bare, $over, 'A section one byte over the budget is dropped whole.');
    }

    /** @param list<string> $rules */
    private static function brief(string $state, ?int $ageSeconds = 3_600, array $rules = []): SessionBrief
    {
        return new SessionBrief(
            state: $state,
            projectId: $state === 'unscanned' ? null : 'project_abc',
            projectName: $state === 'unscanned' ? null : 'Shop',
            path: '/work/shop',
            ageSeconds: $state === 'unscanned' ? null : $ageSeconds,
            changedFiles: 2,
            trackedFiles: 10,
            rules: $rules,
        );
    }
}
