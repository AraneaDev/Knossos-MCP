<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ContributionCacheService;
use Knossos\Scan\ContributionPartition;
use Knossos\Scan\ScanCancelledException;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('contribution-cache')]
final class ContributionCacheServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/knossos-ccs-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function manifest(): ScannerManifest
    {
        return new ScannerManifest('knossos.php', '0.1.0', '1', '1', ['php'], ['php'], ['scan']);
    }

    private function writeFile(string $name, string $contents): DiscoveredFile
    {
        $absolute = $this->dir . '/' . $name;
        file_put_contents($absolute, $contents);
        $hash = hash('sha256', $contents);

        return new DiscoveredFile($name, $absolute, 'php', strlen($contents), 0, $hash);
    }

    public function testEntriesForScannedCachesWhenDiskContentStillMatches(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $file = $this->writeFile('Foo.php', "<?php // stable\n");
        $contribution = new ScanContribution('knossos.php:file:Foo.php');

        $result = $service->entriesForScanned([$contribution], [$file], $manifest, 'cfg');

        assertSame(1, count($result['contributions']));
        assertSame(1, count($result['cache_entries']));
    }

    public function testEntriesForScannedDropsCacheEntryWhenContentChangedDuringScan(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $file = $this->writeFile('Foo.php', "<?php // original\n");
        // Simulate the TOCTOU window: the worker read (and this contribution reflects)
        // bytes that no longer match the discovery-time hash carried on $file.
        file_put_contents($file->absolutePath, "<?php // mutated after discovery\n");
        $contribution = new ScanContribution('knossos.php:file:Foo.php');

        $result = $service->entriesForScanned([$contribution], [$file], $manifest, 'cfg');

        // The contribution is still returned for this scan's graph,
        // but no poisoned cache entry is persisted.
        assertSame(1, count($result['contributions']));
        assertSame(0, count($result['cache_entries']));
    }

    public function testEntriesForScannedDropsCacheEntryWhenFileUnreadableAtScanTime(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $file = $this->writeFile('Gone.php', "<?php\n");
        unlink($file->absolutePath); // vanished before the scan-time re-fingerprint
        $contribution = new ScanContribution('knossos.php:file:Gone.php');

        $result = $service->entriesForScanned([$contribution], [$file], $manifest, 'cfg');

        assertSame(1, count($result['contributions']));
        assertSame(0, count($result['cache_entries']));
    }

    public function testPartitionObservesCancellation(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $files = [];
        for ($i = 0; $i < 300; ++$i) {
            $file = new \stdClass();
            $file->relativePath = "src/File{$i}.php";
            $file->contentHash = 'h' . $i;
            $files[] = $file;
        }
        $token = new CancellationToken();
        $token->cancel();

        $this->expectException(ScanCancelledException::class);
        $service->partition($files, $manifest, 'cfg', [], false, $token);
    }

    public function testPartitionWithoutTokenReturnsPartition(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $file = new \stdClass();
        $file->relativePath = 'src/Only.php';
        $file->contentHash = 'abc';

        $partition = $service->partition([$file], $manifest, 'cfg', [], false);

        assertSame(true, $partition instanceof ContributionPartition);
        assertSame(1, $partition->added);
    }

    public function testPartitionRebuildsFromSourceWhenCachedPayloadIsNotAnArray(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $file = new \stdClass();
        $file->relativePath = 'src/Bad.php';
        $file->contentHash = 'hash1';
        $cache = [
            $manifest->id . "\0src/Bad.php" => [
                'content_hash' => 'hash1',
                'scanner_version' => $manifest->version,
                'configuration_hash' => 'cfg',
                // Valid JSON but decodes to a scalar, not an array -> treated as corrupt cache.
                'payload_json' => '5',
            ],
        ];

        $partition = $service->partition([$file], $manifest, 'cfg', $cache, false);

        assertSame(0, count($partition->cached));
        assertSame([$file], $partition->filesToScan);
        assertSame(1, $partition->changed);
    }

    public function testEntriesForScannedKeepsCacheEntryWhenFileLacksAbsolutePath(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        // Non-DiscoveredFile input (no absolutePath/contentHash pair available for
        // re-fingerprinting) — verification is skipped and the entry is kept.
        $file = new \stdClass();
        $file->relativePath = 'src/NoAbsolutePath.php';
        $file->contentHash = 'abc123';
        $contribution = new ScanContribution($manifest->id . ':file:src/NoAbsolutePath.php');

        $result = $service->entriesForScanned([$contribution], [$file], $manifest, 'cfg');

        assertSame(1, count($result['contributions']));
        assertSame(1, count($result['cache_entries']));
    }
}
