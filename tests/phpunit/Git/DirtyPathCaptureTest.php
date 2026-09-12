<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Git;

use Knossos\Git\DirtyPathResolver;
use Knossos\Git\DirtyPathSet;
use Knossos\Git\GitProcessRunnerInterface;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * What a scan records about the paths that differed from its commit, and what
 * the recording refuses to claim.
 *
 * The set is the only thing standing between the drift oracle and a file that
 * was scanned dirty and has since been restored, which git's own listings
 * cannot name at all. An absent, unparseable or truncated record therefore has
 * to read as "unknown" rather than as "clean": the first costs a walk, the
 * second is a false `fresh`. Driven through a faked runner, because CI has no
 * git binary and no checkout.
 */
final class DirtyPathCaptureTest extends KnossosTestCase
{
    private const HEAD = '3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c';

    /** A NUL-separated listing is the set, deduplicated and ordered so the same tree encodes identically. */
    #[Group('git')]
    public function testItReadsTheNulSeparatedListingAsTheSet(): void
    {
        $set = (new DirtyPathResolver($this->runner("src/b.php\0src/a.php\0src/a.php\0")))->resolve('/tmp/knossos-dirty', self::HEAD);

        self::assertNotNull($set);
        self::assertSame(['src/a.php', 'src/b.php'], $set->paths);
        self::assertTrue($set->complete);
    }

    /**
     * The argv is the contract, and it has to be spelled exactly like the
     * drift oracle's own changed-file listing: the two sets are unioned later,
     * so a difference in `--relative` or the pathspec would be a silent
     * mismatch between paths that name the same file.
     */
    #[Group('git')]
    public function testItPinsTheCommandItIssues(): void
    {
        $runner = $this->capturingRunner();

        (new DirtyPathResolver($runner))->resolve('/tmp/knossos-dirty', self::HEAD);

        self::assertSame([[
            'git', '--no-optional-locks', '--no-pager', '-C', '/tmp/knossos-dirty',
            'diff', '--name-only', '-z', '--no-ext-diff', '--no-renames', '--relative', self::HEAD, '--', '/tmp/knossos-dirty',
        ]], $runner->commands);
        self::assertSame([2000], $runner->timeouts);
        self::assertSame(['scan dirty paths'], $runner->operations);
    }

    /** A git that could not answer leaves no set at all, rather than an empty one that would read as a clean tree. */
    #[Group('git')]
    public function testAFailedListingRecordsNothingRatherThanACleanTree(): void
    {
        $log = sys_get_temp_dir() . '/knossos-dirty-breadcrumb-' . bin2hex(random_bytes(6)) . '.log';
        $previous = ini_set('error_log', $log);
        try {
            $set = (new DirtyPathResolver($this->failingRunner()))->resolve('/tmp/knossos-dirty', self::HEAD);

            self::assertNull($set, 'An empty set would claim a clean tree; the truth is that nothing is known.');
            self::assertStringContainsString('scan dirty paths', (string) @file_get_contents($log), 'The degraded oracle that follows is invisible from outside, so the reason has to be findable.');
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($log);
        }
    }

    /** A tree dirty past the persisted bound is marked incomplete rather than trimmed into a complete-looking set. */
    #[Group('git')]
    public function testASetPastTheBoundIsMarkedIncomplete(): void
    {
        $paths = [];
        for ($index = 0; $index <= DirtyPathSet::MAX_PATHS; ++$index) {
            $paths[] = sprintf('src/f%06d.php', $index);
        }

        $set = DirtyPathSet::of($paths);

        self::assertFalse($set->complete);
        self::assertCount(DirtyPathSet::MAX_PATHS, $set->paths);
        self::assertNull(DirtyPathSet::decode($set->encode()), 'An incomplete set must not decode into one a reader would act on.');
    }

    /** A complete set survives the round trip, which is what the oracle reads back on every probe. */
    #[Group('git')]
    public function testACompleteSetSurvivesTheRoundTrip(): void
    {
        $decoded = DirtyPathSet::decode(DirtyPathSet::of(['src/a.php', 'src/b.php'])->encode());

        self::assertNotNull($decoded);
        self::assertSame(['src/a.php', 'src/b.php'], $decoded->paths);
        self::assertSame(['src/a.php' => true, 'src/b.php' => true], $decoded->asKeys());
    }

    /** Every shape that is not a trustworthy record decodes to null, so one gate covers them all. */
    #[Group('git')]
    public function testAnUntrustworthyRecordDecodesToNothing(): void
    {
        self::assertNull(DirtyPathSet::decode(null), 'A scan predating the column recorded nothing.');
        self::assertNull(DirtyPathSet::decode(''), 'An empty column is not an empty set.');
        self::assertNull(DirtyPathSet::decode('not json'), 'A value that no longer parses says nothing about the tree.');
        self::assertNull(DirtyPathSet::decode('{"paths":["a.php"]}'), 'Completeness is never assumed when it was not recorded.');
        self::assertNull(DirtyPathSet::decode('{"paths":[17],"complete":true}'), 'A path that is not a string is a corrupt record, not a path.');
        self::assertSame([], DirtyPathSet::decode(DirtyPathSet::clean()->encode())?->paths, 'A recorded clean tree is an answer, and the one case that is not null.');
    }

    /** A runner answering with fixed output, standing in for a git binary CI does not have. */
    private function runner(string $output): GitProcessRunnerInterface
    {
        return new class ($output) implements GitProcessRunnerInterface {
            public function __construct(private string $output) {}

            /** Answers the fixed listing whatever it is asked. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                return $this->output;
            }
        };
    }

    /** A runner that records what it was asked, so the argv can be asserted rather than described. */
    private function capturingRunner(): object
    {
        return new class implements GitProcessRunnerInterface {
            /** @var list<list<string>> */
            public array $commands = [];

            /** @var list<int> */
            public array $timeouts = [];

            /** @var list<string> */
            public array $operations = [];

            /** Records the call and answers an empty listing. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                $this->commands[] = $command;
                $this->timeouts[] = $timeoutMs;
                $this->operations[] = $operation;

                return '';
            }
        };
    }

    /** A runner standing in for a git that was there and failed anyway. */
    private function failingRunner(): GitProcessRunnerInterface
    {
        return new class implements GitProcessRunnerInterface {
            /** Fails the way a timeout or an absent binary does. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                throw new RuntimeException('timed out after 2000 ms');
            }
        };
    }
}
