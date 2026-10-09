<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\StalenessProbe;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\CountingDriftOracle;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The probe ran git and a directory walk in every project in the database,
 * inside the server's allowed roots or not. With a root check wired in, a
 * project outside the roots is reported unverified without being read.
 */
final class StalenessProbeRootsTest extends KnossosTestCase
{
    #[Group('query')]
    public function testAProjectOutsideTheRootsIsUnverifiedWithoutReadingIt(): void
    {
        [$pdo, $projectId] = $this->fixtureOnDisk();
        $oracle = new CountingDriftOracle();
        $probe = new StalenessProbe($pdo, oracle: $oracle, rootAdmitted: static fn(string $candidate): bool => false);

        $staleness = $probe->probe($projectId);

        assertSame(0, $oracle->calls);
        assertSame('unverified', $staleness['state']);
        assertSame(true, str_contains($staleness['guidance'], 'allowed roots'));
        assertSame(false, array_key_exists('changed_files_since', $staleness), 'Nothing was measured, so no counts are reported.');
    }

    /**
     * A newer failed scan attempt is a database fact; it still makes the graph
     * stale outside the roots. The guidance said to rescan with scan_project,
     * which refuses such a root.
     */
    #[Group('query')]
    public function testAProjectOutsideTheRootsWithANewerFailedScanIsStale(): void
    {
        [$pdo, $projectId] = $this->fixtureOnDisk();
        $pdo->prepare(
            "INSERT INTO scans (id, project_id, mode, status, scanner_set_hash, started_at) VALUES ('scan-newer', :project, 'full', 'failed', 'hash', '2999-01-01T00:00:00Z')",
        )->execute(['project' => $projectId]);
        $oracle = new CountingDriftOracle();
        $probe = new StalenessProbe($pdo, oracle: $oracle, rootAdmitted: static fn(string $candidate): bool => false);

        $staleness = $probe->probe($projectId);

        assertSame(0, $oracle->calls);
        assertSame('stale', $staleness['state']);
        assertSame(
            "Graph may be stale, and this project's root is outside this server's allowed roots, so it cannot be rescanned here; add the root to the allowed roots to rescan or verify it.",
            $staleness['guidance'],
            'scan_project refuses a root outside the allowed roots, so the advice must not be to call it.',
        );
    }

    #[Group('query')]
    public function testAProjectInsideTheRootsIsStillProbed(): void
    {
        [$pdo, $projectId, $root] = $this->fixtureOnDisk();
        $seen = [];
        $oracle = new CountingDriftOracle();
        $probe = new StalenessProbe($pdo, oracle: $oracle, rootAdmitted: static function (string $candidate) use (&$seen): bool {
            $seen[] = $candidate;

            return true;
        });

        $staleness = $probe->probe($projectId);

        assertSame(1, $oracle->calls);
        assertSame('fresh', $staleness['state']);
        assertSame([$root], $seen, 'The check is asked about the project root itself.');
    }

    /** Without a root check (the CLI, the user's own process) every project is probed as before. */
    #[Group('query')]
    public function testWithoutARootCheckEveryProjectIsProbed(): void
    {
        [$pdo, $projectId] = $this->fixtureOnDisk();
        $oracle = new CountingDriftOracle();

        $staleness = (new StalenessProbe($pdo, oracle: $oracle))->probe($projectId);

        assertSame(1, $oracle->calls);
        assertSame('fresh', $staleness['state']);
    }

    /**
     * The store fixture with its scan completed and active, and its project root pointed at a directory that exists.
     *
     * @return array{PDO, string, string}
     */
    private function fixtureOnDisk(): array
    {
        [$pdo, , $ids] = $this->storeFixture();
        $root = self::repositoryRoot() . '/tests/Fixtures/mixed';
        $pdo->prepare("UPDATE scans SET status = 'complete', finished_at = '2026-01-01T00:00:00Z' WHERE id = ?")->execute([$ids['scan']]);
        $pdo->prepare('UPDATE projects SET root_realpath = ?, active_scan_id = ? WHERE id = ?')->execute([$root, $ids['scan'], $ids['project']]);

        return [$pdo, $ids['project'], $root];
    }
}
