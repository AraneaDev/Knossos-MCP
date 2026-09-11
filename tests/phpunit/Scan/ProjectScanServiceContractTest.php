<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanCancelledException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * What a scan reports about itself, and what it does at the edges of its run.
 *
 * ProjectScanService scored 63% under mutation testing: the stage timings, the
 * configuration it stores for the next scan to compare, the counts the no-change
 * path reports, the log line for a lease lost mid-scan, and two of its
 * cancellation checkpoints could all change with the scan tests green, because
 * those tests asserted the graph a scan left and not the scan's own account.
 */
final class ProjectScanServiceContractTest extends KnossosTestCase
{
    /** Every stage is timed, each timing is a plausible duration, and none is lost when the next is added. */
    #[Group('scan')]
    public function testAFullScanTimesEveryStage(): void
    {
        [$pdo, , $root] = $this->scanTempFixture('mixed');
        try {
            $stages = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'full')->data['metrics']['stages_ms'];

            foreach (['configuration', 'discovery', 'planning', 'analysis', 'reconciliation'] as $stage) {
                assertSame(true, array_key_exists($stage, $stages), sprintf('%s is not timed.', $stage));
            }
            foreach (['prepare', 'archive_snapshot', 'read_existing', 'save_nodes', 'prune', 'commit'] as $phase) {
                assertSame(true, array_key_exists('reconciliation.' . $phase, $stages), sprintf('reconciliation.%s is not timed.', $phase));
            }
            foreach ($stages as $stage => $milliseconds) {
                assertSame(true, $milliseconds >= 0.0 && $milliseconds < 60_000.0, sprintf('%s took %s ms.', $stage, $milliseconds));
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The stored configuration is what the next scan compares to decide nothing changed. */
    #[Group('scan')]
    public function testAScanStoresTheConfigurationTheNextScanCompares(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $stored = json_decode((string) $pdo->query("SELECT config_json FROM projects WHERE id = '{$projectId}'")->fetchColumn(), true);
            ksort($stored);

            assertSame(['configuration_hash', 'dead_code_suppressions', 'input_hash', 'snapshot_retention'], array_keys($stored));
            assertSame(true, is_string($stored['input_hash']) && $stored['input_hash'] !== '');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A rescan that changed nothing reports the stored graph's counts, not zeroes. */
    #[Group('scan')]
    public function testTheNoChangePathReportsTheStoredGraph(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

            assertSame('no_change', $result->data['fast_path']);
            foreach (['files', 'nodes', 'edges', 'diagnostics'] as $table) {
                $stored = (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE project_id = '{$projectId}'")->fetchColumn();
                assertSame($stored, $result->data[$table], $table);
            }
            assertSame(true, $result->data['nodes'] > 0);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A lease that disappears while the scan runs is logged when the scan lets
     * go of it, because it means another writer may have been in the project.
     * An ordinary scan logs nothing.
     */
    #[Group('scan')]
    public function testALeaseLostMidScanIsLoggedAndAnOrdinaryScanIsNot(): void
    {
        [$pdo, , $root] = $this->scanTempFixture('mixed');
        $log = tempnam(sys_get_temp_dir(), 'knossos-lease-log-');
        $previous = ini_set('error_log', (string) $log);
        try {
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $service->scan($root);
            assertSame('', (string) file_get_contents((string) $log), 'An ordinary scan logs nothing.');

            // The poll runs at every checkpoint; clearing the lock table there
            // takes the lease away while the scan still believes it holds it.
            $service->scan($root, cancellation: new CancellationToken(static function () use ($pdo): bool {
                $pdo->exec('DELETE FROM scan_locks');

                return false;
            }));

            assertSame(true, str_contains((string) file_get_contents((string) $log), 'released zero rows'));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink((string) $log);
            $this->removeTempTree($root);
        }
    }

    /**
     * A cancellation that arrives before the project's lease is taken ends the
     * scan without recording an attempt: nothing was started to record.
     */
    #[Group('scan')]
    public function testACancellationBeforeTheLeaseRecordsNoAttempt(): void
    {
        [$pdo, , $root] = $this->scanTempFixture('mixed');
        try {
            $scans = self::scanCount($pdo);
            $checks = 0;
            // The first checkpoint passes, the second (before the lease) cancels.
            $token = new CancellationToken(static function () use (&$checks): bool {
                return ++$checks >= 2;
            });

            captureThrows(
                static fn() => (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, cancellation: $token),
                ScanCancelledException::class,
            );

            assertSame($scans, self::scanCount($pdo));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The last checkpoint, after analysis and before anything is written, is
     * honoured: a cancellation that arrives there ends the scan as cancelled
     * rather than letting it reconcile.
     *
     * Found by counting: a first scan counts how often the token is consulted,
     * and a second, identical scan cancels at exactly that last consultation.
     * Both are no-change rescans, because only those consult the token a fixed
     * number of times: they send no scan request, and a scan request polls the
     * token once per frame it reads, which varies with how the pipe delivers.
     */
    #[Group('scan')]
    public function testACancellationAtTheLastCheckpointIsHonoured(): void
    {
        [$pdo, , $root] = $this->scanTempFixture('mixed');
        try {
            // A fresh service for each scan: a service keeps its workers, so a
            // second scan on the same one skips the handshake the first
            // counted, and consults the token fewer times.
            $consulted = 0;
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, cancellation: new CancellationToken(static function () use (&$consulted): bool {
                ++$consulted;

                return false;
            }));
            $scans = self::scanCount($pdo);

            $seen = 0;
            $error = captureThrows(
                static fn() => (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, cancellation: new CancellationToken(static function () use (&$seen, $consulted): bool {
                    return ++$seen >= $consulted;
                })),
                ScanCancelledException::class,
            );

            assertSame(true, $error instanceof ScanCancelledException);
            assertSame($scans + 1, self::scanCount($pdo), 'The cancelled attempt is recorded.');
            assertSame('cancelled', (string) $pdo->query('SELECT status FROM scans ORDER BY started_at DESC, rowid DESC LIMIT 1')->fetchColumn());
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A full rescan is a rebuild the caller asked for, even when there is
     * nothing to parse. On a project with files every file counts as changed in
     * full mode, so only an empty project shows whether the mode alone keeps
     * the scan off the no-change path.
     */
    #[Group('scan')]
    public function testAFullRescanOfAnEmptyProjectStillReconciles(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-empty-' . bin2hex(random_bytes(6));
        mkdir($root);
        try {
            $pdo = $this->freshTestDatabase();
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $service->scan($root);

            $rescan = $service->scan($root, mode: 'full');

            assertSame('full', $rescan->data['mode']);
            assertSame(false, array_key_exists('fast_path', $rescan->data));
        } finally {
            $this->removeTempTree($root);
        }
    }

    private static function scanCount(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();
    }
}
