<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Discovery\AllowedRoots;
use Knossos\Query\LedgeredScanner;
use Knossos\Query\ScanLedger;
use Knossos\Query\TurnBriefService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertLessThan;
use function PHPUnit\Framework\assertIsInt;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

/**
 * What one turn did to the graph: changed, added and deleted files with their
 * fan-in, affected tests and policy violations, scanning only allowed roots.
 */
final class TurnBriefServiceTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    /** Called by Caller.php, so editing it must report a dependent. */
    private const TARGET = 'src/Core/Greeter.php';

    /** Calls the target across a boundary, so the policy below already fails on it. */
    private const CALLER = 'src/Edge/Caller.php';

    private const POLICIES = [['id' => 'no-calls', 'from_boundary' => 'Edge', 'deny_targets' => ['Core']]];

    private string|false $allowedRoots = false;

    private string|false $rootsFile = false;

    /** The temp directory is allowed so the fixture copies are scannable; restored in tearDown. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->allowedRoots = getenv('KNOSSOS_ALLOWED_ROOTS');
        $this->rootsFile = getenv('KNOSSOS_ROOTS_FILE');
        putenv('KNOSSOS_ALLOWED_ROOTS=' . sys_get_temp_dir());
        putenv('KNOSSOS_ROOTS_FILE');
    }

    protected function tearDown(): void
    {
        putenv(is_string($this->allowedRoots) ? 'KNOSSOS_ALLOWED_ROOTS=' . $this->allowedRoots : 'KNOSSOS_ALLOWED_ROOTS');
        putenv(is_string($this->rootsFile) ? 'KNOSSOS_ROOTS_FILE=' . $this->rootsFile : 'KNOSSOS_ROOTS_FILE');
        parent::tearDown();
    }

    private function service(PDO $pdo, string $databasePath = ':memory:'): TurnBriefService
    {
        return new TurnBriefService($pdo, $databasePath, self::repositoryRoot());
    }

    private function touch(string $root, string $relative): void
    {
        $file = $root . '/' . $relative;
        file_put_contents($file, file_get_contents($file) . "\n// touched\n");
    }

    /** Adds a method `$name` to the caller that crosses the boundary again. */
    private function addCall(string $root, string $name): void
    {
        $file = $root . '/' . self::CALLER;
        $method = sprintf("    public function %s(): string\n    {\n        return (new \\App\\Greeter())->greet('again');\n    }\n}\n", $name);
        file_put_contents($file, (string) preg_replace('/}\s*$/', '', (string) file_get_contents($file)) . "\n" . $method);
    }

    private function rows(PDO $pdo, string $table): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    #[Group('query')]
    public function testAnEditedFileIsReportedWithItsImpact(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->touch($root, self::TARGET);
            $brief = $this->service($pdo)->brief($root);
            assertSame('ok', $brief['status']);
            assertSame([self::TARGET], $brief['changed_files']);
            assertSame([], $brief['added_files']);
            assertSame([], $brief['deleted_files']);
            assertGreaterThan(0, $brief['impact'][self::TARGET]['dependent_files']);
            assertSame(self::TARGET, $brief['impact'][self::TARGET]['path']);
            // The file's own boundary, not the boundary of the caller that depends on it.
            assertSame('Core', $brief['impact'][self::TARGET]['boundary']);
            assertSame(true, in_array('Edge', $brief['impact'][self::TARGET]['boundaries'], true));
            assertSame(realpath($root), $brief['project_root']);
            assertNotNull($brief['project_id']);
            assertNotNull($brief['snapshot_id']);
            assertIsInt($brief['scanned_at']);
            assertGreaterThanOrEqual(0, $brief['scan_ms']);
            assertLessThan(60_000, $brief['scan_ms']);
            assertSame(null, $brief['reason']);
            assertSame(['status' => 'not_evaluated', 'total' => 0, 'violations' => [], 'truncated' => false], $brief['policy']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testANoChangeTurnReportsNothingChanged(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $service = $this->service($pdo);
            $service->brief($root);
            $brief = $service->brief($root);
            assertSame('ok', $brief['status']);
            assertSame([], $brief['changed_files']);
            assertSame([], $brief['added_files']);
            assertSame([], $brief['deleted_files']);
            assertSame([], $brief['impact']);
            assertSame([], $brief['tests']);
            assertSame('not_evaluated', $brief['policy']['status']);
            assertSame(0, $brief['policy']['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testADeletedFileIsListedEvenThoughItHasNoNodesLeft(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            unlink($root . '/' . self::TARGET);
            $brief = $this->service($pdo)->brief($root);
            assertSame('ok', $brief['status']);
            assertSame([self::TARGET], $brief['deleted_files']);
            assertSame([], $brief['changed_files']);
            assertSame([], $brief['added_files']);
            assertSame(false, array_key_exists(self::TARGET, $brief['impact']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAnAddedFileIsListed(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            file_put_contents($root . '/new.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class Fresh {}\n");
            $brief = $this->service($pdo)->brief($root);
            assertSame('ok', $brief['status']);
            assertSame(['new.php'], $brief['added_files']);
            assertSame([], $brief['changed_files']);
            assertSame([], $brief['deleted_files']);
            assertSame(true, array_key_exists('new.php', $brief['impact']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAReportedDotDirectoryPathKeepsItsDot(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            mkdir($root . '/.hidden');
            file_put_contents($root . '/.hidden/x.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class Hidden {}\n");
            $service = $this->service($pdo);
            $first = $service->brief($root);
            assertSame(['.hidden/x.php'], $first['added_files']);
            $this->touch($root, '.hidden/x.php');
            $brief = $service->brief($root, ['./.hidden/x.php']);
            assertSame('ok', $brief['status']);
            assertSame(['.hidden/x.php'], $brief['changed_files']);
            assertSame([], $brief['added_files']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAnAbsoluteReportedPathIsMadeRelativeToTheProject(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            // Edited and rescanned once, so the diff alone finds nothing new for it.
            $this->touch($root, self::TARGET);
            $service = $this->service($pdo);
            $service->brief($root);
            $brief = $service->brief($root, [(string) realpath($root) . '/' . self::TARGET]);
            assertSame([self::TARGET], $brief['changed_files']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAPathOutsideTheAllowedRootsIsRefusedWithoutScanning(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        $data = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($data);
        try {
            putenv('KNOSSOS_ALLOWED_ROOTS=/nonexistent-root');
            $scans = $this->rows($pdo, 'scans');
            $databasePath = $data . '/knossos.sqlite';
            $brief = $this->service($pdo, $databasePath)->brief($root);
            assertSame('not-allowed', $brief['status']);
            assertSame($data . '/roots.json', $brief['roots_file']);
            assertSame(realpath($root), $brief['path']);
            assertSame(realpath($root), $brief['refused_root']);
            assertSame(null, $brief['project_id']);
            assertSame([], $brief['changed_files']);
            assertSame(['status' => 'not_evaluated', 'total' => 0, 'violations' => [], 'truncated' => false], $brief['policy']);
            assertSame($scans, $this->rows($pdo, 'scans'));
        } finally {
            $this->removeTempTree($root);
            $this->removeTempTree($data);
        }
    }

    /** The resolver walks up to the owning project; that project's root has to be allowed too. */
    #[Group('query')]
    public function testAnAncestorProjectRootOutsideTheAllowedRootsIsRefused(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        $data = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($data);
        try {
            $real = (string) realpath($root);
            putenv('KNOSSOS_ALLOWED_ROOTS=' . $real . '/src');
            $scans = $this->rows($pdo, 'scans');
            $brief = $this->service($pdo, $data . '/knossos.sqlite')->brief($real . '/src/Core');
            assertSame('not-allowed', $brief['status']);
            assertSame($data . '/roots.json', $brief['roots_file']);
            // The path itself is allowed; the ancestor root that would be scanned is what to allow.
            assertSame($real, $brief['refused_root']);
            assertSame(null, $brief['project_id']);
            assertSame($scans, $this->rows($pdo, 'scans'));
        } finally {
            $this->removeTempTree($root);
            $this->removeTempTree($data);
        }
    }

    #[Group('query')]
    public function testAMissingPathIsReported(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $missing = $root . '/nope/deeper';
            $brief = $this->service($pdo, $root . '/knossos.sqlite')->brief($missing);
            assertSame('missing', $brief['status']);
            assertSame($missing, $brief['path']);
            assertSame(null, $brief['project_id']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAnUnscannedDirectoryIsReportedAndNotScanned(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        $other = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($other);
        try {
            $projects = $this->rows($pdo, 'projects');
            $brief = $this->service($pdo)->brief($other);
            assertSame('unscanned', $brief['status']);
            assertSame(realpath($other), $brief['path']);
            assertSame(null, $brief['project_id']);
            assertSame($projects, $this->rows($pdo, 'projects'));
        } finally {
            $this->removeTempTree($root);
            $this->removeTempTree($other);
        }
    }

    #[Group('query')]
    public function testAFailedScanIsReportedWithItsReason(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            // A project configuration that does not parse stops every scan before it writes.
            file_put_contents($root . '/knossos.json', '{');
            $scans = $this->rows($pdo, 'scans');
            $brief = $this->service($pdo)->brief($root);
            assertSame('scan-failed', $brief['status']);
            assertSame($scans, $this->rows($pdo, 'scans'));
            assertSame(realpath($root), $brief['project_root']);
            assertSame(true, is_string($brief['reason']) && $brief['reason'] !== '');
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testPoliciesAreEvaluatedOnlyWhenEnforced(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->addCall($root, 'again');
            $service = $this->service($pdo);
            $off = $service->brief($root, [self::CALLER], self::POLICIES, false);
            assertSame(['status' => 'disabled', 'total' => 0, 'violations' => [], 'truncated' => false], $off['policy']);
            assertSame([self::CALLER], $off['changed_files']);

            $this->addCall($root, 'more');
            $on = $service->brief($root, [self::CALLER], self::POLICIES, true);
            assertSame('evaluated', $on['policy']['status']);
            assertSame(false, $on['policy']['truncated']);
            // Only the new method's two edges (the class and its method) are new.
            assertSame(2, $on['policy']['total']);
            $targets = [];
            foreach ($on['policy']['violations'] as $violation) {
                assertSame(['policy_id', 'source', 'target', 'source_boundaries', 'target_boundaries'], array_keys($violation));
                assertSame('no-calls', $violation['policy_id']);
                assertSame('App\\Caller::more', $violation['source']);
                assertSame(true, in_array('Edge', array_column($violation['source_boundaries'], 'name'), true));
                assertSame(true, in_array('Core', array_column($violation['target_boundaries'], 'name'), true));
                $targets[] = $violation['target'];
            }
            sort($targets);
            assertSame(['App\\Greeter', 'App\\Greeter::greet'], $targets);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A violation that was there before the turn is not the turn's, even in a file the turn edited. */
    #[Group('query')]
    public function testAnExistingViolationIsNotReportedAsIntroduced(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $service = $this->service($pdo);
            // The edited file's dependent already breaks the policy.
            $this->touch($root, self::TARGET);
            $impacted = $service->brief($root, [self::TARGET], self::POLICIES);
            assertSame([self::TARGET], $impacted['changed_files']);
            assertSame(['status' => 'evaluated', 'total' => 0, 'violations' => [], 'truncated' => false], $impacted['policy']);
            // The violating file itself, edited without adding a call.
            $this->touch($root, self::CALLER);
            $edited = $service->brief($root, [self::CALLER], self::POLICIES);
            assertSame([self::CALLER], $edited['changed_files']);
            assertSame(['status' => 'evaluated', 'total' => 0, 'violations' => [], 'truncated' => false], $edited['policy']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A file changed by other means (a checkout, a formatter) never counts against the turn. */
    #[Group('query')]
    public function testOnlyFilesTheTurnEditedCountForPolicy(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $service = $this->service($pdo);
            // Changed on disk but not reported (as a formatter would): its existing violations are not introduced.
            $file = $root . '/' . self::CALLER;
            file_put_contents($file, str_replace('final class Caller', "// reformatted\nfinal class Caller", (string) file_get_contents($file)));
            $other = $service->brief($root, [], self::POLICIES);
            assertSame([self::CALLER], $other['changed_files']);
            assertSame(0, $other['policy']['total']);
            // Even a new violating call stays out until the turn reports the file.
            $this->addCall($root, 'quiet');
            assertSame(0, $service->brief($root, [], self::POLICIES)['policy']['total']);
            // Reported with a newly added call: only that call is new.
            $this->addCall($root, 'loud');
            $edited = $service->brief($root, [self::CALLER], self::POLICIES);
            assertSame(2, $edited['policy']['total']);
            assertSame(['App\\Caller::loud'], array_values(array_unique(array_column($edited['policy']['violations'], 'source'))));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Past the check's own result cap the figures are bounds, and the brief says so. */
    #[Group('query')]
    public function testATruncatedPolicyCheckIsFlagged(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $methods = '';
            foreach (range(1, 60) as $n) {
                $methods .= sprintf("    public function run%d(): string\n    {\n        return (new \\App\\Greeter())->greet('x');\n    }\n\n", $n);
            }
            file_put_contents($root . '/src/Edge/Many.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Many\n{\n" . $methods . "}\n");
            $brief = $this->service($pdo)->brief($root, ['src/Edge/Many.php'], self::POLICIES);
            assertSame(['src/Edge/Many.php'], $brief['added_files']);
            assertSame('evaluated', $brief['policy']['status']);
            assertSame(true, $brief['policy']['truncated']);
            assertGreaterThan(0, $brief['policy']['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTestsAreListedWithADistance(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->touch($root, self::TARGET);
            $brief = $this->service($pdo)->brief($root);
            assertCount(1, $brief['tests']);
            assertSame(['path', 'distance'], array_keys($brief['tests'][0]));
            assertSame('tests/GreeterTest.php', $brief['tests'][0]['path']);
            assertGreaterThan(0, $brief['tests'][0]['distance']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Whatever form the caller reports a path in, it lands as the project-relative path, and only if tracked. */
    #[Group('query')]
    public function testReportedPathsAreNormalisedAndOnlyTrackedOnesCount(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $absolute = (string) realpath($root) . '/' . self::TARGET;
            $service = $this->service($pdo);
            foreach (['./' . self::TARGET, $absolute, self::TARGET] as $reported) {
                $brief = $service->brief($root, [$reported, './untracked.php']);
                assertSame([self::TARGET], $brief['changed_files'], $reported);
                assertSame([], $brief['added_files']);
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Added and deleted lists come out sorted whatever order the scan stored them in. */
    #[Group('query')]
    public function testListsAreSorted(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $body = "<?php\n\ndeclare(strict_types=1);\n\nfinal class %s {}\n";
            foreach (['zz', 'mm', 'aa'] as $name) {
                file_put_contents($root . '/' . $name . '.php', sprintf($body, ucfirst($name)));
            }
            $service = $this->service($pdo);
            assertSame(['aa.php', 'mm.php', 'zz.php'], $service->brief($root)['added_files']);
            $this->touch($root, 'zz.php');
            $this->touch($root, 'aa.php');
            $this->touch($root, self::TARGET);
            assertSame(['aa.php', self::TARGET, 'zz.php'], $service->brief($root)['changed_files']);
            foreach (['zz', 'mm', 'aa'] as $name) {
                unlink($root . '/' . $name . '.php');
            }
            assertSame(['aa.php', 'mm.php', 'zz.php'], $service->brief($root)['deleted_files']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The check is scoped to the edited files' edges: edges elsewhere in the
     * graph, however many, cannot exhaust its budget and flag a clean edit as
     * truncated, nor push the edited file's own violations past the bound.
     */
    #[Group('query')]
    public function testEdgesOutsideTheEditedFilesDoNotTruncateThePolicyCheck(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $methods = '';
            foreach (range(1, 30) as $n) {
                $methods .= sprintf("    public function run%d(): string\n    {\n        return (new \\App\\Greeter())->greet('x');\n    }\n\n", $n);
            }
            // Sorts before the edited caller, so a project-wide walk spends its budget here first.
            file_put_contents($root . '/src/Edge/Aaa.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Aaa\n{\n" . $methods . "}\n");
            $service = new TurnBriefService($pdo, ':memory:', self::repositoryRoot(), 1000, 20);
            $service->brief($root, [], self::POLICIES);

            $this->addCall($root, 'scoped');
            $brief = $service->brief($root, [self::CALLER], self::POLICIES);
            assertSame('evaluated', $brief['policy']['status']);
            assertSame(false, $brief['policy']['truncated']);
            assertSame(2, $brief['policy']['total']);
            assertSame(['App\\Caller::scoped'], array_values(array_unique(array_column($brief['policy']['violations'], 'source'))));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The total counts every introduced violation while the list stays short. */
    #[Group('query')]
    public function testViolationsAreCappedButTheTotalIsExact(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            foreach (range(1, 6) as $n) {
                file_put_contents($root . '/src/Edge/Extra' . $n . '.php', sprintf(
                    "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Extra%d\n{\n    public function run(): string\n    {\n        return (new \\App\\Greeter())->greet('x');\n    }\n}\n",
                    $n,
                ));
            }
            $reported = array_map(static fn(int $n): string => 'src/Edge/Extra' . $n . '.php', range(1, 6));
            $brief = $this->service($pdo)->brief($root, $reported, self::POLICIES);
            // Six new callers, each reaching the class and its method; the old caller's are not new.
            assertSame(12, $brief['policy']['total']);
            assertCount(10, $brief['policy']['violations']);
            assertSame(false, $brief['policy']['truncated']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A checkout reached through a linked directory: the reported path still lands in the project. */
    #[Group('query')]
    public function testAPathReportedThroughASymlinkIsMadeRelative(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        $links = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($links);
        try {
            symlink((string) realpath($root), $links . '/checkout');
            // Unchanged on disk, so only the reported path can put it in the list.
            $brief = $this->service($pdo)->brief($root, [$links . '/checkout/' . self::TARGET]);
            assertSame([self::TARGET], $brief['changed_files']);
        } finally {
            $this->removeTempTree($links);
            $this->removeTempTree($root);
        }
    }

    /** The live watcher's scanner: records each scan, with the policies' baseline, in the ledger. */
    private function watcherScan(PDO $pdo, string $root): void
    {
        $roots = AllowedRoots::of([(string) realpath($root)]);
        LedgeredScanner::local($pdo, self::repositoryRoot(), $roots, self::POLICIES)->scan($root, 'incremental');
    }

    /** Another writer scanned the turn's edit first: the ledger still makes it, and its violations, the turn's. */
    #[Group('query')]
    public function testATurnScannedByAnotherWriterIsStillReportedWithItsViolations(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $since = (new ScanLedger($pdo))->activeSnapshot($projectId);
            $this->addCall($root, 'again');
            $this->watcherScan($pdo, $root);
            $brief = $this->service($pdo)->brief($root, [self::CALLER], self::POLICIES, true, $since, true);
            assertSame(false, $brief['scanned']);
            assertSame([self::CALLER], $brief['changed_files']);
            assertSame('evaluated', $brief['policy']['status']);
            assertSame(2, $brief['policy']['total']);
            // Without the snapshot the turn began at, the graph already holds the edit: nothing reads as new.
            $blind = $this->service($pdo)->brief($root, [self::CALLER], self::POLICIES, true, null, true);
            assertSame([self::CALLER], $blind['changed_files']);
            assertSame(0, $blind['policy']['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A scan the ledger never recorded: the files are still the turn's, but the policy's baseline is gone. */
    #[Group('query')]
    public function testAnUnrecordedScanSinceTheTurnBeganLeavesThePolicyUnevaluated(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $since = (new ScanLedger($pdo))->activeSnapshot($projectId);
            $this->addCall($root, 'again');
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'incremental');
            $brief = $this->service($pdo)->brief($root, [self::CALLER], self::POLICIES, true, $since, true);
            assertSame([self::CALLER], $brief['changed_files']);
            assertSame('not_evaluated', $brief['policy']['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A file the turn added and one it deleted keep their kind when another writer scanned them first. */
    #[Group('query')]
    public function testAddedAndDeletedFilesScannedByAnotherWriterKeepTheirKind(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $since = (new ScanLedger($pdo))->activeSnapshot($projectId);
            file_put_contents($root . '/src/Core/Added.php', "<?php\nnamespace App;\nfinal class Added {}\n");
            unlink($root . '/' . self::TARGET);
            $this->watcherScan($pdo, $root);
            $brief = $this->service($pdo)->brief($root, ['src/Core/Added.php', self::TARGET], null, true, $since, true);
            assertSame(false, $brief['scanned']);
            assertSame(['src/Core/Added.php'], $brief['added_files']);
            assertSame([self::TARGET], $brief['deleted_files']);
            assertSame([], $brief['changed_files']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Asked to reuse a scan the graph does not have, the brief scans the edit itself. */
    #[Group('query')]
    public function testReuseScanStillScansAnEditTheGraphDoesNotHold(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $since = (new ScanLedger($pdo))->activeSnapshot($projectId);
            $this->addCall($root, 'again');
            $brief = $this->service($pdo)->brief($root, [self::CALLER], self::POLICIES, true, $since, true);
            assertSame(true, $brief['scanned']);
            assertSame(2, $brief['policy']['total']);
            // Its own scan is recorded: a second session that began at the same snapshot reads the same verdict.
            $again = $this->service($pdo)->brief($root, [self::CALLER], self::POLICIES, true, $since, true);
            assertSame(false, $again['scanned']);
            assertSame([self::CALLER], $again['changed_files']);
            assertSame(2, $again['policy']['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** An all-digit file name stays a string, though PHP turns it into an integer array key. */
    #[Group('query')]
    public function testAnAllDigitPathStaysAString(): void
    {
        $diff = new \ReflectionMethod(TurnBriefService::class, 'diff');
        [$changed, $added, $deleted] = $diff->invoke(null, ['123' => 'a', '7' => 'x'], ['123' => 'b', '45' => 'c'], ['123']);
        assertSame(['123'], $changed);
        assertSame(['45'], $added);
        assertSame(['7'], $deleted);
    }
}
