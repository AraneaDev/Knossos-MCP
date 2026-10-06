<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Filesystem\RegularFileOpener;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;

/**
 * Without FFI, a file is opened by a helper process that has a deadline to
 * report the open, so a path swapped for a FIFO cannot hang the scan. On a
 * loaded machine the helper's own start-up missed that deadline, and a
 * regular file read as unreadable: the scan aborted, claiming the file could
 * not be re-read. The deadline is for the FIFO, so it is the path's type that
 * decides whether a late helper is waited for.
 */
#[Group('discovery')]
final class RegularFileOpenerHelperTest extends KnossosTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/knossos-stale-opener-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->directory);
        parent::tearDown();
    }

    public function testAHelperLateForARegularFileIsWaitedFor(): void
    {
        file_put_contents($this->directory . '/a.ts', "export const a = 1;\n");
        // The helper misses a one-nanosecond deadline, as a slow start does.
        $handle = (new ReflectionMethod(RegularFileOpener::class, 'openWithHelper'))->invoke(null, $this->directory . '/a.ts', 1);

        self::assertIsResource($handle);
        self::assertSame("export const a = 1;\n", stream_get_contents($handle));
        fclose($handle);
    }

    public function testAHelperBlockedOnAFifoStillFailsAtTheDeadline(): void
    {
        if (!function_exists('posix_mkfifo') || !posix_mkfifo($this->directory . '/pipe.ts', 0o600)) {
            self::markTestSkipped('FIFOs are not available on this platform.');
        }
        $started = hrtime(true);

        $handle = (new ReflectionMethod(RegularFileOpener::class, 'openWithHelper'))->invoke(null, $this->directory . '/pipe.ts');

        self::assertNull($handle);
        self::assertLessThan(2_000_000_000, hrtime(true) - $started);
    }

    /** With a cap, the helper stops copying one byte past it, so the caller still sees "too large". */
    public function testTheHelperStopsCopyingOneBytePastTheCap(): void
    {
        $file = $this->directory . '/big.txt';
        file_put_contents($file, str_repeat('x', 10_000));
        $method = new ReflectionMethod(RegularFileOpener::class, 'openWithHelper');

        $handle = $method->invoke(null, $file, 5_000_000_000, 100);

        self::assertIsResource($handle);
        self::assertSame(101, strlen((string) stream_get_contents($handle)));
    }

    /** The public entry point honours the cap whichever path it takes. */
    public function testOpenHonoursTheCapOnEveryPath(): void
    {
        $file = $this->directory . '/big.txt';
        file_put_contents($file, str_repeat('y', 10_000));

        $handle = RegularFileOpener::open($file, 100);

        self::assertIsResource($handle);
        self::assertLessThanOrEqual(101, strlen((string) stream_get_contents($handle, 101)));
    }
}
