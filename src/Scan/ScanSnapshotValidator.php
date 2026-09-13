<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\FileFingerprint;

/**
 * Proves, after the language workers have returned and before anything is
 * written, that the files the scan parsed still hash to what discovery recorded
 * for them.
 *
 * The gap it closes. Discovery reads and hashes each file inside this process;
 * the workers are separate processes in four languages and are handed a root
 * and a relative path, so each of them reads the file again for itself. Nothing
 * ties those two reads together, and a write landing between them produces
 * graph facts that no `files.content_hash` describes. Because the stored hash
 * still matches whatever the tree settled on, every drift oracle afterwards
 * reports the graph `fresh`. Detecting that here converts a silently wrong
 * graph into an honest failure.
 *
 * Every discovered file is hashed, with no mtime or size prefilter. A
 * second-resolution timestamp cannot distinguish a write inside the scan's own
 * second from no write at all, so a prefilter would hide exactly the race this
 * exists to catch, permanently and silently. The cost is one read-and-hash pass,
 * about what a single drift probe costs, against a scan that has already sent
 * every one of these files through a language worker.
 *
 * It detects rather than prevents, and it cannot say which fact came from which
 * bytes — only that the scan as a whole is suspect. That is why the response is
 * to fail the scan rather than to retry or to repair part of it.
 */
final readonly class ScanSnapshotValidator
{
    /**
     * Throw unless every discovered file still hashes to its discovered hash.
     *
     * Hashing goes through {@see FileFingerprint}, the same class the discovery
     * walk fingerprints with, so the two values are comparable by construction
     * rather than because two hand-written implementations happen to agree.
     * Through its content-hash-only entry point, because that is the whole
     * question here: the line count and the Git blob id
     * {@see FileFingerprint::compute()} also derives describe a file and a
     * commit, and neither is compared, so computing them would be work this
     * pass pays for on every file it re-reads and then throws away.
     *
     * Fails on the first offending file rather than collecting them all: the
     * scan is discarded either way, and one named path is what a reader acts on.
     *
     * @param list<DiscoveredFile> $files the files discovery selected and hashed
     * @throws ScanSnapshotChangedException when any file no longer matches, has
     *         been removed, or can no longer be read
     */
    public function validate(array $files): void
    {
        foreach ($files as $file) {
            $contentHash = FileFingerprint::contentHashOf($file->absolutePath);
            if ($contentHash === null) {
                // Two reasons a re-read fails, kept apart because they lead an
                // operator somewhere different. Which one it is comes from a
                // later look at the filesystem than the failed read, so it is
                // descriptive only: both outcomes abort the scan regardless.
                throw file_exists($file->absolutePath)
                    ? ScanSnapshotChangedException::unreadable($file->relativePath)
                    : ScanSnapshotChangedException::disappeared($file->relativePath);
            }
            if ($contentHash !== $file->contentHash) {
                throw ScanSnapshotChangedException::contentChanged($file->relativePath);
            }
        }
    }
}
