<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\GraphTopologyQueryService;
use Knossos\Query\SessionBriefService;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

final class SessionBriefServiceTest extends KnossosTestCase
{
    #[Group('query')]
    public function testTheBriefReportsTheRootsFileItActuallyConsulted(): void
    {
        // Not "the roots file", of which there may be several on one machine,
        // but the one this call read. It follows the same precedence
        // AllowedRoots uses, so the reported path is the path that decided the
        // verdict rather than a plausible guess about where roots live.
        $directory = sys_get_temp_dir() . '/knossos-brief-roots-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o755, true);
        $previous = getenv('KNOSSOS_ROOTS_FILE');
        putenv('KNOSSOS_ROOTS_FILE');
        try {
            $besideTheDatabase = SessionBriefService::unscanned('/root/Elsewhere', $directory . '/knossos.sqlite');
            assertSame($directory . '/roots.json', $besideTheDatabase->rootsFile);

            putenv('KNOSSOS_ROOTS_FILE=' . $directory . '/named.json');
            $named = SessionBriefService::unscanned('/root/Elsewhere', $directory . '/knossos.sqlite');
            assertSame($directory . '/named.json', $named->rootsFile);
        } finally {
            putenv(is_string($previous) ? 'KNOSSOS_ROOTS_FILE=' . $previous : 'KNOSSOS_ROOTS_FILE');
            exec('rm -rf ' . escapeshellarg($directory));
        }
    }

    #[Group('query')]
    public function testNoRootsFileIsReportedWhenThereIsNoDatabaseToFindOneBeside(): void
    {
        // ':memory:' has no directory to look beside, so there is no file the
        // verdict could honestly name and it must not invent one.
        $brief = SessionBriefService::unscanned('/root/Elsewhere', ':memory:');

        assertSame(null, $brief->rootsFile);
    }

    #[Group('query')]
    public function testUnscannedPathYieldsTheShortestBrief(): void
    {
        [$pdo] = $this->storeFixture();

        $text = (new SessionBriefService($pdo))->brief(sys_get_temp_dir());

        assertSame(true, str_starts_with($text, 'NOT SCANNED.'));
        assertSame(true, str_contains($text, 'scan_project path='));
    }

    #[Group('query')]
    public function testUnscannedRelativePathIsResolvedToAbsoluteInTheVerdict(): void
    {
        // Every other unscanned-path test above passes an already-absolute
        // path, which is exactly why none of them would catch a relative one
        // leaking into the verdict. `scan_project path=.` is not a command an
        // MCP server can run: it has its own working directory and allowed
        // roots, so the argument handed back must be absolute.
        //
        // The working directory is moved to a fixture the test created rather
        // than assumed to be the repository root. Comparing against
        // repositoryRoot() made this pass only when phpunit was launched from
        // there; nothing in CI does otherwise today, so the failure would have
        // been a puzzling red on someone's laptop rather than a caught bug.
        // The expectation is now the directory this test chose, so what is
        // asserted is unchanged and where it is asserted from no longer
        // matters.
        [$pdo] = $this->storeFixture();
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root, 0o777, true);
        $absolute = (string) realpath($root);
        $previousDirectory = (string) getcwd();

        try {
            chdir($root);
            $text = (new SessionBriefService($pdo))->brief('.');
        } finally {
            // Restored before any assertion runs, so a failure here cannot
            // leave every later test in this process running from a directory
            // that is about to be deleted.
            chdir($previousDirectory);
        }

        try {
            assertSame(true, str_contains($text, $absolute));
            assertSame(false, str_contains($text, 'path=.'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testScannedProjectCarriesItsIdAndAnyNotes(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $root = (string) $pdo->query('SELECT root_realpath FROM projects')->fetchColumn();
        $insert = $pdo->prepare(
            'INSERT INTO annotations(project_id, canonical_name, kind, value, created_at, updated_at) ' .
            "VALUES(:p, 'App\\\\Checkout', 'note', 'the seam is the repository, not the controller', :t, :t)",
        );
        $insert->execute(['p' => $ids['project'], 't' => '2026-09-09T12:00:00+00:00']);

        $text = (new SessionBriefService($pdo))->brief($root);

        assertSame(true, str_contains($text, $ids['project']));
        assertSame(true, str_contains($text, 'the seam is the repository'));
        assertSame(true, str_contains($text, '`knossos` skill'));
    }

    #[Group('query')]
    public function testNotesAreCappedNewestFirstAndOnlyOfKindNote(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $root = (string) $pdo->query('SELECT root_realpath FROM projects')->fetchColumn();
        $insert = $pdo->prepare(
            'INSERT INTO annotations(project_id, canonical_name, kind, value, created_at, updated_at) ' .
            'VALUES(:p, :n, :k, :v, :t, :t)',
        );
        // Seven notes, oldest first, so the cap has something to cut and the
        // ordering has a wrong answer available to it.
        for ($i = 1; $i <= 7; $i++) {
            $insert->execute([
                'p' => $ids['project'],
                'n' => 'App\\Note' . $i,
                'k' => 'note',
                'v' => 'note body ' . $i,
                't' => sprintf('2026-09-%02dT12:00:00+00:00', $i),
            ]);
        }
        // The other three kinds change how other read surfaces behave. They are
        // not orientation material and must not consume the notes budget.
        $insert->execute([
            'p' => $ids['project'],
            'n' => 'App\\Suppressed',
            'k' => 'false_positive',
            'v' => 'not dead, reached through the container',
            't' => '2026-09-30T12:00:00+00:00',
        ]);

        $text = (new SessionBriefService($pdo))->brief($root);

        assertSame(true, str_contains($text, 'note body 7'));   // newest kept
        assertSame(true, str_contains($text, 'note body 3'));   // fifth-newest kept
        assertSame(false, str_contains($text, 'note body 2'));  // beyond the cap of 5
        assertSame(false, str_contains($text, 'note body 1'));
        assertSame(false, str_contains($text, 'reached through the container')); // wrong kind
    }

    #[Group('query')]
    public function testFreshStateGathersEntryPointsAndHubs(): void
    {
        // storeFixture()'s root, /workspace/fixture-shop, does not exist on
        // disk, so WalkDriftOracle::drift() always bails out to null there
        // and 'fresh' can never actually be observed. A real scan
        // of a real temp checkout is needed so this positive case is genuine,
        // not assumed. This is the counterpart to the negative case below:
        // asserting an empty entryPoints/hubs on its own would also pass if
        // the seeding were broken and there were never anything to find.
        [$pdo, $projectId, $root] = $this->scanTempFixture('php-scanner');
        try {
            $this->seedRouteAndHub($pdo, $projectId);

            $brief = (new SessionBriefService($pdo))->gather($root);

            assertSame('fresh', $brief->state);
            assertSame(true, in_array('LoginRoute (route)', $brief->entryPoints, true));
            // Kind and degree travel with the name: a bare name gives an agent
            // no way to judge why the component is on the list. The degree is
            // the seeded 10 inbound `calls` edges, counted the way
            // architecture_health counts, so this also pins that the brief
            // ranks over impact relationships rather than over every edge.
            assertSame(true, in_array('PaymentGateway (class, degree 10)', $brief->hubs, true));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testGraphSectionsExcludeTestAndVendorComponents(): void
    {
        // Against this repository's own graph the unfiltered ranking led with
        // assertSame, InvalidArgumentException, count, StableId and sprintf: a
        // test helper, an SPL class and two PHP built-ins offered to an agent
        // as the components a change is most likely to reach. The seeds below
        // reproduce both shapes deliberately, each outranking the real hub, so
        // the filters are what keeps them out rather than an accident of the
        // fixture's own degrees. The command stub is the entry-point half of
        // the same problem: it carries the role without being a way in.
        [$pdo, $projectId, $root] = $this->scanTempFixture('php-scanner');
        try {
            $this->seedRouteAndHub($pdo, $projectId);
            $this->seedUnreportableNoise($pdo, $projectId);

            $brief = (new SessionBriefService($pdo))->gather($root);

            assertSame('fresh', $brief->state);
            assertSame(true, in_array('PaymentGateway (class, degree 10)', $brief->hubs, true));
            assertSame(true, in_array('LoginRoute (route)', $brief->entryPoints, true));
            // Both outrank PaymentGateway at 12 inbound edges apiece, so
            // either one appearing would mean the filter never ran.
            assertSame(false, in_array('VendorClient (external_class, degree 12)', $brief->hubs, true));
            assertSame(false, in_array('TestKitAssert (method, degree 12)', $brief->hubs, true));
            // 'command' sorts before 'route', so an unfiltered entry-point
            // query puts this stub ahead of the real route.
            assertSame(false, in_array('FakeCommandStub (command)', $brief->entryPoints, true));
            // The role a classifier gives a controller is the whole reason
            // this repository rendered no entry points at all: nothing here
            // carries the `route`, `command` or `endpoint` kind.
            assertSame(true, in_array('CheckoutController (class)', $brief->entryPoints, true));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testNonFreshStateKeepsConfigAndNotesButOmitsGraphSections(): void
    {
        // Rendered text cannot prove this: SessionBriefRenderer independently
        // withholds entry points and hubs for any non-fresh state, so a check
        // against `brief()`'s string masks whatever `gather()` actually did.
        // Asserting on the gathered SessionBrief directly is what makes the
        // service's own `$state === 'fresh'` gate (not the renderer's)
        // observable.
        [$pdo, $projectId, $root] = $this->scanTempFixture('php-scanner');
        try {
            $this->seedRouteAndHub($pdo, $projectId);

            // Force a non-fresh verdict without touching the filesystem: a
            // later scan attempt that never completed is exactly what
            // StalenessProbe::hasNewerAttempt() looks for, and it makes
            // probe() report 'stale' regardless of on-disk drift.
            $pdo->prepare(
                'INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at) ' .
                "VALUES(:id, :project, 'full', 'failed', 'later-attempt', '2099-01-01T00:00:00Z')",
            )->execute(['id' => $projectId . '-scan-2', 'project' => $projectId]);

            $insert = $pdo->prepare(
                'INSERT INTO annotations(project_id, canonical_name, kind, value, created_at, updated_at) ' .
                'VALUES(:p, :n, :k, :v, :t, :t)',
            );
            $insert->execute([
                'p' => $projectId,
                'n' => 'App\\Ops',
                'k' => 'note',
                'v' => 'stale graphs still carry their notes',
                't' => '2026-09-09T12:00:00+00:00',
            ]);

            $brief = (new SessionBriefService($pdo))->gather($root);

            assertSame('stale', $brief->state); // the state lever actually moved
            assertSame([], $brief->entryPoints); // gated off
            assertSame([], $brief->hubs); // gated off
            assertSame(true, in_array('App\\Ops: stale graphs still carry their notes', $brief->notes, true));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testUnscannedPathOutsideEveryRootPointsAtAllowRoot(): void
    {
        // Paired with the mirror test below so this cannot pass for the wrong
        // reason: an always-false flag would make this one pass too.
        [$pdo, $root, $databasePath] = $this->rootsFileFixture(['roots' => []]);
        try {
            $text = (new SessionBriefService($pdo, $databasePath))->brief($root);

            assertSame(true, str_contains($text, 'knossos allow-root'));
            assertSame(true, str_contains($text, (string) realpath($root)));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testUnscannedPathInsideARootGetsTheOrdinaryScanWording(): void
    {
        // Mirror of the test above: same unscanned path, same absence of a
        // scanned project, but the roots file now covers it. An always-true
        // flag would make the test above fail to catch anything; this one
        // guards the other direction.
        [$pdo, $root, $databasePath] = $this->rootsFileFixture(null);
        try {
            $covering = (string) realpath($root);
            file_put_contents(dirname($databasePath) . '/roots.json', json_encode(['roots' => [$covering]], JSON_THROW_ON_ERROR));

            $text = (new SessionBriefService($pdo, $databasePath))->brief($root);

            assertSame(true, str_contains($text, 'scan_project path='));
            assertSame(false, str_contains($text, 'allow-root'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAMissingGraphOutsideEveryRootPointsAtAllowRootThroughARealProbe(): void
    {
        // The `missing` state end to end, off a real StalenessProbe rather than
        // a hand-built SessionBrief: a projects row with no active scan takes
        // the probe's missing branch, and the containment check then runs
        // against a roots file that covers nothing. One insert is all the state
        // this needs, which is why it is written out rather than borrowed from
        // a scan fixture.
        //
        // NULL rather than '': active_scan_id carries a foreign key to scans,
        // which an empty string violates, so NULL is the only shape a real row
        // with no active scan can take. Both land on the same branch of the
        // probe.
        [$pdo, $root, $databasePath] = $this->rootsFileFixture(['roots' => []]);
        try {
            $pdo->prepare(
                'INSERT INTO projects(id, name, root_realpath, active_scan_id, created_at, updated_at) ' .
                "VALUES('project_no_scan', 'No Graph Fixture', :root, NULL, :t, :t)",
            )->execute(['root' => (string) realpath($root), 't' => '2026-09-09T12:00:00+00:00']);

            $brief = (new SessionBriefService($pdo, $databasePath))->gather($root);
            $text = (new SessionBriefService($pdo, $databasePath))->brief($root);

            assertSame('missing', $brief->state);
            assertSame(false, $brief->pathAllowed);
            // The roots file that decided it, named: the verdict is checkable
            // against server_info rather than being a claim about "roots".
            assertSame(true, str_contains(
                $text,
                'NO GRAPH, and ' . realpath($root) . ' is not an allowed root in ' . dirname($databasePath) . '/roots.json.',
            ));
            assertSame(true, str_contains($text, 'knossos allow-root'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAStaleProjectOutsideEveryRootIsNotToldToRescan(): void
    {
        // A CLI scan self-authorises: ScanCommand passes the root it was given
        // as its own allow-list, so `knossos scan` leaves a project that no
        // roots.json covers. The old rule (scanned implies permitted) rendered
        // `STALE (...). Run scan_project path=... first.` there, which is
        // exactly the call the server rejects.
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/Checkout.php']);
        try {
            $databaseDirectory = $root . '/db';
            mkdir($databaseDirectory, 0o777, true);
            $databasePath = $databaseDirectory . '/knossos.sqlite';
            file_put_contents($databaseDirectory . '/roots.json', json_encode(['roots' => []], JSON_THROW_ON_ERROR));
            // A later attempt that never completed is what StalenessProbe reads
            // as drift, without touching the filesystem.
            $pdo->prepare(
                'INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at) ' .
                "VALUES(:id, :project, 'full', 'failed', 'later-attempt', '2099-01-01T00:00:00Z')",
            )->execute(['id' => $projectId . '-scan-2', 'project' => $projectId]);

            $brief = (new SessionBriefService($pdo, $databasePath))->gather($root);
            $text = (new SessionBriefService($pdo, $databasePath))->brief($root);

            assertSame('stale', $brief->state);
            assertSame(false, $brief->pathAllowed);
            assertSame(true, str_contains($text, 'is not an allowed root in ' . dirname($databasePath) . '/roots.json. Add it: knossos allow-root'));
            assertSame(false, str_contains($text, 'scan_project'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAPathThatDoesNotExistIsNotReportedAsAnUnallowedRoot(): void
    {
        // The two-step dead end this pair of tests exists to prevent: RootGuard
        // used to raise one exception type for "no such directory" and for
        // "outside every root", so the brief read the first as the second and
        // answered a path that is not there with `knossos allow-root <path>`,
        // which then refuses it for not being a directory. The verdict must say
        // what is actually wrong and recommend neither command.
        [$pdo, $root, $databasePath] = $this->rootsFileFixture(['roots' => []]);
        try {
            $absent = $root . '/no-such-directory';

            $brief = (new SessionBriefService($pdo, $databasePath))->gather($absent);
            $text = (new SessionBriefService($pdo, $databasePath))->brief($absent);

            assertSame(false, $brief->pathExists);
            // RootGuard refused it, so it must not read as allowed to anything
            // consuming the gathered brief rather than its rendered text.
            assertSame(false, $brief->pathAllowed);
            assertSame(true, str_contains($text, $absent . ' does not exist.'));
            assertSame(false, str_contains($text, 'is not an allowed root'));
            // Both command names occur in the verdict, as the two things that
            // will not accept this path. Neither may occur as an instruction.
            assertSame(false, str_contains($text, 'knossos allow-root'));
            assertSame(false, str_contains($text, 'scan_project path='));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAPathThatExistsButIsOutsideEveryRootKeepsTheAllowRootWording(): void
    {
        // The mirror of the test above, against the same empty roots file. Both
        // paths are refused by RootGuard; only the exception type tells them
        // apart, so a fix that reported every refusal as "does not exist" would
        // pass the test above and fail this one.
        [$pdo, $root, $databasePath] = $this->rootsFileFixture(['roots' => []]);
        try {
            $brief = (new SessionBriefService($pdo, $databasePath))->gather($root);
            $text = (new SessionBriefService($pdo, $databasePath))->brief($root);

            assertSame(true, $brief->pathExists);
            assertSame(false, $brief->pathAllowed);
            assertSame(true, str_contains($text, 'is not an allowed root in ' . dirname($databasePath) . '/roots.json. Add it: knossos allow-root'));
            assertSame(false, str_contains($text, 'does not exist.'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testExistenceIsStillReportedWithNoDatabasePathToConsult(): void
    {
        // With no database path there is no roots file, so no root warning has
        // any basis and every path is treated as allowed. Whether a directory
        // is on disk owes nothing to that, and the brief must not tell a
        // session to scan a path that is not there just because it had no
        // allow-list to read.
        [$pdo] = $this->storeFixture();

        $text = (new SessionBriefService($pdo))->brief('/knossos-definitely-absent-' . bin2hex(random_bytes(6)));

        assertSame(true, str_contains($text, 'does not exist.'));
        assertSame(false, str_contains($text, 'scan_project path='));
    }

    /**
     * The verdict line quotes these numbers, so each one is the probe's own.
     *
     * One file of each kind of drift, so a term dropped from the sum, or a
     * default standing in for a count that was there, moves the total.
     */
    #[Group('query')]
    public function testDriftAgeAndTrackedFilesAreTheScansOwnCounts(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php', 'src/b.php', 'lib/c.php']);
        try {
            file_put_contents($root . '/src/a.php', "<?php\n// edited\n");
            touch($root . '/src/a.php', time() + 60);
            unlink($root . '/src/b.php');
            file_put_contents($root . '/lib/new.php', "<?php\n");

            $brief = (new SessionBriefService($pdo))->gather($root);

            assertSame('stale', $brief->state);
            assertSame(3, $brief->changedFiles, 'One changed, one deleted and one added file.');
            assertSame(3, $brief->trackedFiles, 'The scan tracked three files.');
            // The fixture backdates the scan by five seconds.
            assertSame(true, is_int($brief->ageSeconds) && $brief->ageSeconds >= 5);
            assertSame($projectId, $brief->projectId);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A graph whose root has gone cannot be fingerprinted, which is no drift rather than invented drift. */
    #[Group('query')]
    public function testAnUnverifiedGraphReportsNoDrift(): void
    {
        [$pdo, , $root] = $this->seedProjectWithFiles(['src/a.php']);
        $this->removeTempTree($root);

        $brief = (new SessionBriefService($pdo))->gather($root);

        assertSame('unverified', $brief->state);
        assertSame(0, $brief->changedFiles);
        assertSame(1, $brief->trackedFiles);
    }

    #[Group('query')]
    public function testAnUnscannedPathHasNoDriftAgeOrTrackedFiles(): void
    {
        $brief = SessionBriefService::unscanned('/root/Elsewhere');

        assertSame(0, $brief->changedFiles);
        assertSame(0, $brief->trackedFiles);
        assertSame(null, $brief->ageSeconds);
    }

    /** Every usable policy becomes one rule, in declaration order; one without a source boundary is skipped. */
    #[Group('query')]
    public function testBoundaryPoliciesBecomeRulesAnAgentCanRead(): void
    {
        [$pdo, , $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            file_put_contents($root . '/knossos.json', json_encode([
                'version' => 1,
                'boundaries' => [
                    ['name' => 'core', 'path_prefix' => 'src'],
                    ['name' => 'app', 'path_prefix' => 'app'],
                    ['name' => 'tests', 'path_prefix' => 'tests'],
                ],
                'policies' => [
                    ['id' => 'core-stays-clean', 'from_boundary' => 'core', 'deny_targets' => ['tests', 'app']],
                    ['id' => 'app-uses-core', 'from_boundary' => 'app', 'allow_targets' => ['core']],
                ],
            ], JSON_THROW_ON_ERROR));

            $brief = (new SessionBriefService($pdo))->gather($root);

            assertSame(['core -x-> tests, app', 'app --> only core'], $brief->rules);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A caller that already holds a topology service passes it, and that one ranks the hubs. */
    #[Group('query')]
    public function testAnInjectedTopologyIsTheOneThatRanksHubs(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('php-scanner');
        try {
            $this->seedRouteAndHub($pdo, $projectId);
            $overAnEmptyGraph = new GraphTopologyQueryService($this->freshTestDatabase());

            $default = (new SessionBriefService($pdo))->gather($root);
            $injected = (new SessionBriefService($pdo, null, $overAnEmptyGraph))->gather($root);

            assertSame(true, in_array('PaymentGateway (class, degree 10)', $default->hubs, true));
            assertSame([], $injected->hubs, 'The injected service reads a graph with no hubs.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * `KNOSSOS_ALLOWED_ROOTS` grants a root to the brief exactly as it does to
     * the server, blank entries from a stray separator included.
     */
    #[Group('query')]
    public function testARootFromTheEnvironmentIsAllowedDespiteBlankEntries(): void
    {
        [$pdo, $root, $databasePath] = $this->rootsFileFixture(['roots' => []]);
        $previous = getenv('KNOSSOS_ALLOWED_ROOTS');
        try {
            putenv('KNOSSOS_ALLOWED_ROOTS=' . PATH_SEPARATOR . realpath($root) . PATH_SEPARATOR);

            $brief = (new SessionBriefService($pdo, $databasePath))->gather($root);

            assertSame(true, $brief->pathAllowed);
        } finally {
            putenv(is_string($previous) ? 'KNOSSOS_ALLOWED_ROOTS=' . $previous : 'KNOSSOS_ALLOWED_ROOTS');
            $this->removeTempTree($root);
        }
    }

    /**
     * A real temp directory (so RootGuard's own existence check can pass or
     * fail on its merits, not on a fixture path that was never created), a
     * fresh database, and a roots.json seeded beside a database path that
     * lives inside that same temp tree so removeTempTree() cleans up both.
     *
     * @param array{roots: list<string>}|null $rootsFile written verbatim as
     *   JSON when given; omitted entirely (no file at all) when null.
     * @return array{0: PDO, 1: string, 2: string} [pdo, project root, database path]
     */
    private function rootsFileFixture(?array $rootsFile): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root, 0o777, true);
        $dbDir = $root . '/db';
        mkdir($dbDir, 0o777, true);
        $databasePath = $dbDir . '/knossos.sqlite';
        if ($rootsFile !== null) {
            file_put_contents($dbDir . '/roots.json', json_encode($rootsFile, JSON_THROW_ON_ERROR));
        }

        return [$this->freshTestDatabase(), $root, $databasePath];
    }

    /**
     * A node whose kind qualifies as an entry point, and a second node that
     * is the target of edges, so entryPoints() and hubs() both have a
     * candidate that would appear only by actually reading the graph.
     * hubs() ranks by inbound edge count against the fixture's own graph
     * (its densest real node, Ledger, sits at degree 8), so ten edges are
     * wired in to make the seeded hub win the ranking outright rather than
     * relying on a tie the ORDER BY could break either way.
     */
    private function seedRouteAndHub(PDO $pdo, string $projectId): void
    {
        $repository = new SqliteGraphRepository($pdo);
        $statement = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        $scanId = (string) $statement->fetchColumn();

        $route = StableId::symbol($projectId, 'php', 'route', 'App\\LoginRoute');
        $repository->saveNode(
            $route,
            $projectId,
            'php',
            'route',
            'App\\LoginRoute',
            'LoginRoute',
            null,
            null,
            null,
            null,
            'ast',
            'certain',
            [],
            'test:session-brief-route',
            $scanId,
        );
        $hub = StableId::symbol($projectId, 'php', 'class', 'App\\PaymentGateway');
        $repository->saveNode(
            $hub,
            $projectId,
            'php',
            'class',
            'App\\PaymentGateway',
            'PaymentGateway',
            null,
            null,
            null,
            null,
            'ast',
            'certain',
            [],
            'test:session-brief-hub',
            $scanId,
        );
        for ($i = 0; $i < 10; $i++) {
            $source = StableId::symbol($projectId, 'php', 'class', "App\\Source{$i}");
            $repository->saveNode(
                $source,
                $projectId,
                'php',
                'class',
                "App\\Source{$i}",
                "Source{$i}",
                null,
                null,
                null,
                null,
                'ast',
                'certain',
                [],
                "test:session-brief-src-{$i}",
                $scanId,
            );
            $edge = StableId::edge($projectId, 'calls', $source, $hub, "test:session-brief-edge-{$i}");
            $repository->saveEdge(
                $edge,
                $projectId,
                'calls',
                $source,
                $hub,
                null,
                null,
                null,
                'ast',
                'certain',
                [],
                "test:session-brief-edge-{$i}",
                $scanId,
            );
        }
    }

    /**
     * The three components a brief must never offer, each seeded so that only
     * a filter can keep it out.
     *
     * Two of them are hubs by degree: a vendor class and a test helper, both
     * wired to 12 inbound edges against the seeded hub's 10, so an unfiltered
     * ranking puts them above it. The third is a command stub carrying the
     * command role inside a test module, which an unfiltered entry-point query
     * sorts ahead of the real route because 'command' precedes 'route'.
     *
     * The seeded controller is the positive half: it holds no entry-point
     * kind, only an `application.controller` role, which is how every way into
     * a repository this scanner classifies is actually recognised.
     */
    private function seedUnreportableNoise(PDO $pdo, string $projectId): void
    {
        $repository = new SqliteGraphRepository($pdo);
        $statement = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        $scanId = (string) $statement->fetchColumn();

        $saveNode = static function (string $kind, string $canonical, string $display, string $origin) use ($repository, $projectId, $scanId): string {
            $id = StableId::symbol($projectId, 'php', $kind, $canonical);
            $repository->saveNode(
                $id,
                $projectId,
                'php',
                $kind,
                $canonical,
                $display,
                null,
                null,
                null,
                null,
                $origin,
                'certain',
                [],
                'test:session-brief-' . $display,
                $scanId,
            );

            return $id;
        };
        $classify = static function (string $nodeId, string $role) use ($repository, $projectId, $scanId): void {
            $repository->saveClassification(
                StableId::classification($projectId, $nodeId, $role, 'test:session-brief'),
                $projectId,
                $nodeId,
                $role,
                'heuristic',
                'certain',
                'test:session-brief',
                null,
                null,
                null,
                [],
                $scanId,
            );
        };

        $vendor = $saveNode('external_class', 'Vendor\\VendorClient', 'VendorClient', 'external');
        $helper = $saveNode('method', 'App\\Tests\\TestKit::assert', 'TestKitAssert', 'ast');
        $classify($helper, 'quality.test_module');
        $stub = $saveNode('command', 'App\\Tests\\FakeCommandStub', 'FakeCommandStub', 'ast');
        $classify($stub, 'application.command');
        $classify($stub, 'quality.test_module');
        $controller = $saveNode('class', 'App\\CheckoutController', 'CheckoutController', 'ast');
        $classify($controller, 'application.controller');

        for ($i = 0; $i < 12; $i++) {
            $source = $saveNode('class', "App\\Noise{$i}", "Noise{$i}", 'ast');
            foreach ([$vendor, $helper] as $target) {
                $owner = "test:session-brief-noise-{$i}-{$target}";
                $repository->saveEdge(
                    StableId::edge($projectId, 'calls', $source, $target, $owner),
                    $projectId,
                    'calls',
                    $source,
                    $target,
                    null,
                    null,
                    null,
                    'ast',
                    'certain',
                    [],
                    $owner,
                    $scanId,
                );
            }
        }
    }
}
