<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Scan\ScanInputHashes;
use Knossos\Scan\ScanSnapshotChangedException;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Scanner\Worker\WorkerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The result-level hashes of files a worker read for another file's sake,
 * checked against what discovery hashed.
 */
#[Group('scan')]
final class ScanInputHashesTest extends TestCase
{
    private const OTHER = "export const other = 1;\n";

    public function testADeclaringWorkerThatSendsNoInputHashesIsRefused(): void
    {
        $error = captureThrows(fn() => ScanInputHashes::verify(['count' => 1], $this->declaring(), $this->discovery()), WorkerException::class);

        assertSame('WORKER_RESPONSE_INVALID', $error->diagnosticCode);
        assertContains('knossos.fake', $error->getMessage());
        assertContains('input_hashes', $error->getMessage());
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function malformedInputHashes(): iterable
    {
        yield 'a list rather than an object' => [[hash('sha256', 'x')], 'must be an object'];
        yield 'a string rather than an object' => ['src/Other.ts', 'must be an object'];
        yield 'an empty key' => [['' => hash('sha256', 'x')], 'project-relative path'];
        yield 'a list, which is also what an object keyed only "0" decodes to' => [[0 => hash('sha256', 'x')], 'must be an object'];
        yield 'an absolute path' => [['/etc/passwd' => hash('sha256', 'x')], 'must not be absolute'];
        yield 'a parent traversal' => [['src/../../secret.ts' => hash('sha256', 'x')], 'invalid path segment'];
        yield 'an uppercase hash' => [['src/Other.ts' => strtoupper(hash('sha256', 'x'))], 'lowercase SHA-256'];
        yield 'a short hash' => [['src/Other.ts' => 'abc123'], 'lowercase SHA-256'];
        yield 'a hash with a trailing newline' => [['src/Other.ts' => hash('sha256', 'x') . "\n"], 'lowercase SHA-256'];
        yield 'a number' => [['src/Other.ts' => 42], 'lowercase SHA-256'];
        yield 'false' => [['src/Other.ts' => false], 'lowercase SHA-256'];
    }

    #[DataProvider('malformedInputHashes')]
    public function testMalformedInputHashesAreRefused(mixed $inputHashes, string $phrase): void
    {
        $error = captureThrows(
            fn() => ScanInputHashes::verify(['input_hashes' => $inputHashes], $this->declaring(), $this->discovery()),
            WorkerException::class,
        );

        assertSame('WORKER_RESPONSE_INVALID', $error->diagnosticCode);
        assertContains('knossos.fake', $error->getMessage());
        assertContains('input_hashes', $error->getMessage());
        assertContains($phrase, $error->getMessage());
    }

    public function testMalformedInputHashesAreRefusedFromAWorkerThatDidNotDeclareThem(): void
    {
        $error = captureThrows(
            fn() => ScanInputHashes::verify(['input_hashes' => ['/etc/passwd' => hash('sha256', 'x')]], $this->plain(), $this->discovery()),
            WorkerException::class,
        );

        assertSame('WORKER_RESPONSE_INVALID', $error->diagnosticCode);
    }

    public function testTheWholeMapIsValidatedBeforeAnyHashIsCompared(): void
    {
        // A response is judged well-formed before any of it is believed.
        $error = captureThrows(
            fn() => ScanInputHashes::verify(
                ['input_hashes' => ['src/Other.ts' => hash('sha256', 'swapped'), '/etc/passwd' => hash('sha256', 'x')]],
                $this->declaring(),
                $this->discovery(),
            ),
            \Throwable::class,
        );

        assertSame(WorkerException::class, $error::class);
    }

    public function testAnEmptyObjectMeansNothingElseWasRead(): void
    {
        // `{}` decodes to an empty PHP array, which is also what an empty list
        // decodes to; both mean the same thing, so both are accepted.
        ScanInputHashes::verify(['input_hashes' => []], $this->declaring(), $this->discovery());

        $this->addToAssertionCount(1);
    }

    public function testAMatchingHashForADiscoveredFilePasses(): void
    {
        ScanInputHashes::verify(['input_hashes' => ['src/Other.ts' => hash('sha256', self::OTHER)]], $this->declaring(), $this->discovery());

        $this->addToAssertionCount(1);
    }

    public function testADifferentHashForADiscoveredFileFailsTheScan(): void
    {
        $error = captureThrows(
            fn() => ScanInputHashes::verify(['input_hashes' => ['src/Other.ts' => hash('sha256', 'swapped')]], $this->declaring(), $this->discovery()),
            ScanSnapshotChangedException::class,
        );

        assertContains('src/Other.ts', $error->getMessage());
        assertContains('was read from different content than the scan hashed', $error->getMessage());
    }

    public function testAFailedReadOfADiscoveredFileFailsTheScan(): void
    {
        $error = captureThrows(
            fn() => ScanInputHashes::verify(['input_hashes' => ['src/Other.ts' => null]], $this->declaring(), $this->discovery()),
            ScanSnapshotChangedException::class,
        );

        assertContains('src/Other.ts', $error->getMessage());
        assertContains('could not be read while the scan derived graph facts from it', $error->getMessage());
    }

    public function testAPathDiscoveryNeverHashedIsIgnored(): void
    {
        ScanInputHashes::verify(
            ['input_hashes' => ['node_modules/dep/index.d.ts' => hash('sha256', 'x'), 'vendor/lib.py' => null]],
            $this->declaring(),
            $this->discovery(),
        );

        $this->addToAssertionCount(1);
    }

    public function testAWorkerThatDidNotDeclareTheCapabilityNeedNotSendIt(): void
    {
        ScanInputHashes::verify(['count' => 1], $this->plain(), $this->discovery());

        $this->addToAssertionCount(1);
    }

    public function testAHashFromAWorkerThatDidNotDeclareTheCapabilityIsStillChecked(): void
    {
        $error = captureThrows(
            fn() => ScanInputHashes::verify(['input_hashes' => ['src/Other.ts' => hash('sha256', 'swapped')]], $this->plain(), $this->discovery()),
            ScanSnapshotChangedException::class,
        );

        assertContains('was read from different content than the scan hashed', $error->getMessage());
    }

    public function testAReportedHashForAFileDiscoveryRecordedNoHashForIsRefused(): void
    {
        $discovery = ['src/Other.ts' => (object) ['relativePath' => 'src/Other.ts', 'contentHash' => null]];

        $error = captureThrows(
            fn() => ScanInputHashes::verify(['input_hashes' => ['src/Other.ts' => hash('sha256', self::OTHER)]], $this->declaring(), $discovery),
            WorkerException::class,
        );

        assertSame('WORKER_RESPONSE_INVALID', $error->diagnosticCode);
        assertContains('src/Other.ts', $error->getMessage());
    }

    public function testAFailedReadFailsTheScanEvenWithoutADiscoveryHashToCompare(): void
    {
        // A null claims no bytes, so there is nothing to verify: the failed
        // read of a discovered file is the fault on its own.
        $discovery = ['src/Other.ts' => (object) ['relativePath' => 'src/Other.ts']];

        $error = captureThrows(
            fn() => ScanInputHashes::verify(['input_hashes' => ['src/Other.ts' => null]], $this->declaring(), $discovery),
            ScanSnapshotChangedException::class,
        );

        assertContains('could not be read while the scan derived graph facts from it', $error->getMessage());
    }

    public function testANumericPathDecodedToAnIntKeyIsStillThatPath(): void
    {
        // json_decode turns {"123": "..."} into [123 => "..."].
        $discovery = self::numericDiscovery('123');
        $decoded = json_decode('{"input_hashes": {"123": "' . hash('sha256', 'swapped') . '"}}', true);

        $error = captureThrows(fn() => ScanInputHashes::verify($decoded, $this->declaring(), $discovery), ScanSnapshotChangedException::class);

        assertContains('123 was read from different content', $error->getMessage());
    }

    public function testAMatchingNumericPathPasses(): void
    {
        $discovery = self::numericDiscovery('123');
        $decoded = json_decode('{"input_hashes": {"123": "' . hash('sha256', self::OTHER) . '", "src/x.ts": null}}', true);

        ScanInputHashes::verify($decoded, $this->declaring(), $discovery);

        $this->addToAssertionCount(1);
    }

    public function testAFailedReadOfANumericPathNamesIt(): void
    {
        $discovery = self::numericDiscovery('7');
        $decoded = json_decode('{"input_hashes": {"src/x.ts": null, "7": null}}', true);

        $error = captureThrows(fn() => ScanInputHashes::verify($decoded, $this->declaring(), $discovery), ScanSnapshotChangedException::class);

        assertContains('7 could not be read', $error->getMessage());
    }

    public function testAnObjectKeyedOnlyByZeroIsRefusedBecauseItDecodesToAList(): void
    {
        // The documented limitation: indistinguishable from a JSON array.
        $decoded = json_decode('{"input_hashes": {"0": "' . hash('sha256', self::OTHER) . '"}}', true);

        $error = captureThrows(fn() => ScanInputHashes::verify($decoded, $this->declaring(), self::numericDiscovery('0')), WorkerException::class);

        assertSame('WORKER_RESPONSE_INVALID', $error->diagnosticCode);
        assertContains('must be an object', $error->getMessage());
    }

    /** @return array<string, DiscoveredFile> */
    private static function numericDiscovery(string $path): array
    {
        return [$path => new DiscoveredFile($path, '/nonexistent/' . $path, 'typescript', strlen(self::OTHER), 0, hash('sha256', self::OTHER))];
    }

    /** @return array<string, DiscoveredFile> */
    private function discovery(): array
    {
        return ['src/Other.ts' => new DiscoveredFile('src/Other.ts', '/nonexistent/src/Other.ts', 'typescript', strlen(self::OTHER), 0, hash('sha256', self::OTHER))];
    }

    private function declaring(): ScannerManifest
    {
        return new ScannerManifest('knossos.fake', '0.1.0', '1', '1', ['typescript'], ['ts'], ['scan', 'content_hash', 'input_hashes']);
    }

    private function plain(): ScannerManifest
    {
        return new ScannerManifest('knossos.fake', '0.1.0', '1', '1', ['typescript'], ['ts'], ['scan']);
    }
}
