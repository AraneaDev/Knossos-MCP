<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\Drift;

use Knossos\Git\GitProcessRunnerInterface;
use Knossos\Query\Drift\GitDriftOracle;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Driven through a faked runner, because CI has no git binary and no checkout.
 * What is under test is the parsing and the fallback decision, not git itself.
 */
final class GitDriftOracleTest extends KnossosTestCase
{
    /** Verified by hand-tracing the `-z` parser against this exact input: (changed 1, added 2, deleted 1). */
    #[Group('git')]
    public function testItSplitsNameStatusIntoTheThreeCounts(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead('3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c');
        try {
            $runner = $this->fakeRunner([
                "3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c\n",
                "M\0src/a.php\0A\0src/b.php\0D\0src/c.php\0",
                "src/untracked.php\0",
            ]);
            $drift = (new GitDriftOracle($pdo, $runner))->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));

            self::assertNotNull($drift);
            self::assertSame(1, $drift->changed);
            self::assertSame(2, $drift->added, 'An untracked file is an addition; it is exactly what a new component looks like.');
            self::assertSame(1, $drift->deleted);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Verified by hand-tracing the `-z` parser against this exact input: (changed 0, added 1, deleted 1). */
    #[Group('git')]
    public function testARenameCountsAsOneAdditionAndOneDeletion(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead('3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c');
        try {
            $runner = $this->fakeRunner([
                "3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c\n",
                "R100\0src/old.php\0src/new.php\0",
                '',
            ]);
            $drift = (new GitDriftOracle($pdo, $runner))->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));

            self::assertNotNull($drift);
            self::assertSame(1, $drift->added);
            self::assertSame(1, $drift->deleted);
            self::assertSame(0, $drift->changed, 'A pure rename moved no content, but it did move a component.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A scan taken before Task 1 recorded no commit at all; the oracle must hand over rather than guess. */
    #[Group('git')]
    public function testItReturnsNullWhenTheScanRecordedNoHead(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(null);
        try {
            $drift = (new GitDriftOracle($pdo, $this->fakeRunner([])))
                ->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));

            self::assertNull($drift);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A rebased or garbage-collected commit must hand over to the walk, not report zero drift. */
    #[Group('git')]
    public function testItReturnsNullWhenTheRecordedHeadNoLongerResolves(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead('3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c');
        try {
            $drift = (new GitDriftOracle($pdo, $this->failingRunner()))
                ->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));

            self::assertNull($drift, 'A rebased or garbage-collected commit must hand over to the walk, not report zero drift.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Seeds a project with one file and stamps its scan's `git_head` to the
     * given value, so each test controls what the oracle finds without
     * touching the fixture's own scan-creation path.
     *
     * @return array{0: PDO, 1: string, 2: string, 3: string}
     */
    private function seedWithHead(?string $head): array
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        $project = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :project');
        $project->execute(['project' => $projectId]);
        $scanId = (string) $project->fetchColumn();
        $pdo->prepare('UPDATE scans SET git_head = :head WHERE id = :id')->execute(['head' => $head, 'id' => $scanId]);

        return [$pdo, $projectId, $root, $scanId];
    }

    /** Looks up a scan's finish time by parameter binding, not string interpolation. */
    private function finishedAt(PDO $pdo, string $scanId): string
    {
        $statement = $pdo->prepare('SELECT finished_at FROM scans WHERE id = :id');
        $statement->execute(['id' => $scanId]);

        return (string) $statement->fetchColumn();
    }

    /** A runner that hands back each of $outputs in call order, standing in for rev-parse, diff and ls-files. */
    private function fakeRunner(array $outputs): GitProcessRunnerInterface
    {
        return new class ($outputs) implements GitProcessRunnerInterface {
            private int $call = 0;

            /** @param list<string> $outputs */
            public function __construct(private readonly array $outputs) {}

            public function run(array $command, int $timeoutMs, string $operation): string
            {
                return $this->outputs[$this->call++] ?? '';
            }
        };
    }

    /** A runner that fails every call, standing in for a commit that no longer resolves. */
    private function failingRunner(): GitProcessRunnerInterface
    {
        return new class implements GitProcessRunnerInterface {
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                throw new RuntimeException('bad revision');
            }
        };
    }
}
