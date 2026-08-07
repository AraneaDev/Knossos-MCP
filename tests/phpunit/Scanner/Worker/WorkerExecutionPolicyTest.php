<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Worker;

use InvalidArgumentException;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use Knossos\Scanner\Worker\WorkerLimits;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-worker')]
final class WorkerExecutionPolicyTest extends TestCase
{
    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(WorkerExecutionPolicy::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testConstructorAppliesDefaultTimeout(): void
    {
        $p = new WorkerExecutionPolicy();
        assertSame(30_000, $p->requestTimeoutMs);
    }

    public function testConstructorStoresExplicitTimeout(): void
    {
        $p = new WorkerExecutionPolicy(15_000);
        assertSame(15_000, $p->requestTimeoutMs);
    }

    public function testRejectsTimeoutBelowMin(): void
    {
        assertThrows(
            static fn() => new WorkerExecutionPolicy(999),
            InvalidArgumentException::class,
        );
    }

    public function testAcceptsMinTimeoutBoundary(): void
    {
        $p = new WorkerExecutionPolicy(WorkerExecutionPolicy::MIN_REQUEST_TIMEOUT_MS);
        assertSame(WorkerExecutionPolicy::MIN_REQUEST_TIMEOUT_MS, $p->requestTimeoutMs);
    }

    public function testRejectsTimeoutAboveMax(): void
    {
        assertThrows(
            static fn() => new WorkerExecutionPolicy(120_001),
            InvalidArgumentException::class,
        );
    }

    public function testAcceptsMaxTimeoutBoundary(): void
    {
        $p = new WorkerExecutionPolicy(WorkerExecutionPolicy::MAX_REQUEST_TIMEOUT_MS);
        assertSame(WorkerExecutionPolicy::MAX_REQUEST_TIMEOUT_MS, $p->requestTimeoutMs);
    }

    // ----- limits() -----

    public function testLimitsReturnsWorkerLimitsWithRequestTimeout(): void
    {
        $p = new WorkerExecutionPolicy(10_000);
        $limits = $p->limits();

        $this->assertInstanceOf(WorkerLimits::class, $limits);
        assertSame(10_000, $limits->requestTimeoutMs);
    }

    // ----- metadata() -----

    public function testMetadataReturnsExpectedStructure(): void
    {
        $p = new WorkerExecutionPolicy(7_500);
        $meta = $p->metadata();

        assertSame(7_500, $meta['request_timeout_ms']);
        assertSame(120_000, $meta['maximum_request_timeout_ms']);
        assertSame(1_000_000, $meta['max_line_bytes']);
        assertSame(20_000_000, $meta['max_output_bytes']);
        assertSame(100_000, $meta['max_stderr_bytes']);
        assertSame(400, $meta['scan_batch_files']);
        assertSame(4_000_000, $meta['scan_batch_source_bytes']);
    }

    public function testScanBatchSourceBudgetLeavesRealHeadroomUnderTheOutputCap(): void
    {
        // The source-byte budget is what actually guards max_output_bytes, and
        // it has to hold for a language that expands far more than PHP's
        // measured ~2.2x. TypeScript measured 15.2-15.9x, so require the default
        // budget to survive a 4x expansion with the cap still unspent: an
        // assertion that merely clears the cap would pass right up to the batch
        // size at which the original ceiling reappears.
        $limits = new WorkerLimits();

        assertSame(true, WorkerExecutionPolicy::SCAN_BATCH_SOURCE_BYTES * 4 < $limits->maxOutputBytes);
    }

    public function testScanBatchFileCapLeavesRealHeadroomUnderTheOutputCap(): void
    {
        // The file cap is the deadline guard, but it must not on its own be able
        // to reinstate the byte ceiling: at the measured ~14.9 KB per PHP file a
        // batch has to stay a wide margin under the cap, not merely inside it.
        $limits = new WorkerLimits();

        assertSame(true, WorkerExecutionPolicy::SCAN_BATCH_FILES * 15_000 * 3 < $limits->maxOutputBytes);
    }
}
