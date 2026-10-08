<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Reconciliation\ContributionCacheEntry;
use Knossos\Scan\ProgramEnvironments;
use Knossos\Scanner\Protocol\ScanContribution;
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

    private static function entry(string $path, ?string $program, ?string $environment, bool $fromCache): ContributionCacheEntry
    {
        $contribution = new ScanContribution('o:' . $path, contentHash: self::hash($path), reads: [], program: $program, environment: $environment);

        return new ContributionCacheEntry($path, self::hash($path), 'knossos.typescript', '1', 'cfg', $contribution, [], null, true, $fromCache);
    }

    private static function hash(string $seed): string
    {
        return hash('sha256', $seed);
    }
}
