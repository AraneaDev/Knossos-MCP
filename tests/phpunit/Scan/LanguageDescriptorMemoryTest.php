<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\LanguageDescriptor;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * How a worker's memory cap reaches its command line, and what each worker
 * batches.
 *
 * LanguageDescriptor scored 87% under mutation testing. Nothing asserted that a
 * requested cap actually lands in the command, so the argument the rewrite looks
 * at could move and the cap would be silently dropped: the worker would start
 * with its packaged default and the setting would do nothing at all.
 */
final class LanguageDescriptorMemoryTest extends KnossosTestCase
{
    /** A PHP worker's memory limit is rewritten in place, in megabytes. */
    #[Group('scan')]
    public function testThePhpWorkerMemoryLimitIsRewrittenInPlace(): void
    {
        $php = self::descriptor('php');

        // A value the descriptor does not already carry. Asking for the packaged
        // default returns the descriptor untouched, so the assertion would hold
        // whether the rewrite worked or not.
        $adjusted = $php->withMemoryMb(777);

        assertSame(true, in_array('memory_limit=777M', $adjusted->command, true), implode(' ', $adjusted->command));
        assertSame(777, $adjusted->workerMemoryMb);
        assertSame(false, in_array('memory_limit=512M', $adjusted->command, true), 'The packaged cap is replaced, not kept beside the new one.');
        // The flag keeps its place: the value follows the -d that introduces it.
        $position = array_search('memory_limit=777M', $adjusted->command, true);
        assertSame('-d', $adjusted->command[$position - 1]);
        assertSame(count($php->command), count($adjusted->command), 'Rewritten in place, not appended.');
    }

    /** A TypeScript worker's heap cap is rewritten in place too. */
    #[Group('scan')]
    public function testTheTypescriptWorkerHeapCapIsRewrittenInPlace(): void
    {
        $typescript = self::descriptor('typescript');

        $adjusted = $typescript->withMemoryMb(768);

        assertSame(true, in_array('--max-old-space-size=768', $adjusted->command, true), implode(' ', $adjusted->command));
        assertSame(768, $adjusted->workerMemoryMb);
        assertSame(count($typescript->command), count($adjusted->command));
    }

    /** Asking for the cap a descriptor already has changes nothing. */
    #[Group('scan')]
    public function testAskingForTheCapItAlreadyHasReturnsTheSameDescriptor(): void
    {
        $php = self::descriptor('php');

        assertSame($php, $php->withMemoryMb($php->workerMemoryMb));
        assertSame($php, $php->withMemoryMb(null));
    }

    /** A worker with no cap to rewrite is returned untouched. */
    #[Group('scan')]
    public function testAWorkerWithNoCapToRewriteIsReturnedUntouched(): void
    {
        $python = self::descriptor('python');

        assertSame(null, $python->workerMemoryMb, 'Python manages its own memory, so it advertises no cap.');
        assertSame($python, $python->withMemoryMb(512));
    }

    /** Each worker batches the number of source bytes it was tuned for. */
    #[Group('scan')]
    public function testEachWorkerBatchesTheSourceBytesItWasTunedFor(): void
    {
        assertSame(3_000_000, self::descriptor('rust')->scanBatchSourceBytes);
    }

    private static function descriptor(string $key): LanguageDescriptor
    {
        foreach (LanguageDescriptor::defaults(self::repositoryRoot()) as $descriptor) {
            if ($descriptor->key === $key) {
                return $descriptor;
            }
        }
        self::fail(sprintf('No %s descriptor among the defaults.', $key));
    }
}
