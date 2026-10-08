<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Reconciliation\ContributionCacheEntry;
use Knossos\Scan\ProgramEnvironments;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Worker\WorkerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A contribution reused from an earlier build of a program is stale once this
 * scan builds that program with other global declarations.
 */
#[Group('scan')]
final class ProgramEnvironmentsTest extends TestCase
{
    public function testAReusedContributionOfARebuiltProgramWithAnotherEnvironmentIsStale(): void
    {
        $old = self::hash('old');
        $new = self::hash('new');
        $entries = [
            self::entry('p1/edited.ts', 'p1', $new, false),
            self::entry('p1/same.ts', 'p1', $new, true),
            self::entry('p1/stale.ts', 'p1', $old, true),
            self::entry('p1/unlabelled.ts', 'p1', null, true),
            self::entry('p2/other.ts', 'p2', $old, true),
            self::entry('left-out.ts', null, null, true),
        ];

        assertSame(
            ['o:p1/stale.ts' => true, 'o:p1/unlabelled.ts' => true],
            ProgramEnvironments::staleOwners($entries),
        );
    }

    public function testAScanThatBuildsNoProgramFindsNothingStale(): void
    {
        assertSame([], ProgramEnvironments::staleOwners([self::entry('a.ts', 'p1', self::hash('old'), true)]));
    }

    public function testAProgramBuiltWithoutEmittingAnythingStillRetiresItsReusedContributions(): void
    {
        // A file two configs include is emitted by one program; the other is
        // built for it all the same and reports its environment on the result.
        $entries = [
            self::entry('shared/x.ts', 'p1', self::hash('p1'), false),
            self::entry('p2/d.ts', 'p2', self::hash('p2 before'), true),
            self::entry('p2/same.ts', 'p2', self::hash('p2 after'), true),
            self::entry('p3/far.ts', 'p3', self::hash('p3'), true),
        ];

        assertSame(
            ['o:p2/d.ts' => true],
            ProgramEnvironments::staleOwners($entries, ['p1' => self::hash('p1'), 'p2' => self::hash('p2 after')]),
        );
    }

    public function testAReusedContributionOfAFileNoConfigListsIsStaleOnceAConfigsProgramRebuiltAnything(): void
    {
        $same = self::hash('same');
        $entries = [
            self::entry('p1/edited.ts', 'p1', $same, false),
            self::entry('p1/reached.ts', 'p1', $same, true, listed: false),
            self::entry('test/t.ts', 'fallback:test', $same, true, listed: false),
            self::entry('p1/listed.ts', 'p1', $same, true),
            self::entry('p1/moved.ts', 'fallback:p1', $same, false, listed: false),
        ];

        assertSame(
            ['o:p1/reached.ts' => true, 'o:test/t.ts' => true],
            ProgramEnvironments::unlistedOwners($entries),
        );
    }

    public function testAScanThatRebuiltOnlyFilesOfFallbackProgramsLeavesTheUnlistedFilesAlone(): void
    {
        // A fallback program holds its whole group whatever the imports say,
        // and a config's program is driven from its own root files, so an
        // edit reaching only fallback files moves nothing.
        $same = self::hash('same');
        $entries = [
            self::entry('test/edited.ts', 'fallback:test', $same, false, listed: false),
            self::entry('test/t.ts', 'fallback:test', $same, true, listed: false),
            self::entry('p1/reached.ts', 'p1', $same, true, listed: false),
        ];

        assertSame([], ProgramEnvironments::unlistedOwners($entries));
        assertSame([], ProgramEnvironments::unlistedOwners([self::entry('test/t.ts', 'fallback:test', $same, true, listed: false)]));
    }

    public function testARebuiltContributionNoProgramDescribedRetiresTheUnlistedFilesToo(): void
    {
        $entries = [
            self::entry('broken.ts', null, null, false),
            self::entry('test/t.ts', 'fallback:test', self::hash('same'), true, listed: false),
        ];

        assertSame(['o:test/t.ts' => true], ProgramEnvironments::unlistedOwners($entries));
    }

    public function testTheResultsEnvironmentsAreProgramKeysToDigests(): void
    {
        assertSame([], ProgramEnvironments::fromResult(['input_hashes' => []], 'knossos.fake'));
        assertSame(
            ['fallback:.' => self::hash('f'), 'tsconfig.json' => self::hash('t')],
            ProgramEnvironments::fromResult(['environments' => ['fallback:.' => self::hash('f'), 'tsconfig.json' => self::hash('t')]], 'knossos.fake'),
        );
    }

    #[DataProvider('malformedEnvironments')]
    public function testMalformedEnvironmentsAreRefused(mixed $environments, string $message): void
    {
        $error = captureThrows(
            static fn() => ProgramEnvironments::fromResult(['environments' => $environments], 'knossos.fake'),
            WorkerException::class,
        );

        assertSame('WORKER_RESPONSE_INVALID', $error->diagnosticCode);
        assertSame($message, $error->getMessage());
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function malformedEnvironments(): iterable
    {
        yield 'a list' => [[self::hash('t')], 'knossos.fake sent environments that is not an object keyed by program.'];
        yield 'a string' => ['x', 'knossos.fake sent environments that is not an object keyed by program.'];
        yield 'an empty program key' => [['' => self::hash('t')], 'knossos.fake sent environments whose entry for program "" is not a lowercase SHA-256 hex digest.'];
        yield 'a digest that is not hex' => [['tsconfig.json' => 'not hex'], 'knossos.fake sent environments whose entry for program "tsconfig.json" is not a lowercase SHA-256 hex digest.'];
        yield 'a null digest' => [['tsconfig.json' => null], 'knossos.fake sent environments whose entry for program "tsconfig.json" is not a lowercase SHA-256 hex digest.'];
    }

    private static function entry(string $path, ?string $program, ?string $environment, bool $fromCache, bool $listed = true): ContributionCacheEntry
    {
        $contribution = new ScanContribution('o:' . $path, contentHash: self::hash($path), reads: [], program: $program, environment: $environment, listed: $listed);

        return new ContributionCacheEntry($path, self::hash($path), 'knossos.typescript', '1', 'cfg', $contribution, [], null, true, $fromCache);
    }

    private static function hash(string $seed): string
    {
        return hash('sha256', $seed);
    }
}
