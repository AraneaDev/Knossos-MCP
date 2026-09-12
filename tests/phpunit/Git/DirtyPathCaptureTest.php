<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Git;

use Knossos\Discovery\DiscoveredFile;
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

    /**
     * The set is what discovery read against what the commit holds, not what
     * git would call dirty at some later moment. `changed.php` hashes to
     * something the tree does not hold and is dirty; `clean.php` matches its
     * committed blob and is not.
     */
    #[Group('git')]
    public function testAReadThatDiffersFromTheCommittedBlobIsDirty(): void
    {
        $set = $this->resolve(
            ['src/clean.php' => 'aaaa', 'src/changed.php' => 'bbbb'],
            [$this->discovered('src/clean.php', 'aaaa'), $this->discovered('src/changed.php', 'cccc')],
        );

        self::assertNotNull($set);
        self::assertSame(['src/changed.php'], $set->paths);
        self::assertTrue($set->complete);
    }

    /**
     * The case the whole derivation exists for: a file modified while
     * discovery read it and restored before anything else ran. Every later
     * question to git calls it clean, and a set built from such a question
     * omits it while the graph holds a hash of the modified bytes. Comparing
     * the read itself against an immutable tree cannot be fooled that way.
     */
    #[Group('git')]
    public function testAReadThatWasRestoredAfterwardsIsStillDirty(): void
    {
        // The tree holds the committed blob; the file on disk now holds it
        // again too. Only what discovery read disagrees, and that is what is
        // compared.
        $set = $this->resolve(
            ['src/edited.php' => 'committed-blob'],
            [$this->discovered('src/edited.php', 'blob-of-the-modified-bytes')],
        );

        self::assertNotNull($set);
        self::assertSame(['src/edited.php'], $set->paths, 'The scan stored a hash of bytes that are no longer on disk, whatever git would say about the file now.');
    }

    /** A file the commit does not hold was untracked when it was read, so there is no committed content for its stored hash to disagree with. */
    #[Group('git')]
    public function testAPathTheCommitDoesNotHoldIsNotDirty(): void
    {
        $set = $this->resolve(['src/tracked.php' => 'aaaa'], [$this->discovered('src/untracked.php', 'bbbb')]);

        self::assertNotNull($set);
        self::assertSame([], $set->paths);
    }

    /** A read that could not be pinned to a blob id cannot be compared, and "cannot be compared" must not resolve to "matched". */
    #[Group('git')]
    public function testAReadWithNoBlobIdIsTreatedAsDirty(): void
    {
        $set = $this->resolve(['src/racing.php' => 'aaaa'], [$this->discovered('src/racing.php', null)]);

        self::assertNotNull($set);
        self::assertSame(['src/racing.php'], $set->paths);
    }

    /** Entries that are not blobs, such as a submodule's commit entry, name nothing discovery read and must not become paths. */
    #[Group('git')]
    public function testANonBlobTreeEntryIsIgnored(): void
    {
        $listing = "160000 commit 1111111111111111111111111111111111111111\tvendor/sub\0"
            . "100644 blob 2222222222222222222222222222222222222222\tsrc/a.php\0";

        $set = (new DirtyPathResolver($this->runner($listing)))
            ->resolve('/tmp/knossos-dirty', self::HEAD, [$this->discovered('src/a.php', '2222222222222222222222222222222222222222')]);

        self::assertNotNull($set);
        self::assertSame([], $set->paths);
    }

    /**
     * The argv is the contract. `-C` with a `.` pathspec is what makes the
     * reported paths relative to the scanned root and keeps a monorepo's
     * sibling packages out; the recorded commit rather than a symbolic HEAD is
     * what makes the comparison describe the scan this set accompanies.
     */
    #[Group('git')]
    public function testItPinsTheCommandItIssues(): void
    {
        $runner = $this->capturingRunner();

        (new DirtyPathResolver($runner))->resolve('/tmp/knossos-dirty', self::HEAD, []);

        self::assertSame([[
            'git', '--no-optional-locks', '--no-pager', '-C', '/tmp/knossos-dirty',
            'ls-tree', '-r', '-z', self::HEAD, '--', '.',
        ]], $runner->commands);
        self::assertSame([2000], $runner->timeouts);
        self::assertSame(['scan head tree'], $runner->operations);
    }

    /** A git that could not answer leaves no set at all, rather than an empty one that would read as a clean tree. */
    #[Group('git')]
    public function testAFailedListingRecordsNothingRatherThanACleanTree(): void
    {
        $log = sys_get_temp_dir() . '/knossos-dirty-breadcrumb-' . bin2hex(random_bytes(6)) . '.log';
        $previous = ini_set('error_log', $log);
        try {
            $set = (new DirtyPathResolver($this->failingRunner()))->resolve('/tmp/knossos-dirty', self::HEAD, []);

            self::assertNull($set, 'An empty set would claim every read matched the commit; the truth is that nothing is known.');
            self::assertStringContainsString('scan head tree', (string) @file_get_contents($log), 'The degraded oracle that follows is invisible from outside, so the reason has to be findable.');
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($log);
        }
    }

    /**
     * Runs the resolver against a faked `ls-tree` listing built from the given
     * committed blobs.
     *
     * @param array<string, string> $tree relative path => committed blob id
     * @param list<DiscoveredFile> $files
     */
    private function resolve(array $tree, array $files): ?DirtyPathSet
    {
        $entries = '';
        foreach ($tree as $path => $blob) {
            $entries .= '100644 blob ' . $blob . "\t" . $path . "\0";
        }

        return (new DirtyPathResolver($this->runner($entries)))->resolve('/tmp/knossos-dirty', self::HEAD, $files);
    }

    /** One discovered file, carrying only the two fields this resolver reads. */
    private function discovered(string $relativePath, ?string $gitBlobHash): DiscoveredFile
    {
        return new DiscoveredFile(
            $relativePath,
            '/tmp/knossos-dirty/' . $relativePath,
            'php',
            1,
            1,
            hash('sha256', $relativePath),
            1,
            $gitBlobHash,
        );
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
