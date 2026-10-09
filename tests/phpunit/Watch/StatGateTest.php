<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Watch;

use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Watch\StatGate;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertSame;

/** An idle tree costs stat calls, not hashes: the gate opens only when something the fingerprint saw moved. */
final class StatGateTest extends KnossosTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/deep', 0o700, true);
        mkdir($this->root . '/src/new', 0o700, true);
        file_put_contents($this->root . '/src/deep/A.php', 'a');
        file_put_contents($this->root . '/B.php', 'b');
        // Out of the racy second: the gate trusts these stats.
        $this->backdate(10);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    private function backdate(int $seconds): void
    {
        foreach (['', '/src', '/src/deep', '/src/new', '/src/deep/A.php', '/B.php'] as $path) {
            touch($this->root . $path, time() - $seconds);
        }
    }

    /**
     * A gate that remembered the fixture as a walk saw it.
     *
     * The walk is taken to have started after the fixture was written (two
     * seconds on, since a ctime cannot be backdated), so the fixture's own
     * creation is not a change during the walk. Null applies the default rule.
     *
     * @param list<string> $directories
     */
    private function gate(int $fullEveryMs = StatGate::FULL_EVERY_MS, ?int $walkStartedAt = PHP_INT_MIN, array $directories = []): StatGate
    {
        $gate = new StatGate($this->root, $fullEveryMs);
        $gate->remember(['src/deep/A.php' => 'h', 'B.php' => 'h'], $directories, $walkStartedAt === PHP_INT_MIN ? time() + 2 : $walkStartedAt);
        return $gate;
    }

    #[Group('watch')]
    public function testAnUntouchedTreeKeepsTheGateShutUntilTheFullCheckIsDue(): void
    {
        $gate = $this->gate(300);
        for ($i = 0; $i < 20; ++$i) {
            assertSame(false, $gate->mayHaveChanged());
        }
        usleep(350_000);
        assertSame(true, $gate->mayHaveChanged());
    }

    #[Group('watch')]
    public function testAnEditAFileAddedBesideAnotherAndADeletionEachOpenTheGate(): void
    {
        $gate = $this->gate();
        file_put_contents($this->root . '/src/deep/A.php', 'aa');
        assertSame(true, $gate->mayHaveChanged());

        $this->backdate(10);
        $gate = $this->gate();
        file_put_contents($this->root . '/src/deep/New.php', 'n');
        assertSame(true, $gate->mayHaveChanged());

        unlink($this->root . '/src/deep/New.php');
        $this->backdate(10);
        $gate = $this->gate();
        unlink($this->root . '/B.php');
        assertSame(true, $gate->mayHaveChanged());
    }

    #[Group('watch')]
    public function testAFileWrittenInTheSecondItWasSeenKeepsTheGateOpen(): void
    {
        touch($this->root . '/B.php');
        $gate = $this->gate(walkStartedAt: null);
        assertSame(true, $gate->mayHaveChanged());
        assertSame(true, $gate->mayHaveChanged());
    }

    /** A file created in a directory that held no discovered file moved only that directory's mtime, which the gate did not track. */
    #[Group('watch')]
    public function testAFileCreatedInAnEmptyWalkedDirectoryOpensTheGate(): void
    {
        $tracked = $this->gate(directories: ['src', 'src/deep', 'src/new']);
        $untracked = $this->gate();
        assertSame(false, $tracked->mayHaveChanged());

        file_put_contents($this->root . '/src/new/b.ts', 'b');
        touch($this->root . '/src/new/b.ts', time() + 5);

        assertSame(true, $tracked->mayHaveChanged());
        // The directories of discovered files alone, as before: the new file is unseen.
        assertSame(false, $untracked->mayHaveChanged());
    }

    /** The gate stat'ed after the walk, so an edit made while the tree was walked was recorded as already seen. */
    #[Group('watch')]
    public function testAnEditDuringTheWalkKeepsTheGateOpen(): void
    {
        touch($this->root . '/B.php', time() - 5);

        // The walk started 30 s ago and B.php moved 5 s ago, during it.
        assertSame(true, $this->gate(walkStartedAt: time() - 30)->mayHaveChanged());
        // A walk that started after the edit trusts it.
        assertSame(false, $this->gate()->mayHaveChanged());
    }

    /**
     * Racy means a time at or after one second before the walk started. Every
     * write moves the ctime, so the ctime is the change time; an mtime set
     * into the future (a skewed clock, an archive) counts as well.
     */
    #[Group('watch')]
    public function testTheRacyWindowStartsOneSecondBeforeTheWalk(): void
    {
        clearstatcache();
        $changed = max(array_map(
            fn(string $path): int => (int) filectime($this->root . $path),
            ['', '/src', '/src/deep', '/src/new', '/src/deep/A.php', '/B.php'],
        ));

        assertSame(true, $this->gate(walkStartedAt: $changed + 1)->mayHaveChanged());
        assertSame(false, $this->gate(walkStartedAt: $changed + 2)->mayHaveChanged());

        touch($this->root . '/B.php', $changed + 60);
        clearstatcache();
        $changed = max($changed, (int) filectime($this->root . '/B.php'));
        assertSame(true, $this->gate(walkStartedAt: $changed + 2)->mayHaveChanged());
    }

    /** A listed directory does not stand in for its ancestors: a file's directories up to the root are tracked whatever the list holds. */
    #[Group('watch')]
    public function testAListedDirectoryLeavesTheFilesAncestorsTracked(): void
    {
        $gate = $this->gate(directories: ['src/deep']);

        file_put_contents($this->root . '/src/C.php', 'c');
        touch($this->root . '/src/C.php', time() - 10);

        assertSame(true, $gate->mayHaveChanged());
    }
}
