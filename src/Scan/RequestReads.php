<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Scanner\Worker\ContributionDecoder;
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
     * @return array{groups: array<string, array<string, ?string>>, owners: array<string, array{reads: array<string, ?string>, group: ?string, attributed: bool}>}
     * @throws WorkerException WORKER_CONTRIBUTION_INVALID when an attributing worker omits `reads` or reports one `input_hashes` does not confirm
     */
    public static function forRequest(array $result, ScannerManifest $manifest, array $verifiedInputs, array $contributions): array
    {
        $attributing = in_array(Protocol::CAPABILITY_READ_ATTRIBUTION, $manifest->capabilities, true);
        $shared = $attributing
            ? (array_key_exists('reads', $result) ? ContributionDecoder::reads($result['reads']) : [])
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
