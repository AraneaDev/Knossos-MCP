<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use InvalidArgumentException;
use Knossos\Scan\ScanSnapshotChangedException;
use Knossos\Scan\UndiscoveredInputVerifier;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The re-read, just before a scan commits, of every file a worker read that
 * discovery never hashed.
 */
#[Group('scan')]
final class UndiscoveredInputVerifierTest extends KnossosTestCase
{
    private const DEP = 'node_modules/dep/index.d.ts';

    private string $root;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $this->outside = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/node_modules/dep', 0o777, true);
        mkdir($this->outside, 0o777, true);
        file_put_contents($this->root . '/' . self::DEP, "export declare const dep: 1;\n");
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        $this->removeTempTree($this->outside);
        parent::tearDown();
    }

    public function testAnUnchangedFileAndAStillAbsentFilePass(): void
    {
        $this->verify([self::DEP => $this->hashOf(self::DEP), 'node_modules/dep/missing.d.ts' => null, 'absent/below/it.ts' => null]);

        assertSame("export declare const dep: 1;\n", (string) file_get_contents($this->root . '/' . self::DEP));
    }

    public function testNothingToVerifyPasses(): void
    {
        $this->verify([]);

        $this->addToAssertionCount(1);
    }

    public function testChangedBytesFailTheScan(): void
    {
        $reported = $this->hashOf(self::DEP);
        file_put_contents($this->root . '/' . self::DEP, "export declare const dep: 2;\n");

        $this->assertFails([self::DEP => $reported], self::DEP);
    }

    public function testAFileRemovedAfterItWasReadFailsTheScan(): void
    {
        $reported = $this->hashOf(self::DEP);
        unlink($this->root . '/' . self::DEP);

        $this->assertFails([self::DEP => $reported], self::DEP);
    }

    public function testAFileThatAppearedAfterAFailedReadFailsTheScan(): void
    {
        $this->assertFails([self::DEP => null], self::DEP);
    }

    public function testAPathThatBecameADirectoryFailsAgainstAHash(): void
    {
        $reported = $this->hashOf(self::DEP);
        unlink($this->root . '/' . self::DEP);
        mkdir($this->root . '/' . self::DEP);

        $this->assertFails([self::DEP => $reported], self::DEP);
    }

    public function testADirectoryDoesNotPassAsAnEmptyFile(): void
    {
        // A directory opens on Linux and yields no bytes, which hash like an empty file.
        mkdir($this->root . '/generated');

        $this->assertFails(['generated' => hash('sha256', '')], 'generated');
    }

    /** Opening a FIFO blocks until a writer appears, so a swap for one must fail the scan, not hang it. */
    public function testAFifoFailsAgainstAHashWithoutBlocking(): void
    {
        $this->fifo('pipe.d.ts');

        $this->assertFails(['pipe.d.ts' => hash('sha256', '')], 'pipe.d.ts');
    }

    public function testAFifoIsConsistentWithAFailedReadWithoutBlocking(): void
    {
        $this->fifo('pipe.d.ts');

        $this->verify(['pipe.d.ts' => null]);

        assertSame('fifo', filetype($this->root . '/pipe.d.ts'));
    }

    public function testADirectoryIsConsistentWithAFailedRead(): void
    {
        $this->verify(['node_modules/dep' => null]);

        $this->addToAssertionCount(1);
    }

    /**
     * The TypeScript worker reads a dependency's declaration file up to
     * sixteen times the source cap, since a framework's types can fill one
     * file; this check has to agree, or the hash it read fails the scan.
     */
    public function testADependencyDeclarationGetsSixteenTimesTheCap(): void
    {
        $declaration = str_repeat('x', 150);
        file_put_contents($this->root . '/' . self::DEP, $declaration);
        file_put_contents($this->root . '/big.ts', $declaration);

        $this->verify([self::DEP => hash('sha256', $declaration)], 10);
        // A project's own source keeps the plain cap.
        $this->assertFails(['big.ts' => hash('sha256', $declaration)], 'big.ts', 10);
        // And a declaration past sixteen times it is refused like any file.
        file_put_contents($this->root . '/' . self::DEP, str_repeat('x', 161));
        $this->verify([self::DEP => null], 10);
    }

    public function testAFileOverTheCapFailsEvenAgainstItsOwnHash(): void
    {
        file_put_contents($this->root . '/big.ts', str_repeat('x', 11));

        // A worker never reads past the cap, so no honest hash of these bytes exists.
        $this->assertFails(['big.ts' => hash('sha256', str_repeat('x', 11))], 'big.ts', 10);
    }

    public function testAFileExactlyAtTheCapIsCompared(): void
    {
        file_put_contents($this->root . '/big.ts', str_repeat('x', 10));

        $this->verify(['big.ts' => hash('sha256', str_repeat('x', 10))], 10);

        $this->addToAssertionCount(1);
    }

    /**
     * A worker refuses a file over the cap and reports null for it, so a null
     * against a file that is still over the cap describes the same tree.
     */
    public function testAFileStillOverTheCapIsConsistentWithAFailedRead(): void
    {
        file_put_contents($this->root . '/big.ts', str_repeat('x', 11));

        $this->verify(['big.ts' => null], 10);

        $this->addToAssertionCount(1);
    }

    public function testAFileThatShrankUnderTheCapAfterAFailedReadFailsTheScan(): void
    {
        file_put_contents($this->root . '/big.ts', str_repeat('x', 10));

        $this->assertFails(['big.ts' => null], 'big.ts', 10);
    }

    /**
     * A link is not the file itself: the TypeScript worker records a failed
     * read under a link it refused or only probed through, so a null there
     * stays consistent while the link does.
     */
    public function testALinkIsConsistentWithAFailedRead(): void
    {
        symlink($this->root . '/node_modules/dep', $this->root . '/node_modules/alias');

        $this->verify(['node_modules/alias/index.d.ts' => null]);

        $this->addToAssertionCount(1);
    }

    /** A long-running server resolves paths across scans; a link replaced by a file is seen as the file. */
    public function testALinkReplacedByAFileIsNotResolvedFromAnEarlierVerify(): void
    {
        symlink($this->root . '/' . self::DEP, $this->root . '/alias.d.ts');
        $this->verify(['alias.d.ts' => null]);

        // Replaced by another process, as a worker or an editor would: PHP's own
        // unlink() would clear the cache for this process and hide the fault.
        $alias = escapeshellarg($this->root . '/alias.d.ts');
        exec(sprintf('rm %s && printf %%s now-a-file > %s', $alias, $alias), $output, $status);
        assertSame(0, $status);

        $this->assertFails(['alias.d.ts' => null], 'alias.d.ts');
    }

    public function testAHashThroughALinkInsideTheRootIsCompared(): void
    {
        symlink($this->root . '/node_modules/dep', $this->root . '/node_modules/alias');

        $this->verify(['node_modules/alias/index.d.ts' => $this->hashOf(self::DEP)]);

        $this->assertFails(['node_modules/alias/index.d.ts' => hash('sha256', 'other')], 'node_modules/alias/index.d.ts');
    }

    public function testALinkLeavingTheRootIsNeverRead(): void
    {
        file_put_contents($this->outside . '/secret.ts', 'secret');
        symlink($this->outside . '/secret.ts', $this->root . '/escape.ts');

        // Even the right hash of the outside file does not pass.
        $this->assertFails(['escape.ts' => hash('sha256', 'secret')], 'escape.ts');
    }

    /** @return iterable<string, array{string}> */
    public static function escapingKeys(): iterable
    {
        yield 'a parent traversal' => ['../outside.ts'];
        yield 'an absolute path' => ['/etc/passwd'];
        yield 'an empty path' => [''];
    }

    #[DataProvider('escapingKeys')]
    public function testAKeyEscapingTheRootIsRefused(string $key): void
    {
        file_put_contents($this->outside . '/outside.ts', 'x');

        $error = captureThrows(fn() => $this->verify([$key => hash('sha256', 'x')]), InvalidArgumentException::class);

        assertContains('undiscovered input ' . $key, $error->getMessage());
    }

    public function testANumericKeyIsThePathItSpells(): void
    {
        file_put_contents($this->root . '/123', 'n');

        $this->verify(json_decode('{"123": "' . hash('sha256', 'n') . '"}', true));

        $this->assertFails(json_decode('{"123": "' . hash('sha256', 'm') . '"}', true), '123');
    }

    /** @param array<array-key, string|null> $inputs */
    private function verify(array $inputs, int $maxFileBytes = 1_000_000): void
    {
        (new UndiscoveredInputVerifier())->verify($this->root, $inputs, $maxFileBytes);
    }

    /** @param array<array-key, string|null> $inputs */
    private function assertFails(array $inputs, string $path, int $maxFileBytes = 1_000_000): void
    {
        $error = captureThrows(fn() => $this->verify($inputs, $maxFileBytes), ScanSnapshotChangedException::class);

        assertSame(ScanSnapshotChangedException::inputChangedAfterRead($path)->getMessage(), $error->getMessage());
        assertContains($path . ' was read during the scan', $error->getMessage());
    }

    private function fifo(string $relativePath): void
    {
        if (!function_exists('posix_mkfifo') || !posix_mkfifo($this->root . '/' . $relativePath, 0o600)) {
            self::markTestSkipped('FIFOs are unavailable here.');
        }
    }

    private function hashOf(string $relativePath): string
    {
        return hash('sha256', (string) file_get_contents($this->root . '/' . $relativePath));
    }
}
