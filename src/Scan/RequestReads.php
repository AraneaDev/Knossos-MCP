<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Scanner\Worker\ReadsMap;
use Knossos\Scanner\Worker\WorkerException;

/**
 * What each contribution of one worker request read, split into the reads a
 * single file owns and the reads every file of the request shares.
 *
 * A worker that declares `read_attribution` says which files each contribution
 * came from, so only a change to one of those invalidates it. A worker that does
 * not leaves the core to assume every file depended on everything the request
 * read, which is its whole verified `input_hashes` map, so the shared set is
 * that map. Shared sets are content-addressed: files of one request point at one
 * group instead of each carrying a copy.
 */
final class RequestReads
{
    private function __construct() {}

    /**
     * Resolve the reads of every contribution in one request.
     *
     * @param array<string, mixed> $result the request's final result
     * @param array<string, string|null> $verifiedInputs the request's whole verified `input_hashes` map
     * @param list<ScanContribution> $contributions
     * @param list<string> $requested the paths the request named, whose own reads need no attribution
     * @return array{groups: array<string, array<string, ?string>>, owners: array<string, array{reads: array<string, ?string>, group: ?string, attributed: bool}>}
     * @throws WorkerException WORKER_CONTRIBUTION_INVALID when an attributing worker omits `reads`, reports one `input_hashes` does not confirm, or leaves a read in no `reads` at all
     */
    public static function forRequest(array $result, ScannerManifest $manifest, array $verifiedInputs, array $contributions, array $requested = []): array
    {
        $attributing = in_array(Protocol::CAPABILITY_READ_ATTRIBUTION, $manifest->capabilities, true);
        $shared = $attributing
            ? (array_key_exists('reads', $result) ? ReadsMap::decode($result['reads']) : [])
            : $verifiedInputs;
        if ($attributing) {
            self::assertConfirmed($shared, $verifiedInputs, $manifest, 'the scan result');
        }
        $groupId = self::groupId($shared);
        $owners = [];
        foreach ($contributions as $contribution) {
            $reads = [];
            if ($attributing) {
                if ($contribution->reads === null) {
                    throw new WorkerException('WORKER_CONTRIBUTION_INVALID', sprintf('%s declares %s but the contribution for %s carries no reads.', $manifest->id, Protocol::CAPABILITY_READ_ATTRIBUTION, $contribution->ownerKey));
                }
                $reads = $contribution->reads;
                self::assertConfirmed($reads, $verifiedInputs, $manifest, $contribution->ownerKey);
            }
            $owners[$contribution->ownerKey] = ['reads' => $reads, 'group' => $groupId, 'attributed' => $attributing];
        }
        if ($attributing) {
            self::assertAttributed($verifiedInputs, [$shared, ...array_column($owners, 'reads')], $requested, $manifest);
        }

        return ['groups' => $groupId === null ? [] : [$groupId => $shared], 'owners' => $owners];
    }

    /**
     * Refuse a reported read that `input_hashes` does not carry with the same value.
     *
     * @param array<string, ?string> $reads
     * @param array<string, string|null> $verifiedInputs
     */
    private static function assertConfirmed(array $reads, array $verifiedInputs, ScannerManifest $manifest, string $source): void
    {
        foreach ($reads as $path => $hash) {
            $path = (string) $path;
            if (!array_key_exists($path, $verifiedInputs) || $verifiedInputs[$path] !== $hash) {
                throw new WorkerException('WORKER_CONTRIBUTION_INVALID', sprintf('%s reported a read of %s in %s that its input_hashes does not confirm.', $manifest->id, $path, $source));
            }
        }
    }

    /**
     * Refuse a read that no contribution's reads and no shared set name.
     *
     * Such a read changes nothing the planner rescans when it changes, so the
     * contributions derived from it would be reused against bytes they never
     * saw. A requested file's own read is its contribution's content hash.
     *
     * @param array<string, string|null> $verifiedInputs
     * @param list<array<string, ?string>> $readSets
     * @param list<string> $requested
     */
    private static function assertAttributed(array $verifiedInputs, array $readSets, array $requested, ScannerManifest $manifest): void
    {
        $named = array_fill_keys($requested, true);
        foreach ($readSets as $reads) {
            $named += $reads;
        }
        foreach ($verifiedInputs as $path => $hash) {
            if (!array_key_exists((string) $path, $named)) {
                throw new WorkerException('WORKER_CONTRIBUTION_INVALID', sprintf("%s read %s but named it in no contribution's reads and not in the request's shared reads.", $manifest->id, $path));
            }
        }
    }

    /**
     * The content address of a read set, independent of the order it arrived in.
     *
     * @param array<string, ?string> $reads
     */
    private static function groupId(array $reads): ?string
    {
        if ($reads === []) {
            return null;
        }
        ksort($reads, SORT_STRING);

        return hash('sha256', json_encode((object) $reads, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
