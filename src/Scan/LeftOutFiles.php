<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Protocol\{Diagnostic, Evidence, ScanContribution, ScannerManifest};
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Store\ContributionCacheEntry;

/**
 * The files one language left out of the graph in a scan, because each one's
 * own answer outgrew a size limit.
 *
 * Each keeps a fact-free contribution saying why, and is cached under
 * {@see ContributionCacheService::leftOutConfigurationHash()} so an unchanged
 * file stays out without being sent again. Kept apart from the scanned
 * contributions so a left-out file is never counted as parsed.
 */
final class LeftOutFiles
{
    /** @var array<string, ScanContribution> by relative path */
    private array $contributions = [];

    /** @var list<ContributionCacheEntry> */
    private array $entries = [];

    public function __construct(
        private readonly ContributionCacheService $cache,
        private readonly ScannerManifest $manifest,
        private readonly string $leftOutHash,
        private readonly string $analysisHash,
    ) {}

    /** Leave one file out, with the failure that its answer alone caused. */
    public function add(object $file, WorkerException $error): void
    {
        $contribution = self::contribution($this->manifest->id, $file->relativePath, $error);
        $this->contributions[$file->relativePath] = $contribution;
        $entry = $this->cache->leftOutEntry($file, $this->manifest, $this->leftOutHash, $contribution, $this->analysisHash);
        if ($entry !== null) {
            $this->entries[] = $entry;
        }
    }

    /** Whether this file was left out. */
    public function has(string $relativePath): bool
    {
        return isset($this->contributions[$relativePath]);
    }

    /**
     * The paths of the files left out, in the order they were.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->contributions);
    }

    /**
     * The fact-free contribution each left-out file keeps in the graph.
     *
     * @return list<ScanContribution>
     */
    public function contributions(): array
    {
        return array_values($this->contributions);
    }

    /**
     * The cache entries that keep an unchanged left-out file out on the next scan.
     *
     * @return list<ContributionCacheEntry>
     */
    public function cacheEntries(): array
    {
        return $this->entries;
    }

    /**
     * The contribution a file gets when its answer alone was too large to take.
     *
     * Carries only the reason, under the owner key the worker would have used,
     * so the file stays accounted for and the gap is visible in the graph.
     */
    private static function contribution(string $scannerId, string $relativePath, WorkerException $error): ScanContribution
    {
        return new ScanContribution($scannerId . ':file:' . $relativePath, [], [], [
            new Diagnostic(
                'error',
                $error->diagnosticCode,
                sprintf(
                    "Left out of the graph: the scanner's answer for %s alone was too large. %s A single file cannot be "
                    . 'split any further, so its facts are omitted and the rest of the language is kept.',
                    $relativePath,
                    $error->getMessage(),
                ),
                new Evidence($relativePath, 1, 1),
            ),
        ]);
    }
}
