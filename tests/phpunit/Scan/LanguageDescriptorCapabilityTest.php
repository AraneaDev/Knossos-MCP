<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\LanguageDescriptor;
use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\Scanner\Worker\WorkerLimits;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The core plans from its descriptors which packaged workers rebuild every file
 * on an added one, before any worker has started; each packaged worker's
 * handshake must say the same. Layout markers are the descriptors' alone.
 */
#[Group('scan')]
final class LanguageDescriptorCapabilityTest extends KnossosTestCase
{
    public function testEachPackagedWorkerDeclaresWhatItsDescriptorSaysAboutAddedFiles(): void
    {
        $checked = 0;
        foreach (LanguageDescriptor::defaults(self::repositoryRoot()) as $descriptor) {
            if (!$descriptor->isInstalled()) {
                continue;
            }
            $client = new ProcessScannerClient($descriptor->command, new WorkerLimits(requestTimeoutMs: 20_000));
            try {
                $capabilities = $client->initialize()->capabilities;
            } finally {
                $client->shutdown();
            }
            assertSame(
                $descriptor->addedFilesAffectAll,
                in_array(Protocol::CAPABILITY_ADDED_FILES_AFFECT_ALL, $capabilities, true),
                $descriptor->key,
            );
            assertSame(
                $descriptor->addedFilesAffectAll,
                isset(LanguageDescriptor::scannersWhoseAddedFilesAffectAll()[$descriptor->scannerId()]),
                $descriptor->key,
            );
            ++$checked;
        }

        self::assertGreaterThanOrEqual(3, $checked);
    }

    /**
     * Only `src/__init__.py` decides a Python source root (the src layout);
     * any other package marker is an ordinary file the worker's probes cover.
     */
    public function testOnlyPythonHasLayoutMarkersAndTheOnlyOneIsTheSrcPackageMarker(): void
    {
        $markers = LanguageDescriptor::layoutMarkersByScanner();

        assertSame(['knossos.python'], array_keys($markers));
        assertSame(1, preg_match($markers['knossos.python'], 'src/__init__.py'));
        assertSame(0, preg_match($markers['knossos.python'], 'core/__init__.py'));
        assertSame(0, preg_match($markers['knossos.python'], 'src/sub/__init__.py'));
        assertSame(0, preg_match($markers['knossos.python'], 'lib/src/__init__.py'));
        assertSame(0, preg_match($markers['knossos.python'], '__init__.py'));
        assertSame(0, preg_match($markers['knossos.python'], 'src/__init__.pyi'));
    }

    public function testAdjustingTheMemoryCapKeepsTheLayoutMarkers(): void
    {
        $descriptor = new LanguageDescriptor('x', ['x'], ['x'], 'scanner_x', workerMemoryMb: 64, layoutMarkers: '#\Ax\z#');

        assertSame('#\Ax\z#', $descriptor->withMemoryMb(128)->layoutMarkers);
    }

    /**
     * Only Rust reads directly: the Python and TypeScript workers name a
     * module and rely on its own reads for what it re-exports, so a rebuilt
     * module must still reach its importers.
     */
    public function testOnlyRustReadsDirectlyAndAdjustingTheMemoryCapKeepsIt(): void
    {
        assertSame(['knossos.rust' => true], LanguageDescriptor::scannersWithDirectReads());
        $descriptor = new LanguageDescriptor('x', ['x'], ['x'], 'scanner_x', workerMemoryMb: 64, directReads: true);

        assertSame(true, $descriptor->withMemoryMb(128)->directReads);
    }
}
