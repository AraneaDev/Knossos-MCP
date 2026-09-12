<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * What a policy declaration may contain, at each limit it advertises.
 *
 * ArchitecturePolicyQueryService scored 68% under mutation testing, and its
 * validation survived in the usual shape: values well outside each limit are
 * refused and the limit itself is never accepted, so every comparison could move
 * by one and stay green.
 *
 * One of the boundaries here is named with two hundred characters on purpose. A
 * target that long has to resolve to something, or the length limit is never
 * reached and the test would prove only that unknown boundaries are refused.
 */
final class PolicyValidationBoundsTest extends KnossosTestCase
{
    private const LONG_NAME_LENGTH = 200;

    /** Fifty policies are accepted; fifty-one are refused. */
    #[Group('query')]
    public function testTheDeclarationCapAcceptsFiftyAndRefusesFiftyOne(): void
    {
        [$pdo, $project] = $this->fixture();

        assertSame(50, count(self::check($pdo, $project, self::policies(50))->data['policies_evaluated']));
        assertThrows(fn() => self::check($pdo, $project, self::policies(51)), InvalidArgumentException::class);
        assertThrows(fn() => self::check($pdo, $project, []), InvalidArgumentException::class);
    }

    /** A policy id of exactly a hundred bytes is accepted; one more is refused. */
    #[Group('query')]
    public function testThePolicyIdLengthLimitIsAHundredBytes(): void
    {
        [$pdo, $project] = $this->fixture();

        assertSame(1, count(self::check($pdo, $project, [self::policy(str_repeat('i', 100))])->data['policies_evaluated']));

        $tooLong = captureThrows(
            fn() => self::check($pdo, $project, [self::policy(str_repeat('i', 101))]),
            InvalidArgumentException::class,
        );
        assertSame('Policy id must be a non-empty string of at most 100 bytes.', $tooLong->getMessage());

        // Whitespace is not an id, and is reported as the id being wrong.
        $blank = captureThrows(
            fn() => self::check($pdo, $project, [self::policy('   ')]),
            InvalidArgumentException::class,
        );
        assertSame('Policy id must be a non-empty string of at most 100 bytes.', $blank->getMessage());
    }

    /** A from_boundary of whitespace is refused as an empty boundary, not as an unknown one. */
    #[Group('query')]
    public function testAWhitespaceFromBoundaryIsRefusedAsEmpty(): void
    {
        [$pdo, $project] = $this->fixture();

        $error = captureThrows(
            fn() => self::check($pdo, $project, [['id' => 'p', 'from_boundary' => "\t ", 'deny_targets' => ['Core']]]),
            InvalidArgumentException::class,
        );

        assertSame('Policy from_boundary must be a non-empty boundary ID or name.', $error->getMessage());
    }

    /** A target of exactly two hundred bytes is accepted; one more is refused. */
    #[Group('query')]
    public function testTheTargetLengthLimitIsTwoHundredBytes(): void
    {
        [$pdo, $project] = $this->fixture();
        $longName = str_repeat('b', self::LONG_NAME_LENGTH);

        $accepted = self::check($pdo, $project, [['id' => 'p', 'from_boundary' => 'Core', 'deny_targets' => [$longName]]]);
        assertSame(1, count($accepted->data['policies_evaluated']));

        $error = captureThrows(
            fn() => self::check($pdo, $project, [['id' => 'p', 'from_boundary' => 'Core', 'deny_targets' => [str_repeat('b', 201)]]]),
            InvalidArgumentException::class,
        );
        assertSame('Policy deny_targets values must be non-empty strings of at most 200 bytes.', $error->getMessage());
    }

    /** A target list of exactly fifty entries is accepted; fifty-one are refused. */
    #[Group('query')]
    public function testTheTargetListCapAcceptsFiftyAndRefusesFiftyOne(): void
    {
        [$pdo, $project] = $this->fixture();

        // The cap counts entries as given, before duplicates are collapsed.
        $accepted = self::check($pdo, $project, [['id' => 'p', 'from_boundary' => 'Core', 'deny_targets' => array_fill(0, 50, '@unassigned')]]);
        assertSame(['@unassigned'], $accepted->data['policies_evaluated'][0]['deny_target_ids']);

        $error = captureThrows(
            fn() => self::check($pdo, $project, [['id' => 'p', 'from_boundary' => 'Core', 'deny_targets' => array_fill(0, 51, '@unassigned')]]),
            InvalidArgumentException::class,
        );
        assertSame('Policy deny_targets must be a list of at most 50 values.', $error->getMessage());
    }

    /** Two policies may not share an id, and a policy may not carry an unknown field. */
    #[Group('query')]
    public function testPolicyIdsAreUniqueAndFieldsAreKnown(): void
    {
        [$pdo, $project] = $this->fixture();

        $duplicate = captureThrows(
            fn() => self::check($pdo, $project, [self::policy('same'), self::policy('same')]),
            InvalidArgumentException::class,
        );
        assertSame('Policy ids must be unique: same', $duplicate->getMessage());

        $unknown = captureThrows(
            fn() => self::check($pdo, $project, [['id' => 'p', 'from_boundary' => 'Core', 'deny_targets' => ['Core'], 'denyTargets' => ['Core']]]),
            InvalidArgumentException::class,
        );
        assertSame('Policy contains unknown fields: denyTargets', $unknown->getMessage());
    }

    /** @param list<array<string, mixed>> $policies */
    private static function check(PDO $pdo, string $projectId, array $policies): \Knossos\Query\ResultEnvelope
    {
        return (new ArchitectureQueryService($pdo))->checkArchitecture($projectId, $policies);
    }

    /** @return array<string, mixed> */
    private static function policy(string $id): array
    {
        return ['id' => $id, 'from_boundary' => 'Core', 'deny_targets' => ['Core']];
    }

    /** @return list<array<string, mixed>> */
    private static function policies(int $count): array
    {
        return array_map(static fn(int $index): array => self::policy('p' . $index), range(1, $count));
    }

    /** @return array{0: PDO, 1: string} */
    private function fixture(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        foreach (['Core', str_repeat('b', self::LONG_NAME_LENGTH)] as $name) {
            $boundary = StableId::boundary($ids['project'], $name, 'explicit');
            $repository->saveBoundary($boundary, $ids['project'], $name, ['path_prefix' => 'src'], 'explicit', $ids['scan']);
            $repository->saveBoundaryMembership($boundary, $ids['project'], $ids['checkout'], $ids['scan']);
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        return [$pdo, $ids['project']];
    }
}
