<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\{RelativePath, ScanContribution};

/**
 * One file's cached contribution, keyed by fingerprint and analyzer hash so stale reuse is impossible.
 *
 * `$reads` are the files this contribution's facts were derived from, each a
 * SHA-256 or null for a path probed and not found. `$readGroup` names a set
 * shared by every file of the same worker request, `$readAttribution` says the
 * worker itself reported the per-file reads, and `$fromCache` marks an entry
 * carried over unchanged so the writer leaves its stored row alone.
 * `$readsIncomplete` marks an attributed entry whose reads cannot cover what
 * the file stands for: a file left out of the graph, or one whose worker
 * failed on it after naming only part of what it read (`reads_partial`). A reader of such a file names the file
 * instead of what it re-exports, so the entry is rebuilt on any change its
 * scanner sees ({@see \Knossos\Scan\ReadSetInvalidator}).
 */
final readonly class ContributionCacheEntry
{
    /** @param array<string, ?string> $reads path to SHA-256 hex, or null for a path probed and not found */
    public function __construct(
        public string $filePath,
        public string $contentHash,
        public string $scannerId,
        public string $scannerVersion,
        public string $configurationHash,
        public ScanContribution $contribution,
        public array $reads = [],
        public ?string $readGroup = null,
        public bool $readAttribution = false,
        public bool $fromCache = false,
        public bool $readsIncomplete = false,
    ) {
        foreach ([$filePath, $contentHash, $scannerId, $scannerVersion, $configurationHash] as $value) {
            if ($value === '') {
                throw new InvalidArgumentException('Contribution cache metadata must not be empty.');
            }
        }
        foreach ($reads as $path => $hash) {
            RelativePath::assertValid((string) $path, 'read path');
            if ($hash !== null && preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
                throw new InvalidArgumentException('A read hash must be lowercase SHA-256 hex or null.');
            }
        }
    }
}
