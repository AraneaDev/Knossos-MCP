<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Watch;

use Knossos\Scan\CancellationToken;
use Knossos\Scan\ScanCancelledException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Watch\ProcessScanner;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringStartsWith;

/** A scan in a process of its own: its result read back, its failure and its cancellation surfaced as the watcher expects. */
final class ProcessScannerTest extends KnossosTestCase
{
    #[Group('watch')]
    public function testAScanRunsInItsOwnProcessAndItsResultIsReadBack(): void
    {
        $data = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($data);
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/turn-brief', $root);
        try {
            $result = (new ProcessScanner(self::repositoryRoot(), $data . '/knossos.sqlite'))->scan($root, mode: 'full');
            assertStringStartsWith('project_', $result->projectId);
            assertStringStartsWith('scan_', $result->snapshotId);
            assertSame(true, ($result->data['parsed_files'] ?? 0) > 0);
        } finally {
            $this->removeTempTree($root);
            $this->removeTempTree($data);
        }
    }

    #[Group('watch')]
    public function testAFailedScanIsAnErrorWithTheReasonTheProcessGave(): void
    {
        $data = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($data);
        try {
            $this->expectException(RuntimeException::class);
            (new ProcessScanner(self::repositoryRoot(), $data . '/knossos.sqlite'))->scan($data . '/missing');
        } finally {
            $this->removeTempTree($data);
        }
    }

    #[Group('watch')]
    public function testACancelledWatcherStopsTheScanProcess(): void
    {
        $token = new CancellationToken();
        $token->cancel();
        $this->expectException(ScanCancelledException::class);
        (new ProcessScanner(self::repositoryRoot(), sys_get_temp_dir() . '/knossos-stale-none/knossos.sqlite'))->scan(sys_get_temp_dir(), cancellation: $token);
    }
}
