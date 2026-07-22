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
}
