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
use Knossos\Query\Drift\DriftCounts;
use Knossos\Query\Drift\DriftOracle;
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
     * Drift detection is the one cost this feature was careful to bound, and
     * the bound was paid twice: once before dispatch to decide whether to
     * repair the graph, once after to annotate the answer. That is six git
     * subprocesses for a git project, or two complete hash walks for a gitless
     * one, for a single question with one answer.
     *
     * The memo is per call and nothing more: a second call probes again, or a
     * graph rebuilt between two calls would be reported with the verdict from
     * before it was rebuilt.
     */
    #[Group('mcp')]
    public function testTheDriftOracleRunsOncePerToolCall(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $oracle = $this->countingOracle(new WalkDriftOracle($pdo));
            $tools = new ToolService(
                new ProjectScanService($pdo, self::repositoryRoot(), [$root]),
                new ArchitectureQueryService($pdo, driftOracle: $oracle),
                new DatabaseMaintenanceService($pdo, ':memory:'),
                new ResultEnricher(new StalenessProbe($pdo, oracle: $oracle), new NextStepPlanner()),
            );

            $tools->call('architecture_summary', ['project_id' => $projectId]);
            assertSame(1, $oracle->calls, 'One tool call asks one question, so it probes once.');

            $tools->call('architecture_summary', ['project_id' => $projectId]);
            assertSame(2, $oracle->calls, 'The next call probes again; staleness is not cached across calls.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The memo belongs to the read tools, and only to them.
     *
     * `refresh_if_stale` is honoured for every tool the catalog declares it on,
     * but the probe that decides it runs for the others too, and a tool that
     * mutates the graph invalidates the verdict by doing its own work.
     * `remove_project` is the sharpest case: it deletes the project and returns
     * an envelope still naming it, so a reused snapshot described the staleness
     * of a graph that no longer exists.
     */
    #[Group('mcp')]
    public function testRemovingAProjectReportsNoStalenessForIt(): void
    {
        [$tools, $projectId, $root] = $this->buildToolServiceWithScan('mixed');
        try {
            $result = $tools->call('remove_project', ['project_id' => $projectId, 'execute' => true]);

            assertSame('missing', $result->staleness['state'], 'A deleted project has no graph, so it cannot be reported fresh.');
            assertSame(null, $result->staleness['scanned_at'], 'Nothing about the removed graph may survive into the answer.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The gate belongs to the tool's own schema, not to an exclude-list naming
     * scan_project. `remove_project` does not declare refresh_if_stale, so a
     * stale graph must reach it untouched: scanning the project immediately
     * before deleting it would be wasted work at best, and at worst races the
     * deletion that follows.
     */
    #[Group('mcp')]
    public function testAGraphMutatingToolDoesNotTriggerAScan(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, "\n// drift\n", FILE_APPEND);
            touch($file, filemtime($file) + 60);
            assertSame('stale', (new StalenessProbe($pdo))->probe($projectId)['state']);
            $before = (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();

            // Preview mode (no execute), deliberately: remove_project's real
            // deletion cascades onto the scans table, which would make an
            // unwanted extra scan indistinguishable from none at all once the
            // project itself is gone. What is under test is the gate in
            // ToolService, which runs identically whichever branch of
            // remove_project follows.
            $tools->call('remove_project', ['project_id' => $projectId]);

            assertSame($before, (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn(), 'remove_project must not scan a stale project on its way to previewing its removal.');
        } finally {
            if (is_dir($root)) {
                $this->removeTempTree($root);
            }
        }
    }

    /** An oracle that counts how often it was consulted and otherwise answers exactly as the one it wraps. */
    private function countingOracle(DriftOracle $inner)
    {
        return new class ($inner) implements DriftOracle {
            public int $calls = 0;

            public function __construct(private readonly DriftOracle $inner) {}

            /** Counts the probe, then defers to the wrapped oracle. */
            public function drift(string $projectId, string $activeScanId, string $root, ?string $finishedAt): ?DriftCounts
            {
                ++$this->calls;

                return $this->inner->drift($projectId, $activeScanId, $root, $finishedAt);
            }
        };
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
            // so a recorded 600-second scan costs 200,000 ms/file for a 1-file
            // drift, far over the 5000 ms default budget.
            $projectStatement = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :id');
            $projectStatement->execute(['id' => $projectId]);
            $scanId = (string) $projectStatement->fetchColumn();

            $pdo->prepare('UPDATE scans SET duration_ms = :duration WHERE id = :id')->execute([
                'duration' => 600_000,
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
            // A newer failed attempt makes the probe report 'stale' while the
            // drift counts all read zero: the verdict comes from the attempt,
            // not from anything measured on disk, so there is no change set to
            // cost a rescan against. That is the branch under test.
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

    /**
     * The other half of the $drifted < 1 guard: changed_files_since absent
     * entirely because drift could not be measured at all, rather than present
     * and summing to zero. Both sub-cases must decline identically, but only
     * one of them was covered before this test existed.
     */
    #[Group('mcp')]
    public function testAStaleGraphWithUnmeasurableDriftDeclines(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        try {
            // Removing the root makes WalkDriftOracle decline (is_dir() reads
            // false), and this fixture never records a git head, so
            // GitDriftOracle already declines on its own. Neither oracle
            // answers, so changed_files_since is absent from the verdict
            // rather than present and zero.
            $this->removeTempTree($root);
            $pdo->prepare(
                'INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at) ' .
                "VALUES ('later-attempt', :project, 'incremental', 'failed', 'x', :started)",
            )->execute(['project' => $projectId, 'started' => gmdate('Y-m-d\TH:i:s\Z', time() + 60)]);
            $probe = (new StalenessProbe($pdo))->probe($projectId);
            assertSame('stale', $probe['state']);
            assertSame(false, array_key_exists('changed_files_since', $probe), 'Drift must be genuinely unmeasured for this test to exercise the intended branch.');
            $before = (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();

            $result = $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);

            assertSame($before, (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn(), 'An unmeasurable change set must not scan.');
            assertSame(true, str_contains(implode(' ', $result->warnings), 'change set is unknown'));
        } finally {
            if (is_dir($root)) {
                $this->removeTempTree($root);
            }
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
        // Captured rather than assumed absent: unsetting unconditionally in the
        // finally below would silently change global state for every later
        // test in this process if the variable was already set when it started.
        $previous = getenv('KNOSSOS_AUTO_REFRESH');
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
            if ($previous === false) {
                putenv('KNOSSOS_AUTO_REFRESH');
            } else {
                putenv('KNOSSOS_AUTO_REFRESH=' . $previous);
            }
            $this->removeTempTree($root);
        }
    }

    /**
     * The kill switch turns the default off; it does not make the server
     * read-only. An explicit `refresh_if_stale: true` still rescans with it
     * set, which is the documented precedence and the reason the reference
     * cannot offer the variable alone as a way to get a genuinely read-only
     * call.
     *
     * Pinned because the claim is the kind that reads as obviously true and
     * is not: an operator who set the variable to keep a server from writing
     * would find a caller writing anyway.
     */
    #[Group('mcp')]
    public function testAnExplicitRequestStillRefreshesWithTheKillSwitchSet(): void
    {
        [$tools, $projectId, $root, $pdo] = $this->buildToolServiceWithScan('mixed');
        // Captured rather than assumed absent, for the reason the neighbouring
        // kill-switch test gives.
        $previous = getenv('KNOSSOS_AUTO_REFRESH');
        putenv('KNOSSOS_AUTO_REFRESH=0');
        try {
            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, "\n// drift\n", FILE_APPEND);
            touch($file, filemtime($file) + 60);
            $before = (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();

            $result = $tools->call('architecture_summary', ['project_id' => $projectId, 'refresh_if_stale' => true]);

            assertSame('fresh', $result->staleness['state'], 'The caller asked for a refresh and got one, kill switch or no kill switch.');
            assertSame($before + 1, (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn(), 'A rescan really ran, so the variable alone cannot be offered as a read-only guarantee.');
        } finally {
            if ($previous === false) {
                putenv('KNOSSOS_AUTO_REFRESH');
            } else {
                putenv('KNOSSOS_AUTO_REFRESH=' . $previous);
            }
            $this->removeTempTree($root);
        }
    }
}
