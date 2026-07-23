<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class FastPathTest extends KnossosTestCase
{
    #[Group('scan')]
    public function testNoChangeRescanSkipsReconciliation(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $before = $this->graphSignature($pdo);
            $scansBefore = (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();

            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

            assertSame('no_change', $result->data['fast_path']);
            assertSame('incremental', $result->data['mode']);
            assertSame(0, $result->data['parsed_files']);
            assertSame($before, $this->graphSignature($pdo));
            assertSame($scansBefore, (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn());
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('scan')]
    public function testMtimeOnlyTouchTakesFastPathAndRefreshesFreshness(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $file = $root . '/src/CheckoutService.php';
            touch($file, filemtime($file) + 30);

            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

            assertSame('no_change', $result->data['fast_path']);
            $staleness = (new StalenessProbe($pdo))->probe($projectId);
            assertSame('fresh', $staleness['state']);
            assertSame(0, $staleness['changed_files_since']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('scan')]
    public function testContentChangeOrFullModeSkipsFastPath(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);

            $full = $service->scan($root, mode: 'full');
            assertSame(false, array_key_exists('fast_path', $full->data));

            $file = $root . '/src/CheckoutService.php';
            file_put_contents($file, "\n// changed\n", FILE_APPEND);
            touch($file, filemtime($file) + 60);
            $changed = $service->scan($root);
            assertSame(false, array_key_exists('fast_path', $changed->data));
            assertSame(true, $changed->data['parsed_files'] >= 1);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('scan')]
    public function testExplicitBoundariesSkipFastPath(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);

            // A no-change rescan alone would take the fast path...
            $baseline = $service->scan($root);
            assertSame('no_change', $baseline->data['fast_path']);

            // ...but explicit boundary overrides are not reflected in the stored
            // configuration hash, so they must bypass the fast path and reconcile.
            $withBoundaries = $service->scan($root, explicitBoundaries: [
                ['name' => 'Custom', 'path_prefix' => 'src/'],
            ]);
            assertSame(false, array_key_exists('fast_path', $withBoundaries->data));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('scan')]
    public function testRenameSkipsFastPath(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);

            $baseline = $service->scan($root);
            assertSame('no_change', $baseline->data['fast_path']);

            // A rename argument differs from the stored project name, so the fast
            // path (which would discard the rename) must be bypassed.
            $renamed = $service->scan($root, name: 'Renamed Project');
            assertSame(false, array_key_exists('fast_path', $renamed->data));

            $storedName = (string) $pdo->query('SELECT name FROM projects LIMIT 1')->fetchColumn();
            assertSame('Renamed Project', $storedName);

            // Re-scanning with the same (now-stored) name takes the fast path again.
            $again = $service->scan($root, name: 'Renamed Project');
            assertSame('no_change', $again->data['fast_path']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('scan')]
    public function testConfigOrScannerSetChangeSkipsFastPath(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);

            $configChanged = $service->scan($root, snapshotRetention: 3);
            assertSame(false, array_key_exists('fast_path', $configChanged->data));

            $pdo->exec("UPDATE scans SET scanner_set_hash = 'not-the-real-hash' WHERE id = (SELECT active_scan_id FROM projects LIMIT 1)");
            $hashChanged = $service->scan($root, snapshotRetention: 3);
            assertSame(false, array_key_exists('fast_path', $hashChanged->data));

            $result = $service->scan($root, snapshotRetention: 3);
            assertSame('no_change', $result->data['fast_path']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
