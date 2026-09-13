<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\FileContent;
use Knossos\Discovery\FileContentReader;
use Knossos\Discovery\FilesystemContentReader;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\CountingStreamWrapper;
use PHPUnit\Framework\Attributes\Group;

/**
 * The size limit has to bound the read itself, not merely the decision to read.
 *
 * Discovery checks a file's size against its limit and then asks for the
 * bytes, and those are two moments over a tree the scanned project controls. A
 * manifest grown in between was loaded whole by an unbounded read: the limit
 * the walk enforced bounded nothing the reader did, and a scan of a hostile or
 * merely busy repository could be made to allocate without ceiling.
 *
 * Two claims are needed and they are not the same claim. That an oversized file
 * is reported rather than parsed is visible in the result; that no more than the
 * limit was ever resident is not, because a reader that loads everything and
 * measures it afterwards returns exactly what a bounded one returns. The second
 * is asserted against a stream that counts what it was asked for.
 */
final class BoundedContentReadTest extends KnossosTestCase
{
    /** Small enough that a fixture can cross it in one write, and far under any real manifest limit. */
    private const LIMIT = 256;

    /**
     * A manifest that outgrows the limit after its size was checked is dropped
     * with the too-large diagnostic, rather than read whole and parsed.
     *
     * Arranged through a reader that grows the file immediately before
     * delegating to the real one, because a file growing between two
     * instructions of a running scan is otherwise not something a test can
     * schedule. The delegate is the production reader, so what is asserted is
     * its behaviour and not the seam's.
     */
    #[Group('discovery')]
    public function testAManifestGrownAfterItsSizeCheckIsTooLargeRatherThanParsed(): void
    {
        $root = $this->tempRootWithSmallManifest();
        try {
            $discovery = (new ProjectDiscoverer($this->config($root), $this->readerGrowingTheFileFirst()))->discover($root);

            self::assertSame(
                ['DISCOVERY_FILE_TOO_LARGE'],
                array_values(array_map(static fn(object $diagnostic): string => $diagnostic->code, $discovery->diagnostics)),
                'A file over the limit by the time it is read is over the limit, and gets the same diagnostic as one that was over it when the walk looked.',
            );
            self::assertSame([], $discovery->units, 'Nothing may be parsed out of bytes the limit says must never have been held.');
            self::assertSame([], $discovery->files, 'And no row may describe them either; a file too large is skipped whole.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A file of exactly the limit is within it, so it is read and returned rather than rejected. */
    #[Group('discovery')]
    public function testAFileExactlyAtTheLimitIsStillRead(): void
    {
        $root = $this->tempRoot();
        try {
            $path = $root . '/exact.json';
            file_put_contents($path, str_repeat('x', self::LIMIT));

            $content = (new FilesystemContentReader())->read($path, self::LIMIT);

            self::assertSame(false, $content->oversized, 'At the limit is within it; rejecting here would drop files the walk deliberately admits.');
            self::assertSame(str_repeat('x', self::LIMIT), $content->bytes, 'And all of it is returned, not a prefix.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** One byte past the limit is past it, which is the distinction reading one byte further exists to make. */
    #[Group('discovery')]
    public function testAFileOneByteOverTheLimitIsOversized(): void
    {
        $root = $this->tempRoot();
        try {
            $path = $root . '/over.json';
            file_put_contents($path, str_repeat('x', self::LIMIT + 1));

            $content = (new FilesystemContentReader())->read($path, self::LIMIT);

            self::assertSame(true, $content->oversized, 'A read that stopped at the limit could not tell this from a file of exactly the limit.');
            self::assertSame(null, $content->bytes, 'And it hands back no prefix of it, which a caller could otherwise parse as if it were the file.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The read stops one byte past the limit, whatever the file claims to hold.
     *
     * This is the property the security fix is actually about, and the only one
     * a return value cannot show: a reader that loads ten megabytes and then
     * reports "oversized" passes every assertion above while allocating exactly
     * what the limit exists to prevent. Counted at the stream instead.
     */
    #[Group('discovery')]
    public function testTheReadNeverTakesMoreThanOneByteBeyondTheLimit(): void
    {
        CountingStreamWrapper::reset(10_000_000);
        try {
            $content = (new FilesystemContentReader())->read(CountingStreamWrapper::SCHEME . '://huge', self::LIMIT);

            self::assertSame(true, $content->oversized, 'The fixture is far over the limit, so the answer is not in doubt; what it cost to reach it is.');
            // The limit, the one byte that distinguishes over from at, and at
            // most one 8 KiB stream chunk: a user-space stream hands out whole
            // chunks and the copy discards the remainder, which is an artefact
            // of the wrapper rather than of the reader. What matters is that
            // the figure is a constant and not the ten megabytes the stream
            // was willing to serve, which is exactly what the unbounded read
            // took.
            self::assertLessThanOrEqual(
                self::LIMIT + 8192 + 1,
                CountingStreamWrapper::$bytesServed,
                'Bytes taken off the stream. An unbounded read runs to EOF and takes all ten megabytes; a bounded one cannot exceed the limit by more than a chunk.',
            );
        } finally {
            CountingStreamWrapper::unregister();
        }
    }

    /** A directory holding one manifest comfortably under the limit, which the reader below then grows. */
    private function tempRootWithSmallManifest(): string
    {
        $root = $this->tempRoot();
        file_put_contents($root . '/composer.json', '{"name":"fixture/small"}');

        return $root;
    }

    /** An empty temp directory, removed by the caller. */
    private function tempRoot(): string
    {
        // The fixture prefix removeTempTree() will consent to delete.
        $root = sys_get_temp_dir() . '/knossos-stale-bounded-' . bin2hex(random_bytes(6));
        mkdir($root, 0o777, true);

        return $root;
    }

    /** Discovery scoped to the temp root, with a limit small enough to cross in one write. */
    private function config(string $root): DiscoveryConfig
    {
        return new DiscoveryConfig([$root], maxFileBytes: self::LIMIT);
    }

    /**
     * A reader that grows the file past the limit and then reads it for real.
     *
     * The growth happens between discovery's size check and the read, which is
     * the whole scenario: the write lands after the walk has already decided
     * the file is small enough. What it grows to is valid JSON, so a reader
     * that loads it whole parses it successfully and the test can tell an
     * unbounded read from a bounded one by the result rather than by the
     * failure mode of a parser.
     */
    private function readerGrowingTheFileFirst(): FileContentReader
    {
        return new class implements FileContentReader {
            /** Grow first, then delegate to the production reader, which is what is under test. */
            public function read(string $absolutePath, int $maxBytes): FileContent
            {
                file_put_contents(
                    $absolutePath,
                    '{"name":"fixture/grown","padding":"' . str_repeat('x', $maxBytes) . '"}',
                );

                return (new FilesystemContentReader())->read($absolutePath, $maxBytes);
            }
        };
    }
}
