<?php

declare(strict_types=1);

namespace Knossos\Tests\Scan;

use Knossos\Scan\LanguageDescriptor;
use Knossos\Scan\OversizedBatch;
use Knossos\Scanner\Worker\WorkerException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Which worker failures split a batch, and which keep the per-language failure.
 *
 * Each case differs from a retried one in exactly one respect, so every clause
 * of the decision is the reason some case comes out the way it does.
 */
#[Group('scan-runner')]
final class OversizedBatchTest extends TestCase
{
    private const HEAP = "FATAL ERROR: Ineffective mark-compacts near heap limit\nJavaScript heap out of memory";

    public function testEverySizeCodeSplitsTheBatchInAnyLanguage(): void
    {
        foreach (['WORKER_OUTPUT_LIMIT', 'WORKER_FRAME_TOO_LARGE', 'WORKER_REQUEST_TOO_LARGE'] as $code) {
            assertSame(true, OversizedBatch::signalledBy(self::descriptor('php'), new WorkerException($code, 'too big'), []), $code);
        }
        assertSame(false, OversizedBatch::signalledBy(self::descriptor('php'), new WorkerException('WORKER_TIMEOUT', 'slow'), []));
    }

    public function testTypeScriptHeapExhaustionSplitsTheBatch(): void
    {
        $exited = static fn(string $message): WorkerException => new WorkerException('WORKER_EXITED', $message);

        assertSame(true, OversizedBatch::signalledBy(self::descriptor('typescript'), $exited(self::HEAP), ['config_files' => []]));
        // Either line of Node's signature is enough on its own, in any case,
        // and a request with no config list at all is one with no configs.
        assertSame(true, OversizedBatch::signalledBy(self::descriptor('typescript'), $exited('JavaScript heap out of memory'), []));
        assertSame(true, OversizedBatch::signalledBy(self::descriptor('typescript'), $exited('INEFFECTIVE MARK-COMPACTS NEAR HEAP LIMIT'), []));
    }

    public function testHeapExhaustionIsOnlyASizeSignalForAnUnconfiguredTypeScriptExit(): void
    {
        $heap = new WorkerException('WORKER_EXITED', self::HEAP);

        assertSame(false, OversizedBatch::signalledBy(self::descriptor('php'), $heap, []), 'Only V8 exhausts a heap this way.');
        assertSame(false, OversizedBatch::signalledBy(self::descriptor('typescript'), new WorkerException('WORKER_TIMEOUT', self::HEAP), []), 'A timeout is not an exit.');
        assertSame(
            false,
            OversizedBatch::signalledBy(self::descriptor('typescript'), $heap, ['config_files' => ['tsconfig.json']]),
            'A configured program is the same size whatever the batch lists.',
        );
        assertSame(false, OversizedBatch::signalledBy(self::descriptor('typescript'), new WorkerException('WORKER_EXITED', 'exit code 3'), []));
    }

    private static function descriptor(string $key): LanguageDescriptor
    {
        return new LanguageDescriptor(key: $key, stage: $key . '-analysis', languages: [$key], command: ['node']);
    }
}
