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
}
