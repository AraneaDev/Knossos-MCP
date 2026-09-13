<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\FileFingerprint;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('file-fingerprint')]
final class FileFingerprintTest extends TestCase
{
    private string $tempPath;

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && is_file($this->tempPath)) {
            @unlink($this->tempPath);
        }
    }

    // ----- helpers -----

    private function writeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-fingerprint-');
        file_put_contents($path, $contents);
        $this->tempPath = $path;

        return $path;
    }

    // ----- git blob id -----

    /**
     * The default names a blob the way a SHA-1 repository does, which is what
     * the overwhelming majority of checkouts are.
     */
    public function testItNamesABlobTheWayASha1RepositoryDoes(): void
    {
        $path = $this->writeTempFile("hello\n");

        $fingerprint = FileFingerprint::compute($path);

        $this->assertNotNull($fingerprint);
        assertSame(sha1("blob 6\0hello\n"), $fingerprint->gitBlobHash);
    }

    /**
     * And a SHA-256 repository names the same bytes differently. Computing the
     * SHA-1 id for such a repository is not a near miss: it matches nothing in
     * its trees, so every tracked file reads as dirty and the drift comparison
     * that depends on the id stops working altogether.
     */
    public function testItNamesABlobTheWayASha256RepositoryDoes(): void
    {
        $path = $this->writeTempFile("hello\n");

        $fingerprint = FileFingerprint::compute($path, 'sha256');

        $this->assertNotNull($fingerprint);
        assertSame(hash('sha256', "blob 6\0hello\n"), $fingerprint->gitBlobHash);
        assertSame(hash('sha256', "hello\n"), $fingerprint->contentHash, "The graph's own content hash is SHA-256 whatever the repository is, and must not move with the object format.");
    }

    // ----- fromContents() -----

    /**
     * Discovery streams most files and buffers the ones it also has to parse,
     * so the two paths have to agree on every field. If they drift, a
     * manifest's stored hash stops matching what the same bytes would hash to
     * through the other path, and the drift oracles compare one against the
     * other for ever.
     *
     * Spelled over several shapes because the line count is where the two
     * implementations actually differ: one counts terminators as it streams,
     * the other over a whole buffer.
     */
    public function testBufferedAndStreamedFingerprintsAgree(): void
    {
        foreach (['', "a\n", 'a', "a\nb", "a\nb\n", "\n", "line\r\nline\r\n"] as $contents) {
            $path = $this->writeTempFile($contents);
            $streamed = FileFingerprint::compute($path, 'sha256');
            $buffered = FileFingerprint::fromContents($contents, 'sha256');

            $this->assertNotNull($streamed);
            assertSame($streamed->contentHash, $buffered->contentHash, 'Same bytes, same content hash, whichever path read them.');
            assertSame($streamed->lineCount, $buffered->lineCount, 'Same bytes, same line count: ' . var_export($contents, true));
            assertSame($streamed->gitBlobHash, $buffered->gitBlobHash, 'Same bytes, same blob id, or a manifest matches nothing in its own repository.');
        }
    }

    // ----- compute() -----

    public function testComputeReturnsNullWhenFileDoesNotExist(): void
    {
        $missing = sys_get_temp_dir() . '/knossos-fingerprint-missing-' . uniqid('', true) . '.txt';

        $result = FileFingerprint::compute($missing);

        assertSame(null, $result);
    }

    public function testComputeOnEmptyFileReturnsZeroLinesAndValidSha256(): void
    {
        $path = $this->writeTempFile('');

        $fingerprint = FileFingerprint::compute($path);

        $this->assertNotNull($fingerprint);
        assertSame(0, $fingerprint->lineCount);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint->contentHash);
        assertSame(hash('sha256', ''), $fingerprint->contentHash);
    }

    public function testComputeCountsSingleTerminatedLineAsOne(): void
    {
        $path = $this->writeTempFile("a\n");

        $fingerprint = FileFingerprint::compute($path);

        $this->assertNotNull($fingerprint);
        assertSame(1, $fingerprint->lineCount);
        assertSame(hash('sha256', "a\n"), $fingerprint->contentHash);
    }

    public function testComputeCountsSingleUnterminatedLineAsOne(): void
    {
        $path = $this->writeTempFile('a');

        $fingerprint = FileFingerprint::compute($path);

        $this->assertNotNull($fingerprint);
        assertSame(1, $fingerprint->lineCount);
        assertSame(hash('sha256', 'a'), $fingerprint->contentHash);
    }

    public function testComputeCountsMultipleNewlinesCorrectly(): void
    {
        $contents = "alpha\nbravo\ncharlie\n";
        $path = $this->writeTempFile($contents);

        $fingerprint = FileFingerprint::compute($path);

        $this->assertNotNull($fingerprint);
        assertSame(3, $fingerprint->lineCount);
        assertSame(hash('sha256', $contents), $fingerprint->contentHash);
    }

    public function testComputeCountsTrailingLineWithoutNewline(): void
    {
        $contents = "alpha\nbravo\ncharlie";
        $path = $this->writeTempFile($contents);

        $fingerprint = FileFingerprint::compute($path);

        $this->assertNotNull($fingerprint);
        assertSame(3, $fingerprint->lineCount);
        assertSame(hash('sha256', $contents), $fingerprint->contentHash);
    }

    public function testComputeCountsCrlfAsSingleNewline(): void
    {
        $contents = "alpha\r\nbravo\r\ncharlie\r\n";
        $path = $this->writeTempFile($contents);

        $fingerprint = FileFingerprint::compute($path);

        $this->assertNotNull($fingerprint);
        assertSame(3, $fingerprint->lineCount);
        assertSame(hash('sha256', $contents), $fingerprint->contentHash);
    }

    public function testComputeHandlesLargeFileAcrossMultipleReadChunks(): void
    {
        $line = str_repeat('x', 100) . "\n";
        $contents = str_repeat($line, 700);
        $path = $this->writeTempFile($contents);

        $fingerprint = FileFingerprint::compute($path);

        $this->assertNotNull($fingerprint);
        assertSame(700, $fingerprint->lineCount);
        assertSame(hash('sha256', $contents), $fingerprint->contentHash);
    }

    public function testComputeOnLargeFileWithoutTrailingNewlineSpansChunksAndCountsLastLine(): void
    {
        // 700 lines × (100 'y' + "\n") = 70,700 bytes; strip the final "\n" so the
        // file spans two fread() chunks (> 65,536) yet ends without a newline.
        $line = str_repeat('y', 100) . "\n";
        $contents = rtrim(str_repeat($line, 700), "\n");

        $this->assertGreaterThan(65_536, strlen($contents), 'test fixture must exceed a single read chunk');

        $path = $this->writeTempFile($contents);

        $fingerprint = FileFingerprint::compute($path);

        $this->assertNotNull($fingerprint);
        assertSame(700, $fingerprint->lineCount);
        assertSame(hash('sha256', $contents), $fingerprint->contentHash);
    }

    public function testConstructorExposesContentHashAndLineCount(): void
    {
        $fingerprint = new FileFingerprint('hash-value', 42);

        assertSame('hash-value', $fingerprint->contentHash);
        assertSame(42, $fingerprint->lineCount);
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(FileFingerprint::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }
}