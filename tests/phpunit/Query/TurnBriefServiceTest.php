<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\TurnBriefService;
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
            assertSame(realpath($root), $brief['project_root']);
            assertNotNull($brief['project_id']);
            assertNotNull($brief['snapshot_id']);
            assertIsInt($brief['scanned_at']);
            assertGreaterThanOrEqual(0, $brief['scan_ms']);
            assertLessThan(60_000, $brief['scan_ms']);
            assertSame(null, $brief['reason']);
            assertSame(['status' => 'not_evaluated', 'total' => 0, 'violations' => []], $brief['policy']);
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
            assertSame($root, $brief['path']);
            assertSame(null, $brief['project_id']);
            assertSame([], $brief['changed_files']);
            assertSame(['status' => 'not_evaluated', 'total' => 0, 'violations' => []], $brief['policy']);
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
            // A real installation root is required to find the workers; a bogus one makes the scan throw.
            $brief = (new TurnBriefService($pdo, ':memory:', '/nonexistent-installation'))->brief($root);
            if ($brief['status'] === 'ok') {
                $this->markTestSkipped('The scan does not depend on the installation root here.');
            }
            assertSame('scan-failed', $brief['status']);
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
            $policies = [['id' => 'no-calls', 'from_boundary' => 'Edge', 'deny_targets' => ['Core']]];
            $this->touch($root, self::TARGET);
            $service = $this->service($pdo);
            $off = $service->brief($root, [], $policies, false);
            assertSame('disabled', $off['policy']['status']);
            assertSame(0, $off['policy']['total']);
            assertSame([], $off['policy']['violations']);
            assertSame([self::TARGET], $off['changed_files']);

            $this->touch($root, self::TARGET);
            $on = $service->brief($root, [], $policies, true);
            assertSame('evaluated', $on['policy']['status']);
            // Caller::run reaches both the class and its method, so two edges break the policy.
            assertSame(2, $on['policy']['total']);
            assertCount(2, $on['policy']['violations']);
            $targets = [];
            foreach ($on['policy']['violations'] as $violation) {
                assertSame(['policy_id', 'source', 'target', 'source_boundaries', 'target_boundaries'], array_keys($violation));
                assertSame('no-calls', $violation['policy_id']);
                assertSame('App\\Caller::run', $violation['source']);
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

    /** The total counts every violation touching the change while the list stays short. */
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
            $service = $this->service($pdo);
            $service->brief($root);
            $this->touch($root, self::TARGET);
            $policies = [['id' => 'no-calls', 'from_boundary' => 'Edge', 'deny_targets' => ['Core']]];
            $brief = $service->brief($root, [], $policies);
            // Seven callers, each reaching the class and its method.
            assertSame(14, $brief['policy']['total']);
            assertCount(10, $brief['policy']['violations']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
