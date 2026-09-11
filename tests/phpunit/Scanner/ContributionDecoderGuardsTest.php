<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Worker\ContributionDecoder;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The boundary that keeps a malformed worker reply out of the database, one
 * malformed shape at a time.
 *
 * ContributionDecoder scored 82% under mutation testing. What survived was its
 * guards: each tests two things at once, and a reply that fails both halves
 * cannot tell the conjunction from the disjunction. What separates them is a
 * reply that fails exactly one half, and the message it is refused with, because
 * a value that slips past one guard is caught by the next one and reported as
 * the wrong problem.
 */
final class ContributionDecoderGuardsTest extends KnossosTestCase
{
    /** A line number that is not a number is refused as a line problem. */
    #[Group('scanner')]
    public function testAnEvidenceLineThatIsNotAnIntegerIsRefusedAsALineProblem(): void
    {
        // start_line is valid and end_line is not, so only the disjunction refuses it.
        $node = self::node();
        $node['evidence']['end_line'] = 'two';

        $error = self::refuse(self::reply([$node]));

        assertSame(true, str_contains($error->getMessage(), 'Evidence lines must be integers.'), $error->getMessage());
    }

    /** A list where an object belongs is refused as a shape problem, not a missing field. */
    #[Group('scanner')]
    public function testAListWhereAnObjectBelongsIsRefusedAsAShapeProblem(): void
    {
        $error = self::refuse(self::reply([[1, 2, 3]]));

        assertSame(true, str_contains($error->getMessage(), 'node must be an object.'), $error->getMessage());
    }

    /** A map where a list belongs is refused as a shape problem. */
    #[Group('scanner')]
    public function testAMapWhereAListBelongsIsRefusedAsAShapeProblem(): void
    {
        $reply = self::reply([]);
        $reply['nodes'] = ['first' => self::node()];

        $error = self::refuse($reply);

        assertSame(true, str_contains($error->getMessage(), 'nodes must be a list.'), $error->getMessage());
    }

    /** A field of the wrong type is refused as that field, not as something further on. */
    #[Group('scanner')]
    public function testAFieldOfTheWrongTypeIsRefusedAsThatField(): void
    {
        $wrongType = self::node();
        $wrongType['local_id'] = 5;
        assertSame(
            true,
            str_contains(self::refuse(self::reply([$wrongType]))->getMessage(), 'local_id must be a non-empty string.'),
        );

        $empty = self::node();
        $empty['local_id'] = '';
        assertSame(
            true,
            str_contains(self::refuse(self::reply([$empty]))->getMessage(), 'local_id must be a non-empty string.'),
        );

        $missing = self::node();
        unset($missing['kind']);
        assertSame(
            true,
            str_contains(self::refuse(self::reply([$missing]))->getMessage(), 'kind must be a non-empty string.'),
        );
    }

    /** Attributes given as a list rather than a map are refused. */
    #[Group('scanner')]
    public function testAttributesGivenAsAListAreRefused(): void
    {
        $node = self::node();
        $node['attributes'] = ['a', 'b'];

        $error = self::refuse(self::reply([$node]));

        assertSame(true, str_contains($error->getMessage(), 'Fact attributes must be an object.'), $error->getMessage());
    }

    /**
     * A value outside an enum arrives as a contribution error, not as whatever
     * the enum threw.
     *
     * The decoder's job is to be the one boundary that reports malformed worker
     * output, so a ValueError escaping it would reach the scan as an unhandled
     * error rather than as a rejected contribution.
     */
    #[Group('scanner')]
    public function testAnUnknownEnumValueArrivesAsAContributionError(): void
    {
        $node = self::node();
        $node['origin'] = 'telepathy';

        $error = self::refuse(self::reply([$node]));

        assertSame(WorkerException::class, $error::class);
    }

    /** A well-formed reply decodes, so the refusals above are about the malformation. */
    #[Group('scanner')]
    public function testAWellFormedReplyDecodes(): void
    {
        $contribution = ContributionDecoder::decode(self::reply([self::node()]));

        assertSame('php:file:src/A.php', $contribution->ownerKey);
        assertSame(1, count($contribution->nodes));
    }

    /** @param list<mixed> $nodes @return array<string, mixed> */
    private static function reply(array $nodes): array
    {
        return ['owner_key' => 'php:file:src/A.php', 'nodes' => $nodes, 'edges' => [], 'diagnostics' => []];
    }

    /** @return array<string, mixed> */
    private static function node(): array
    {
        return [
            'local_id' => 'php:class:App\\A',
            'kind' => 'class',
            'canonical_name' => 'App\\A',
            'display_name' => 'A',
            'origin' => 'ast',
            'confidence' => 'certain',
            'evidence' => ['path' => 'src/A.php', 'start_line' => 1, 'end_line' => 2],
        ];
    }

    /** @param array<string, mixed> $reply */
    private static function refuse(array $reply): \Throwable
    {
        return captureThrows(static fn() => ContributionDecoder::decode($reply), WorkerException::class);
    }
}
