<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Git;

use Knossos\Git\GitLogRecords;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `git log -z --name-only` output split into commits, with every path exactly
 * as git wrote it: no unquoting, no trimming, one leading newline removed
 * from the first path after a header and from nothing else.
 */
#[Group('git')]
final class GitLogRecordsTest extends KnossosTestCase
{
    private const MARKER = "KNOSSOS_COMMIT\x1f";

    public function testAHeaderAndItsPaths(): void
    {
        $output = "\0KNOSSOS_COMMIT\x1fa5c3\x1fa@b\0\nwe\"ird.ts\0src/x.ts\0";

        assertSame([['fields' => ['a5c3', 'a@b'], 'paths' => ['we"ird.ts', 'src/x.ts']]], GitLogRecords::parse($output, self::MARKER));
    }

    public function testTwoCommits(): void
    {
        $output = "\0KNOSSOS_COMMIT\x1fa5c3\x1fa@b\0\nwe\"ird.ts\0\0KNOSSOS_COMMIT\x1f91c7\x1fa@b\0\nsrc/x.ts\0";

        assertSame(
            [['fields' => ['a5c3', 'a@b'], 'paths' => ['we"ird.ts']], ['fields' => ['91c7', 'a@b'], 'paths' => ['src/x.ts']]],
            GitLogRecords::parse($output, self::MARKER),
        );
    }

    /** A commit that changed nothing: git writes its header and then the next one at once. */
    public function testACommitWithNoPaths(): void
    {
        $output = "\0KNOSSOS_COMMIT\x1fempty\0\0KNOSSOS_COMMIT\x1ffull\0\nsrc/x.ts\0";

        assertSame(
            [['fields' => ['empty'], 'paths' => []], ['fields' => ['full'], 'paths' => ['src/x.ts']]],
            GitLogRecords::parse($output, self::MARKER),
        );
    }

    /** Only the first path after a header carries git's separator newline; a later one keeps its own. */
    public function testALeadingNewlineIsKeptWhenThePathIsNotFirst(): void
    {
        $output = "\0KNOSSOS_COMMIT\x1fa\0\nfirst.ts\0\nsecond.ts\0";

        assertSame([['fields' => ['a'], 'paths' => ['first.ts', "\nsecond.ts"]]], GitLogRecords::parse($output, self::MARKER));
    }

    /** Exactly one newline is the separator: a first path that itself starts with one keeps it. */
    public function testOnlyOneNewlineIsRemovedFromTheFirstPath(): void
    {
        $output = "\0KNOSSOS_COMMIT\x1fa\0\n\nfirst.ts\0";

        assertSame([['fields' => ['a'], 'paths' => ["\nfirst.ts"]]], GitLogRecords::parse($output, self::MARKER));
    }

    public function testSpacesTabsAndNewlinesInsideAPathSurvive(): void
    {
        $output = "\0KNOSSOS_COMMIT\x1fa\0\n spaced.ts \0tab\tname.ts\0new\nline.ts\0";

        assertSame([' spaced.ts ', "tab\tname.ts", "new\nline.ts"], GitLogRecords::parse($output, self::MARKER)[0]['paths']);
    }

    public function testPathsBeforeTheFirstHeaderAreIgnored(): void
    {
        $output = "garbage.ts\0\nmore.ts\0\0KNOSSOS_COMMIT\x1fa\0\nsrc/x.ts\0";

        assertSame([['fields' => ['a'], 'paths' => ['src/x.ts']]], GitLogRecords::parse($output, self::MARKER));
    }

    public function testEmptyOutputHasNoRecords(): void
    {
        assertSame([], GitLogRecords::parse('', self::MARKER));
    }

    /** A header with nothing after the marker has one empty field, and is still a commit. */
    public function testAMarkerWithNoFields(): void
    {
        assertSame([['fields' => [''], 'paths' => ['x.ts']]], GitLogRecords::parse("\0KNOSSOS_CHURN\x1f\0\nx.ts\0", "KNOSSOS_CHURN\x1f"));
    }

    /**
     * A file may be named like a header: only a token after an empty one (the
     * NUL the format puts before every header) starts a commit, and a path is
     * never empty, so no file name can forge one.
     */
    public function testAPathNamedLikeAHeaderIsAPath(): void
    {
        $output = "\0KNOSSOS_COMMIT\x1fa\0\nA.ts\0KNOSSOS_COMMIT\x1fforged\0\0KNOSSOS_COMMIT\x1fb\0\nKNOSSOS_COMMIT\x1fx\0";

        assertSame(
            [['fields' => ['a'], 'paths' => ['A.ts', "KNOSSOS_COMMIT\x1fforged"]], ['fields' => ['b'], 'paths' => ["KNOSSOS_COMMIT\x1fx"]]],
            GitLogRecords::parse($output, self::MARKER),
        );
    }
}
