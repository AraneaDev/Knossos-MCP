<?php

declare(strict_types=1);

namespace Knossos\Scan;

use InvalidArgumentException;
use Knossos\Discovery\FileFingerprint;
use Knossos\Reconciliation\ContributionCacheEntry;
use Knossos\Scanner\Protocol\{Diagnostic, Evidence, Protocol, ScanContribution, ScannerManifest};
use Knossos\Scanner\Worker\ContributionDecoder;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Scanner\Worker\WorkerLimits;
use PDO;
use Throwable;

/**
 * Decides which files an incremental scan can reuse instead of re-analysing.
 *
 * Reuse is keyed on the file's fingerprint *and* the analyzer configuration hash,
 * so changing analyzer behaviour invalidates the cache rather than silently
 * serving facts the current code would no longer produce.
 */
final readonly class ContributionCacheService
{
    /**
     * The cache key a left-out file is stored under: the language's
     * configuration plus the size limits that left it out.
     *
     * A file whose answer outgrew a limit outgrows it again while its bytes,
     * the configuration and those limits stay the same, so re-sending it only
     * pays the batch splitting again to reach the same result. Any change to
     * them makes the key miss and the file is scanned afresh. The timeout is
     * left out of the key because it has nothing to do with size.
     */
    public static function leftOutConfigurationHash(string $configurationHash, WorkerLimits $limits): string
    {
        return 'left-out:' . hash('sha256', implode("\0", [$configurationHash, $limits->maxLineBytes, $limits->maxOutputBytes]));
    }

    /**
     * The version a cached contribution is stored and compared under: the
     * worker's reported version plus a prefix of the hash of its own files, so
     * an edit to the worker invalidates what it produced without anyone
     * remembering to bump a number.
     */
    public static function cacheVersion(ScannerManifest $manifest, string $analysisHash): string
    {
        return $manifest->version . '+' . substr($analysisHash, 0, 16);
    }

    /**
     * Split the discovered files into reusable and must-scan sets.
     *
     * The cache rows are metadata only. The payloads of the rows that pass
     * every check are fetched afterwards, so an invalidated or stale row is
     * never decoded. A reused entry carries the reads it was stored with and is
     * marked as coming from the cache, so the writer keeps its stored row.
     *
     * @param list<object> $files
     * @param array<string, array<string, mixed>> $cache metadata rows keyed by scanner id and path;
     *        a row may carry `payload_json` itself, which is used when no PDO is given
     * @param ?string $leftOutConfigurationHash see {@see self::leftOutConfigurationHash()}; a row
     *        stored under it is reused too, and counted as left out
     * @param string $analysisHash see {@see AnalysisHash}
     * @param array<string, true> $invalidatedOwners owners a change reached ({@see ReadSetInvalidator}), counted as changed
     * @param ?PDO $pdo where the reused rows' payloads are read from, with `$projectId`
     * @param ?CachedReads $cachedReads the stored reads a reused entry carries over
     */
    public function partition(
        array $files,
        ScannerManifest $manifest,
        string $configurationHash,
        array $cache,
        bool $force,
        string $analysisHash,
        ?CancellationToken $cancellation = null,
        ?string $leftOutConfigurationHash = null,
        array $invalidatedOwners = [],
        ?PDO $pdo = null,
        ?string $projectId = null,
        ?CachedReads $cachedReads = null,
    ): ContributionPartition {
        $cacheVersion = self::cacheVersion($manifest, $analysisHash);
        /** @var list<array{file: object, row: ?array<string, mixed>, owner: string, valid: bool}> $decisions */
        $decisions = [];
        $reusedOwners = [];
        $sinceLastPoll = 0;
        foreach ($files as $file) {
            // Once per 256 files. A counter that restarts, rather than a
            // modulo, so a counter running the wrong way never reaches it.
            if ($cancellation !== null && ++$sinceLastPoll === 256) {
                $sinceLastPoll = 0;
                $cancellation->throwIfCancelled();
            }
            $row = $cache[$manifest->id . "\0" . $file->relativePath] ?? null;
            $owner = (string) ($row['owner_key'] ?? $manifest->id . ':file:' . $file->relativePath);
            $valid = !$force && $row !== null
                && !isset($invalidatedOwners[$owner])
                && $row['content_hash'] === $file->contentHash
                && $row['scanner_version'] === $cacheVersion
                && ($row['configuration_hash'] === $configurationHash
                    || ($leftOutConfigurationHash !== null && $row['configuration_hash'] === $leftOutConfigurationHash));
            if ($valid) {
                $reusedOwners[] = $owner;
            }
            $decisions[] = ['file' => $file, 'row' => $row, 'owner' => $owner, 'valid' => $valid];
        }
        $payloads = $pdo !== null && $projectId !== null ? self::payloads($pdo, $projectId, $reusedOwners) : [];

        $cached = [];
        $entries = [];
        $scan = [];
        $added = 0;
        $changed = 0;
        $leftOutPaths = [];
        foreach ($decisions as ['file' => $file, 'row' => $row, 'owner' => $owner, 'valid' => $valid]) {
            if ($valid && $row !== null) {
                try {
                    $payload = json_decode((string) ($payloads[$owner] ?? $row['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($payload)) {
                        throw new InvalidArgumentException('Cached contribution payload is invalid.');
                    }
                    $contribution = ContributionDecoder::decode($payload);
                    $cached[] = $contribution;
                    $entries[] = $this->reusedEntry($file, $manifest, $row, $contribution, $cachedReads);
                    if ($row['configuration_hash'] === $leftOutConfigurationHash) {
                        $leftOutPaths[] = $file->relativePath;
                    }
                    continue;
                } catch (Throwable) {
                    // Corrupt derived cache is safely rebuilt from source.
                }
            }
            $scan[] = $file;
            $row === null ? ++$added : ++$changed;
        }
        return new ContributionPartition($cached, $entries, $scan, $added, $changed, $leftOutPaths);
    }

    /**
     * The stored payloads of the given owners, read in chunks so no statement
     * exceeds SQLite's bound-parameter limit.
     *
     * @param list<string> $owners
     * @return array<string, string> owner key to payload JSON
     */
    private static function payloads(PDO $pdo, string $projectId, array $owners): array
    {
        $payloads = [];
        foreach (array_chunk($owners, 500) as $chunk) {
            $statement = $pdo->prepare(sprintf(
                'SELECT owner_key, payload_json FROM contribution_cache WHERE project_id = ? AND owner_key IN (%s)',
                implode(', ', array_fill(0, count($chunk), '?')),
            ));
            $statement->execute([$projectId, ...$chunk]);
            foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$owner, $payload]) {
                $payloads[(string) $owner] = (string) $payload;
            }
        }

        return $payloads;
    }

    /**
     * The entry for a contribution carried over from the cache, with the reads
     * it was stored with.
     *
     * @param array<string, mixed> $row
     */
    private function reusedEntry(object $file, ScannerManifest $manifest, array $row, ScanContribution $contribution, ?CachedReads $cachedReads): ContributionCacheEntry
    {
        $owner = $contribution->ownerKey;
        $stored = $cachedReads?->rows[$owner] ?? null;

        return new ContributionCacheEntry(
            $file->relativePath,
            $file->contentHash,
            $manifest->id,
            (string) $row['scanner_version'],
            (string) $row['configuration_hash'],
            $contribution,
            $cachedReads?->ownerReads[$owner] ?? [],
            $stored['read_group'] ?? (isset($row['read_group']) ? (string) $row['read_group'] : null),
            $stored['read_attribution'] ?? ((int) ($row['read_attribution'] ?? 0) === 1),
            true,
        );
    }

    /**
     * The cache entry for a file left out of the graph, or null when its bytes
     * no longer match what discovery hashed and the next scan must look again.
     */
    public function leftOutEntry(object $file, ScannerManifest $manifest, string $leftOutConfigurationHash, ScanContribution $contribution, string $analysisHash): ?ContributionCacheEntry
    {
        if (!$this->contentStillMatchesDiscovery($file)) {
            return null;
        }

        // Its contribution is a diagnostic about its own bytes and nothing
        // else, so it read nothing a change elsewhere could reach.
        return $this->entry($file, $manifest, $leftOutConfigurationHash, $contribution, self::cacheVersion($manifest, $analysisHash), ['reads' => [], 'group' => null, 'attributed' => true]);
    }

    /**
     * Cache entries for the files this scan analysed.
     *
     * @param list<ScanContribution> $scanned
     * @param list<object> $files
     * @param array<string, array{reads: array<string, ?string>, group: ?string, attributed: bool}> $readsByOwner
     *        what each contribution read ({@see RequestReads}); an owner missing here is stored with no reads and as unattributed
     * @return array{contributions: list<ScanContribution>, cache_entries: list<ContributionCacheEntry>}
     */
    public function entriesForScanned(array $scanned, array $files, ScannerManifest $manifest, string $configurationHash, string $analysisHash, array $readsByOwner = []): array
    {
        $cacheVersion = self::cacheVersion($manifest, $analysisHash);
        $byOwner = [];
        $duplicated = [];
        foreach ($scanned as $contribution) {
            // Last write wins, which is what this index did silently before:
            // a worker that answered twice under one owner key had the earlier
            // answer's nodes and edges dropped with nothing recording it.
            if (isset($byOwner[$contribution->ownerKey])) {
                $duplicated[$contribution->ownerKey] = true;
            }
            $byOwner[$contribution->ownerKey] = $contribution;
        }
        $contributions = [];
        $entries = [];
        $requested = [];
        foreach ($files as $file) {
            $requested[$manifest->id . ':file:' . $file->relativePath] = true;
        }
        // Owner keys the worker answered under that nobody asked for. Their
        // nodes and edges are unusable — nothing maps them to a scanned file.
        // When they explain why a requested file has no contribution they are
        // named in the diagnostic that file gets; when every requested file
        // *was* answered they used to be discarded in silence, which is the
        // case self::misattributionContribution() below exists for.
        $unexpected = array_keys(array_diff_key($byOwner, $requested));
        $omitted = false;
        foreach ($files as $file) {
            $owner = $manifest->id . ':file:' . $file->relativePath;
            $contribution = $byOwner[$owner] ?? null;
            if ($contribution === null) {
                // A worker that skipped a file it was asked for. Synthesise the
                // contribution it owed so the file is accounted for and the gap
                // is visible, rather than failing a scan whose other files have
                // already produced facts. Deliberately not cached below: the
                // next scan must retry this file from source.
                $contributions[] = self::omittedContribution($owner, $file->relativePath, $unexpected);
                $omitted = true;
                continue;
            }
            // Before anything is kept or cached: facts parsed from bytes other
            // than the ones discovery hashed must never reach the graph.
            $cacheable = self::parsedContentIsCacheable($contribution, $file, $manifest);
            if (isset($duplicated[$owner])) {
                // Only the last answer survived the index above, so the facts
                // of every earlier one are already gone and nothing here can
                // tell which answer was the intended one. Report it against
                // the file and leave the entry uncached, so the next scan
                // rebuilds this file from source rather than persisting a
                // choice made by arrival order.
                $contributions[] = self::duplicatedContribution($contribution, $file->relativePath);
                continue;
            }
            $contributions[] = $contribution;
            // TOCTOU guard: discovery hashed these bytes before the worker read them.
            // If the file changed during that window, persisting a cache entry keyed on
            // the discovery hash but holding facts of the newer bytes poisons every
            // future incremental scan — and never self-heals if the content later
            // reverts. Re-fingerprint now: only cache the entry when the on-disk bytes
            // still match the discovery hash; otherwise keep this scan's contribution but
            // let the next scan re-scan from source.
            if ($cacheable && $this->contentStillMatchesDiscovery($file)) {
                $entries[] = $this->entry($file, $manifest, $configurationHash, $contribution, $cacheVersion, $readsByOwner[$owner] ?? null);
            }
        }
        if ($unexpected !== [] && !$omitted) {
            $contributions[] = self::misattributionContribution($manifest, $unexpected);
        }
        return ['contributions' => $contributions, 'cache_entries' => $entries];
    }

    /**
     * True when the current on-disk content of a scanned file still hashes to the
     * fingerprint recorded at discovery time. A file with no string discovery hash
     * has nothing a cache entry could be keyed on, so it is never cached. When only
     * the path is unavailable (non-DiscoveredFile inputs) the re-read is skipped and
     * the entry is kept, to preserve prior behaviour; when the file is unreadable at
     * scan time the entry is dropped rather than caching a possibly stale mapping.
     */
    private function contentStillMatchesDiscovery(object $file): bool
    {
        if (!is_string($file->contentHash ?? null)) {
            return false;
        }
        if (!is_string($file->absolutePath ?? null)) {
            return true;
        }
        $fingerprint = FileFingerprint::compute($file->absolutePath);
        if ($fingerprint === null) {
            return false;
        }

        return $fingerprint->contentHash === $file->contentHash;
    }

    /**
     * Check a worker's reported parsed-content hash against discovery, and say
     * whether the contribution may be cached.
     *
     * A present hash is compared whatever the manifest declares, since a
     * mismatch is evidence of a changed tree whoever reports it. Without one, a
     * contribution carrying no facts is a worker's report on a file it could not
     * read, so there were no bytes to hash; it is kept, but a worker that does
     * hash is not allowed to have that empty answer cached. Facts without a hash
     * from a worker that declared it would hash are a defect in that worker.
     *
     * A reported hash for a file discovery recorded no hash for cannot be
     * verified, and treating it as verified would let facts from any bytes
     * through as fresh. It is refused outright rather than kept uncached: kept,
     * the facts would still reach the graph unverified. Refused as an invalid
     * contribution, like {@see ScanInputHashes} refuses an unverifiable read, so
     * the language degrades with a code that says why rather than as a bare
     * WORKER_FAILED.
     *
     * @throws ScanSnapshotChangedException when the hash differs from discovery
     * @throws WorkerException when a declaring worker sent facts without a hash, or a hash there is no discovery hash to compare with
     */
    private static function parsedContentIsCacheable(ScanContribution $contribution, object $file, ScannerManifest $manifest): bool
    {
        $declared = in_array(Protocol::CAPABILITY_CONTENT_HASH, $manifest->capabilities, true);
        if ($contribution->contentHash !== null) {
            $expected = $file->contentHash ?? null;
            if (!is_string($expected)) {
                throw new WorkerException('WORKER_CONTRIBUTION_INVALID', sprintf(
                    '%s reported a content hash for %s, but discovery recorded no hash for it, so the hash cannot be verified.',
                    $manifest->id,
                    $file->relativePath,
                ));
            }
            if (!hash_equals($expected, $contribution->contentHash)) {
                throw ScanSnapshotChangedException::parsedDifferently($file->relativePath);
            }

            return true;
        }
        if ($contribution->nodes === [] && $contribution->edges === []) {
            return !$declared;
        }
        if ($declared) {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', sprintf(
                '%s declares the %s capability but reported no content hash for %s.',
                $manifest->id,
                Protocol::CAPABILITY_CONTENT_HASH,
                $file->relativePath,
            ));
        }

        return true;
    }

    /**
     * One cache entry for a scanned file.
     *
     * @param ?array{reads: array<string, ?string>, group: ?string, attributed: bool} $reads
     */
    private function entry(object $file, ScannerManifest $manifest, string $configurationHash, ScanContribution $contribution, string $cacheVersion, ?array $reads = null): ContributionCacheEntry
    {
        return new ContributionCacheEntry(
            $file->relativePath,
            $file->contentHash,
            $manifest->id,
            $cacheVersion,
            $configurationHash,
            $contribution,
            $reads['reads'] ?? [],
            $reads['group'] ?? null,
            $reads['attributed'] ?? false,
        );
    }

    /**
     * The contribution a worker owed for a file it never reported on, carrying
     * only the reason. Never cached, so the next scan re-analyses the file.
     *
     * Two different failures arrive here and they are not equally serious. A
     * worker that simply skipped a file left nothing behind; a worker that
     * answered under an owner key nobody asked for produced real nodes and
     * edges that then had to be thrown away, because nothing maps them to a
     * scanned file. The second is a bug in the worker's own bookkeeping rather
     * than a gap in coverage, and reporting it under the same code as the
     * first hid it behind the noisier, more ordinary failure. It gets its own.
     *
     * @param list<string> $unexpectedOwners owner keys the worker answered under
     *     that were never requested; empty when the file was simply skipped
     */
    private static function omittedContribution(string $owner, string $relativePath, array $unexpectedOwners = []): ScanContribution
    {
        $message = sprintf('The scanner returned no contribution for %s; the file contributed no facts.', $relativePath);
        if ($unexpectedOwners !== []) {
            $message = sprintf(
                'The scanner returned no contribution for %s and answered under %d owner key(s) that were never requested (%s); those facts were discarded.',
                $relativePath,
                count($unexpectedOwners),
                implode(', ', array_slice($unexpectedOwners, 0, 3)),
            );
        }

        return new ScanContribution($owner, [], [], [
            new Diagnostic(
                'error',
                $unexpectedOwners === [] ? 'SCANNER_OMITTED_CONTRIBUTION' : 'SCANNER_MISATTRIBUTED_CONTRIBUTION',
                $message,
                new Evidence($relativePath, 1, 1),
            ),
        ]);
    }

    /**
     * The file's surviving contribution, carrying the record that it was not
     * the only answer for that owner key.
     *
     * The facts kept here are whichever answer arrived last. That is not a
     * decision this service is in a position to make correctly, so it is
     * recorded rather than hidden, and {@see self::entriesForScanned()}
     * declines to cache it.
     */
    private static function duplicatedContribution(ScanContribution $contribution, string $relativePath): ScanContribution
    {
        return new ScanContribution($contribution->ownerKey, $contribution->nodes, $contribution->edges, [
            ...$contribution->diagnostics,
            new Diagnostic(
                'error',
                'SCANNER_DUPLICATE_CONTRIBUTION',
                sprintf(
                    'The scanner returned more than one contribution for %s; only the last was kept and the file was not cached.',
                    $relativePath,
                ),
                new Evidence($relativePath, 1, 1),
            ),
        ], $contribution->contentHash);
    }

    /**
     * A scan-level record that the worker answered under owner keys nobody
     * asked for, used when every requested file *was* answered.
     *
     * There is no file to hang this on — that is exactly the problem being
     * reported — so it carries no evidence and is owned by the scanner rather
     * than by a path. Without it, a worker that returns every requested
     * contribution plus a stray one has the stray one's nodes and edges
     * dropped with nothing anywhere saying so.
     *
     * @param list<string> $unexpectedOwners
     */
    private static function misattributionContribution(ScannerManifest $manifest, array $unexpectedOwners): ScanContribution
    {
        return new ScanContribution($manifest->id . ':scan', [], [], [
            new Diagnostic(
                'error',
                'SCANNER_MISATTRIBUTED_CONTRIBUTION',
                sprintf(
                    'The scanner answered under %d owner key(s) that were never requested (%s); those facts were discarded.',
                    count($unexpectedOwners),
                    implode(', ', array_slice($unexpectedOwners, 0, 3)),
                ),
            ),
        ]);
    }
}
