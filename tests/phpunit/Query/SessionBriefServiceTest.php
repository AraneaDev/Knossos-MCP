<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\SessionBriefService;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

final class SessionBriefServiceTest extends KnossosTestCase
{
    #[Group('query')]
    public function testUnscannedPathYieldsTheShortestBrief(): void
    {
        [$pdo] = $this->storeFixture();

        $text = (new SessionBriefService($pdo))->brief(sys_get_temp_dir());

        assertSame(true, str_starts_with($text, 'NOT SCANNED.'));
        assertSame(true, str_contains($text, 'scan_project path='));
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
        // disk, so StalenessProbe::changedFilesSince() always bails out to
        // null there and 'fresh' can never actually be observed. A real scan
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
            assertSame(true, in_array('PaymentGateway', $brief->hubs, true));
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
}
