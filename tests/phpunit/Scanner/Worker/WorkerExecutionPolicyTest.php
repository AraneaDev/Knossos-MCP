<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Worker;

use InvalidArgumentException;
use Knossos\Scan\LanguageDescriptor;
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
        assertSame(4, $meta['max_scan_batch_halvings']);
    }

    public function testMetadataCarriesNoBatchBoundsBecauseTheyArePerLanguage(): void
    {
        // A single pair of numbers here would misreport whichever language
        // actually differs — which is TypeScript, the one most likely to
        // overflow. The scan result reports them per language instead.
        $meta = (new WorkerExecutionPolicy())->metadata();

        assertSame(false, array_key_exists('scan_batch_files', $meta));
        assertSame(false, array_key_exists('scan_batch_source_bytes', $meta));
    }

    /**
     * Measured expansion (protocol output bytes / source bytes) per language,
     * over real hand-written sources. Every packaged descriptor's budget is
     * checked against its own figure below.
     *
     * @return array<string, float>
     */
    private static function measuredExpansion(): array
    {
        return [
            // KaTeX src/*.ts at 1.68x and KaTeX plus this repository's own
            // worker sources at 1.81x; the higher one is used.
            'typescript' => 1.81,
            // This repository's own src/ at 2.24x.
            'php' => 2.24,
            // This repository's Python worker and its tests at 1.88x.
            'python' => 1.88,
        ];
    }

    public function testEveryDescriptorsBatchBudgetStaysUnderHalfTheOutputCap(): void
    {
        // Asserted per descriptor against that language's own measured
        // expansion, because one shared factor cannot describe them: an earlier
        // version of this test asserted the default budget against 4x while its
        // own docblock claimed 15.9x, so it validated nothing.
        $cap = (new WorkerLimits())->maxOutputBytes;
        foreach (self::packagedDescriptors() as $key => $descriptor) {
            // Asserted before it is read. A language added later has no entry
            // here, and reading the missing key yielded null, so $projected
            // became 0 and the assertion below passed without checking
            // anything — the exact failure this test exists to prevent.
            $expansion = self::measuredExpansion()[$key] ?? null;
            assertSame(
                true,
                $expansion !== null,
                sprintf('%s has no measured expansion factor; measure it before packaging the descriptor.', $key),
            );
            $projected = $descriptor->scanBatchSourceBytes * $expansion;

            assertSame(
                true,
                $projected <= $cap * 0.5,
                sprintf(
                    '%s projects %d bytes of output per batch (%d source bytes at %.2fx), over half of the %d cap.',
                    $descriptor->key,
                    (int) $projected,
                    $descriptor->scanBatchSourceBytes,
                    $expansion,
                    $cap,
                ),
            );
        }
    }

    public function testPackagedTypescriptDescriptorCarriesItsOwnBatchBounds(): void
    {
        // Pins the production constants themselves, not just the parameterised
        // helper the runner tests use. Without this, deleting the override
        // silently returns a dense TypeScript project to WORKER_OUTPUT_LIMIT
        // with a fully green suite.
        $descriptors = self::packagedDescriptors();

        assertSame(2_000, $descriptors['typescript']->scanBatchFiles);
        assertSame(3_000_000, $descriptors['typescript']->scanBatchSourceBytes);
        // The other two keep the defaults, so a change to either is deliberate.
        assertSame(WorkerExecutionPolicy::SCAN_BATCH_FILES, $descriptors['php']->scanBatchFiles);
        assertSame(WorkerExecutionPolicy::SCAN_BATCH_SOURCE_BYTES, $descriptors['php']->scanBatchSourceBytes);
        assertSame(WorkerExecutionPolicy::SCAN_BATCH_FILES, $descriptors['python']->scanBatchFiles);
        assertSame(WorkerExecutionPolicy::SCAN_BATCH_SOURCE_BYTES, $descriptors['python']->scanBatchSourceBytes);
    }

    public function testTypescriptTakesAWiderFileCapThanTheDefault(): void
    {
        // TypeScript pays a whole-program cost per request rather than per file,
        // so its file cap must stay above the default or a normal project is
        // split into requests that each repeat that cost. Only the relation is
        // asserted here; the exact values are pinned once, above, and repeating
        // them as an inequality said nothing the exact pin does not already say.
        $descriptors = self::packagedDescriptors();

        assertSame(true, $descriptors['typescript']->scanBatchFiles > WorkerExecutionPolicy::SCAN_BATCH_FILES);
    }

    /**
     * The packaged descriptors, keyed by language, as both tests above need
     * them.
     *
     * @return array<string, LanguageDescriptor>
     */
    private static function packagedDescriptors(): array
    {
        $descriptors = [];
        foreach (LanguageDescriptor::defaults('/opt/knossos') as $descriptor) {
            $descriptors[$descriptor->key] = $descriptor;
        }

        return $descriptors;
    }
}
