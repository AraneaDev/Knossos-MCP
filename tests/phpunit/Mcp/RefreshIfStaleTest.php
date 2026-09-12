<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Git\GitProcessRunnerInterface;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\Drift\FirstAnsweringDriftOracle;
use Knossos\Query\Drift\GitDriftOracle;
use Knossos\Query\Drift\WalkDriftOracle;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

final class RefreshIfStaleTest extends KnossosTestCase
{
    /** The commit every scan of the git-backed fixture records, spelled as a literal so no test value is ever interpolated into SQL. */
    private const GIT_HEAD = '3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c';

    /** A graph rebuilt because it was stale must come back fresh, or the refresh bought the caller nothing. */
    #[Group('mcp')]
    public function testStaleGraphIsRescannedBeforeAnswering(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, "\n// drift\n", FILE_APPEND);
            touch($file, filemtime($file) + 60);
            assertSame('stale', (new StalenessProbe($pdo))->probe($projectId)['state']);

            $result = $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);

            assertSame('fresh', $result->staleness['state']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The same promise, on the kind of project this server ships into: a git
     * checkout carrying uncommitted edits.
     *
     * Saving a file does not move HEAD, so every scan of such a repository
     * records the same commit the previous one did, and a drift oracle that
     * reads `git diff <recorded head>` as the verdict reports the edit the
     * scan already absorbed forever. The trigger reproduces that condition
     * exactly; the runner is faked because CI has neither a git binary nor a
     * checkout, and what is under test is the oracle's own reasoning rather
     * than git's.
     */
    #[Group('mcp')]
    public function testARefreshOnAGitBackedProjectAnswersFreshAgain(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $tracked = $pdo->query('SELECT relative_path FROM files')->fetchAll(PDO::FETCH_COLUMN);
            $pdo->prepare('UPDATE scans SET git_head = :head')->execute(['head' => self::GIT_HEAD]);
            // Every later scan records the same commit, because nothing here commits.
            $pdo->exec(
                'CREATE TRIGGER stamp_git_head AFTER INSERT ON scans BEGIN ' .
                "UPDATE scans SET git_head = '" . self::GIT_HEAD . "' WHERE id = NEW.id; END",
            );
            file_put_contents($root . '/src/CheckoutService.php', "\n// drift\n", FILE_APPEND);

            $oracle = new FirstAnsweringDriftOracle(
                new GitDriftOracle($pdo, $this->gitRunnerReporting(['src/CheckoutService.php'], $tracked)),
                new WalkDriftOracle($pdo),
            );
            $tools = new ToolService(
                new ProjectScanService($pdo, self::repositoryRoot(), [$root]),
                new ArchitectureQueryService($pdo, driftOracle: $oracle),
                new DatabaseMaintenanceService($pdo, ':memory:'),
                new ResultEnricher(new StalenessProbe($pdo, oracle: $oracle), new NextStepPlanner()),
            );
            assertSame('stale', (new StalenessProbe($pdo, oracle: $oracle))->probe($projectId)['state']);

            $result = $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);

            assertSame('fresh', $result->staleness['state'], 'A refresh that rebuilt the graph must clear the staleness that triggered it.');
            assertSame([], $result->warnings, 'A refresh that succeeded says so by the state alone; a warning would fire on every call.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A runner that answers each git subcommand the drift oracle asks by name
     * rather than by call order, so it stays correct however many times the
     * oracle is consulted within one tool call.
     *
     * @param list<string> $changed paths `git diff` reports against the recorded commit
     * @param list<string> $tracked paths the index holds, which is what git knows about at all
     */
    private function gitRunnerReporting(array $changed, array $tracked): GitProcessRunnerInterface
    {
        return new class (self::GIT_HEAD, $changed, $tracked) implements GitProcessRunnerInterface {
            /**
             * @param list<string> $changed
             * @param list<string> $tracked
             */
            public function __construct(
                private readonly string $head,
                private readonly array $changed,
                private readonly array $tracked,
            ) {}

            /** Canned stdout for whichever subcommand $command names. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                return match (true) {
                    in_array('rev-parse', $command, true) => $this->head . "\n",
                    in_array('diff', $command, true) => self::nulSeparated($this->changed),
                    in_array('--cached', $command, true) => self::nulSeparated($this->tracked),
                    default => '',
                };
            }

            /** Git's own `-z` framing: every entry terminated by a NUL, nothing quoted. */
            private static function nulSeparated(array $paths): string
            {
                return $paths === [] ? '' : implode("\0", $paths) . "\0";
            }
        };
    }

    /** A fresh graph must not be rescanned, and a rescan that cannot run must warn rather than fail the query. */
    #[Group('mcp')]
    public function testFreshGraphSkipsRescanAndFailedRescanWarnsInsteadOfErroring(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $scans = fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();

            $before = $scans();
            $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);
            assertSame($before, $scans()); // fresh: no rescan attempted

            // Stale project whose root is outside the scanner's allowed roots:
            // the rescan fails, the query still answers, and a warning explains.
            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, "\n// drift\n", FILE_APPEND);
            touch($file, filemtime($file) + 60);
            $confined = new ToolService(
                new ProjectScanService($pdo, self::repositoryRoot(), ['/nonexistent-allowed-root']),
                new ArchitectureQueryService($pdo),
                new DatabaseMaintenanceService($pdo, ':memory:'),
                new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
            );
            $result = $confined->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);
            assertSame($projectId, $result->projectId);
            $joined = implode(' ', $result->warnings);
            assertSame(true, str_contains($joined, 'refresh_if_stale'));

            assertThrows(fn() => $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => 'yes']), InvalidArgumentException::class);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The budget is what makes Task 9's default-on refresh safe. A drift too
     * expensive to repair inline must still answer, from the graph it has, with
     * a warning the caller can act on. An error here would cost the agent the
     * very turn this feature exists to save.
     */
    #[Group('mcp')]
    public function testAnOverBudgetDriftIsDeclinedAndTheStaleGraphStillAnswers(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, "\n// drift\n", FILE_APPEND);
            touch($file, filemtime($file) + 60);

            // Make the recorded scan look expensive rather than lowering the
            // budget to zero: a scan too fast to time costs zero per file, and
            // zero is never over any budget, so a zero budget would not reach
            // the branch under test at all. The 'mixed' fixture scans 3 files,
            // so a 600-second span costs 200,000 ms/file for a 1-file drift,
            // far over the 5000 ms default budget.
            $projectStatement = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :id');
            $projectStatement->execute(['id' => $projectId]);
            $scanId = (string) $projectStatement->fetchColumn();

            $finishedStatement = $pdo->prepare('SELECT finished_at FROM scans WHERE id = :id');
            $finishedStatement->execute(['id' => $scanId]);
            $started = gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $finishedStatement->fetchColumn()) - 600);
            $pdo->prepare('UPDATE scans SET started_at = :started WHERE id = :id')->execute([
                'started' => $started,
                'id' => $scanId,
            ]);
            $before = (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();

            $result = $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);

            assertSame($before, (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn(), 'An over-budget refresh must not scan.');
            assertSame($projectId, $result->projectId);
            assertSame('stale', $result->staleness['state']);
            assertSame(true, str_contains(implode(' ', $result->warnings), 'over the 5000 ms budget'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A stale verdict with no measured change set cannot be costed, so it must decline rather than guess. */
    #[Group('mcp')]
    public function testAStaleGraphWithNoMeasuredDriftDeclines(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            // A newer failed attempt makes the probe report 'stale' without any
            // drift counts, which is the branch under test.
            // started_at must match the schema's own timestamp format (see
            // SqliteValues::now()): hasNewerAttempt() orders by started_at as a
            // string, and a mismatched format (e.g. a space instead of 'T')
            // sorts wrong regardless of which instant is actually later.
            $pdo->prepare(
                'INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at) ' .
                "VALUES ('later-attempt', :project, 'incremental', 'failed', 'x', :started)",
            )->execute(['project' => $projectId, 'started' => gmdate('Y-m-d\TH:i:s\Z', time() + 60)]);
            $before = (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();

            $result = $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);

            assertSame($before, (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn());
            assertSame(true, str_contains(implode(' ', $result->warnings), 'change set is unknown'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The default is the feature: an agent that must ask for a fresh graph pays two round trips discovering it needed one. */
    #[Group('mcp')]
    public function testRefreshHappensWithoutBeingAsked(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, "\n// drift\n", FILE_APPEND);
            touch($file, filemtime($file) + 60);

            $result = $tools->call('architecture_summary', ['project_id' => $projectId]);

            assertSame('fresh', $result->staleness['state'], 'No refresh_if_stale argument was passed, and the graph is fresh anyway.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A default is not a mandate. An explicit false must be obeyed, or callers lose the ability to read the stored graph as stored. */
    #[Group('mcp')]
    public function testAnExplicitFalseStillSuppressesTheRefresh(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, "\n// drift\n", FILE_APPEND);
            touch($file, filemtime($file) + 60);
            $before = (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();

            $result = $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => false]);

            assertSame($before, (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn());
            assertSame('stale', $result->staleness['state']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The kill switch restores the previous behaviour wholesale, for anyone who wants the stored graph and nothing else. */
    #[Group('mcp')]
    public function testTheKillSwitchRestoresTheOldDefault(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        putenv('KNOSSOS_AUTO_REFRESH=0');
        try {
            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, "\n// drift\n", FILE_APPEND);
            touch($file, filemtime($file) + 60);
            $before = (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();

            $result = $tools->call('architecture_summary', ['project_id' => $projectId]);

            assertSame($before, (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn());
            assertSame('stale', $result->staleness['state']);
        } finally {
            putenv('KNOSSOS_AUTO_REFRESH');
            $this->removeTempTree($root);
        }
    }
}
