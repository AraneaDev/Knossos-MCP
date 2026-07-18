<?php

declare(strict_types=1);

namespace Knossos\Scan;

use InvalidArgumentException;
use Knossos\Reconciliation\ContributionCacheEntry;
use Knossos\Scanner\Protocol\{ScanContribution, ScannerManifest};
use Knossos\Scanner\Worker\ContributionDecoder;
use Throwable;

final readonly class ContributionCacheService
{
    /**
     * @param list<object> $files
     * @param array<string, array<string, mixed>> $cache
     */
    public function partition(array $files, ScannerManifest $manifest, string $configurationHash, array $cache, bool $force): ContributionPartition
    {
        $cached = [];
        $entries = [];
        $scan = [];
        $added = 0;
        $changed = 0;
        foreach ($files as $file) {
            $row = $cache[$manifest->id . "\0" . $file->relativePath] ?? null;
            $valid = !$force && $row !== null
                && $row['content_hash'] === $file->contentHash
                && $row['scanner_version'] === $manifest->version
                && $row['configuration_hash'] === $configurationHash;
            if ($valid) {
                try {
                    $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($payload)) {
                        throw new InvalidArgumentException('Cached contribution payload is invalid.');
                    }
                    $contribution = ContributionDecoder::decode($payload);
                    $cached[] = $contribution;
                    $entries[] = $this->entry($file, $manifest, $configurationHash, $contribution);
                    continue;
                } catch (Throwable) {
                    // Corrupt derived cache is safely rebuilt from source.
                }
            }
            $scan[] = $file;
            $row === null ? ++$added : ++$changed;
        }
        return new ContributionPartition($cached, $entries, $scan, $added, $changed);
    }

    /**
     * @param list<ScanContribution> $scanned
     * @param list<object> $files
     * @return array{contributions: list<ScanContribution>, cache_entries: list<ContributionCacheEntry>}
     */
    public function entriesForScanned(array $scanned, array $files, ScannerManifest $manifest, string $configurationHash): array
    {
        $byOwner = [];
        foreach ($scanned as $contribution) {
            $byOwner[$contribution->ownerKey] = $contribution;
        }
        $contributions = [];
        $entries = [];
        foreach ($files as $file) {
            $owner = $manifest->id . ':file:' . $file->relativePath;
            $contribution = $byOwner[$owner] ?? throw new InvalidArgumentException(sprintf('Scanner omitted contribution for %s.', $file->relativePath));
            $contributions[] = $contribution;
            $entries[] = $this->entry($file, $manifest, $configurationHash, $contribution);
        }
        return ['contributions' => $contributions, 'cache_entries' => $entries];
    }

    private function entry(object $file, ScannerManifest $manifest, string $configurationHash, ScanContribution $contribution): ContributionCacheEntry
    {
        return new ContributionCacheEntry($file->relativePath, $file->contentHash, $manifest->id, $manifest->version, $configurationHash, $contribution);
    }
}
