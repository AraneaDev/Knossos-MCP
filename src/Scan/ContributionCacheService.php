<?php

declare(strict_types=1);

namespace Knossos\Scan;

use InvalidArgumentException;
use Knossos\Discovery\FileFingerprint;
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
    public function partition(array $files, ScannerManifest $manifest, string $configurationHash, array $cache, bool $force, ?CancellationToken $cancellation = null): ContributionPartition
    {
        $cached = [];
        $entries = [];
        $scan = [];
        $added = 0;
        $changed = 0;
        $seen = 0;
        foreach ($files as $file) {
            if ($cancellation !== null && (++$seen % 256) === 0) {
                $cancellation->throwIfCancelled();
            }
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
            // TOCTOU guard: discovery hashed these bytes before the worker read them.
            // If the file changed during that window, persisting a cache entry keyed on
            // the discovery hash but holding facts of the newer bytes poisons every
            // future incremental scan — and never self-heals if the content later
            // reverts. Re-fingerprint now: only cache the entry when the on-disk bytes
            // still match the discovery hash; otherwise keep this scan's contribution but
            // let the next scan re-scan from source.
            if ($this->contentStillMatchesDiscovery($file)) {
                $entries[] = $this->entry($file, $manifest, $configurationHash, $contribution);
            }
        }
        return ['contributions' => $contributions, 'cache_entries' => $entries];
    }

    /**
     * True when the current on-disk content of a scanned file still hashes to the
     * fingerprint recorded at discovery time. When the path/hash are unavailable
     * (non-DiscoveredFile inputs) verification is skipped and the entry is kept, to
     * preserve prior behaviour; when the file is unreadable at scan time the entry is
     * dropped rather than caching a possibly stale mapping.
     */
    private function contentStillMatchesDiscovery(object $file): bool
    {
        if (!isset($file->absolutePath, $file->contentHash) || !is_string($file->absolutePath) || !is_string($file->contentHash)) {
            return true;
        }
        $fingerprint = FileFingerprint::compute($file->absolutePath);
        if ($fingerprint === null) {
            return false;
        }

        return $fingerprint->contentHash === $file->contentHash;
    }

    private function entry(object $file, ScannerManifest $manifest, string $configurationHash, ScanContribution $contribution): ContributionCacheEntry
    {
        return new ContributionCacheEntry($file->relativePath, $file->contentHash, $manifest->id, $manifest->version, $configurationHash, $contribution);
    }
}
