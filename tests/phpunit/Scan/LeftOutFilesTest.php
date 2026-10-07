<?php

declare(strict_types=1);

namespace Knossos\Tests\Scan;

use Knossos\Scan\ContributionCacheService;
use Knossos\Scan\LeftOutFiles;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Scanner\Worker\WorkerException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** What a file left out of the graph keeps: a fact-free contribution, and a cache entry while its bytes hold. */
#[Group('scan-runner')]
final class LeftOutFilesTest extends TestCase
{
    public function testALeftOutFileKeepsAFactFreeContributionAndACacheEntryUnderTheLeftOutKey(): void
    {
        $leftOut = new LeftOutFiles(new ContributionCacheService(), self::manifest(), 'left-out:abc', 'analysis');

        $leftOut->add(self::file('dist/a.js'), new WorkerException('WORKER_FRAME_TOO_LARGE', 'Frame too large.'));
        $leftOut->add(self::file('dist/b.js'), new WorkerException('WORKER_OUTPUT_LIMIT', 'Output too large.'));

        assertSame(['dist/a.js', 'dist/b.js'], $leftOut->paths());
        assertSame(true, $leftOut->has('dist/a.js'));
        assertSame(false, $leftOut->has('dist/c.js'));
        $contribution = $leftOut->contributions()[0];
        assertSame('knossos.fake:file:dist/a.js', $contribution->ownerKey);
        assertSame([], $contribution->nodes);
        assertSame([], $contribution->edges);
        assertSame('WORKER_FRAME_TOO_LARGE', $contribution->diagnostics[0]->code);
        assertContains("the scanner's answer for dist/a.js alone was too large. Frame too large.", $contribution->diagnostics[0]->message);
        assertSame(['left-out:abc', 'left-out:abc'], array_map(static fn($entry): string => $entry->configurationHash, $leftOut->cacheEntries()));
    }

    public function testAFileWhoseBytesChangedIsLeftOutButNotCached(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'knossos-left-out-');
        file_put_contents($path, "changed since discovery\n");
        try {
            $file = self::file('dist/a.js');
            $file->absolutePath = $path;
            $leftOut = new LeftOutFiles(new ContributionCacheService(), self::manifest(), 'left-out:abc', 'analysis');

            $leftOut->add($file, new WorkerException('WORKER_FRAME_TOO_LARGE', 'Frame too large.'));

            assertSame(['dist/a.js'], $leftOut->paths());
            assertSame([], $leftOut->cacheEntries());
        } finally {
            unlink($path);
        }
    }

    private static function file(string $path): \stdClass
    {
        $file = new \stdClass();
        $file->relativePath = $path;
        $file->contentHash = 'hash-' . $path;

        return $file;
    }

    private static function manifest(): ScannerManifest
    {
        return new ScannerManifest('knossos.fake', '0.1.0', '1.0', '1.0', ['typescript'], ['ts'], ['partial_ast']);
    }
}
