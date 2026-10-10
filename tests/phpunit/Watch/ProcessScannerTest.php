<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Watch;

use Knossos\Cancellation\CancellationToken;
use Knossos\Cancellation\ScanCancelledException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Watch\ProcessScanner;
use Knossos\Watch\ScanTimeoutException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertLessThan;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * The watcher's scan processes are bounded: a scan past its time limit, or
 * one whose watcher is shutting down, is stopped together with whatever it
 * started, and the watcher still beats while one runs.
 */
final class ProcessScannerTest extends KnossosTestCase
{
    /** Where the hung scan writes the process id of what it started. */
    private string $pidFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('ext-pcntl is not loaded; the hung scan cannot ignore the request to stop.');
        }
        $this->pidFile = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6)) . '.pid';
    }

    protected function tearDown(): void
    {
        @unlink($this->pidFile);
        parent::tearDown();
    }

    /** The installation whose `bin/knossos scan` never finishes. */
    private static function hung(): string
    {
        return self::repositoryRoot() . '/tests/Fixtures/hung-scan';
    }

    /** Whether the process the hung scan started is still there, after a moment for the kill to land. */
    private function startedProcessAlive(): bool
    {
        $pid = (int) @file_get_contents($this->pidFile);
        assertGreaterThan(1, $pid);
        usleep(100_000);
        return file_exists('/proc/' . $pid) && !str_contains((string) @file_get_contents('/proc/' . $pid . '/stat'), ') Z ');
    }

    #[Group('watch')]
    public function testAScanPastItsLimitIsStoppedWithWhatItStarted(): void
    {
        $beats = 0;
        $scanner = new ProcessScanner(self::hung(), $this->pidFile, 1_000, null, static function () use (&$beats): void {
            ++$beats;
        }, 200);
        $started = hrtime(true);
        try {
            $scanner->scan('/nowhere');
            self::fail('The hung scan returned.');
        } catch (ScanTimeoutException $stopped) {
            // Its own class, so the watcher can count timeouts apart from other failures.
            assertStringContainsString('ran past its 1 s limit', $stopped->getMessage());
        }
        // The limit, the grace for a scan that ignores the request to stop, and no more.
        assertLessThan(6_000, intdiv(hrtime(true) - $started, 1_000_000));
        assertGreaterThan(2, $beats);
        assertSame(false, $this->startedProcessAlive());
    }

    #[Group('watch')]
    public function testAScanIsStoppedWhenItsWatcherShutsDownOrIsOrphaned(): void
    {
        $cancellation = new CancellationToken();
        $calls = 0;
        $scanner = new ProcessScanner(self::hung(), $this->pidFile, 60_000, null, static function () use (&$calls, $cancellation): void {
            if (++$calls === 3) {
                $cancellation->cancel();
            }
        }, 100);
        try {
            $scanner->scan('/nowhere', null, $cancellation);
            self::fail('The hung scan returned.');
        } catch (ScanCancelledException) {
        }
        assertSame(false, $this->startedProcessAlive());
        $orphaned = new ProcessScanner(self::hung(), $this->pidFile, 60_000, static fn(): bool => false);
        try {
            $orphaned->scan('/nowhere');
            self::fail('The hung scan returned.');
        } catch (ScanCancelledException) {
        }
        assertSame(false, $this->startedProcessAlive());
    }
}
