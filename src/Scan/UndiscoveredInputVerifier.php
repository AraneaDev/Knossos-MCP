<?php

declare(strict_types=1);

namespace Knossos\Scan;

use InvalidArgumentException;
use Knossos\Discovery\RootGuard;
use Knossos\Scanner\Protocol\RelativePath;

/**
 * Re-reads, just before a scan commits, every file a worker read that discovery
 * never hashed.
 *
 * Discovery's own files are proven twice: against each worker's report, and by
 * {@see ScanSnapshotValidator::validateDiscovery()} after the workers return.
 * A file discovery never hashed, such as a `node_modules` declaration the
 * TypeScript checker resolved a type through, has neither. A change and restore
 * around the worker's read is caught when two reads disagree
 * ({@see UndiscoveredInputs}); what is left is a file still different, created
 * or removed when the workers are done. That is checked here, so the facts a
 * scan commits match every file they came from as it stands at commit.
 *
 * Consistency at commit only. These files are not added to freshness tracking,
 * so editing one after the scan does not mark the graph stale.
 *
 * What a report must still match:
 *
 * - A hash: the path, joined to the root and resolved without leaving it, is a
 *   regular file whose raw bytes hash to it. The read stops one byte past the
 *   discovery cap, and a file that reaches that byte fails against any hash,
 *   since no worker reads past the cap either. A link inside the root is
 *   followed rather than refused, because workers record a read's hash under
 *   every in-root link location they followed (the TypeScript worker's
 *   recordWalked), so refusing links would fail stable trees.
 * - A null, a read that failed or was refused: the path is not readable as
 *   itself, meaning it is absent, not a regular file, reached through a link,
 *   or over the cap. The TypeScript worker records exactly those refusals as
 *   null, including under a link it only probed through, so a null against a
 *   link or an oversized file describes the same tree. A null against an
 *   in-root regular file within the cap means the file appeared, or became
 *   readable, after the worker looked.
 *
 * The file type is checked with stat() before anything is opened, and again on
 * the open handle: opening a FIFO blocks until a writer appears, so a path
 * swapped for one mid-scan would hang the scan instead of failing it.
 */
final class UndiscoveredInputVerifier
{
    /**
     * @param string $rootRealpath the project root as discovery resolved it
     * @param array<array-key, string|null> $inputs path to SHA-256 hex, or null for a failed read
     * @param int $maxFileBytes the discovery byte cap the scan ran under
     * @throws ScanSnapshotChangedException for the first path that no longer matches its read
     * @throws InvalidArgumentException for a key that is not a project-relative path
     */
    public function verify(string $rootRealpath, array $inputs, int $maxFileBytes): void
    {
        $root = rtrim($rootRealpath, '/');
        // realpath() answers from a per-process cache that a long-running
        // server keeps across scans, so a path that became a link, or stopped
        // being one, while the workers ran would resolve as it did before.
        clearstatcache(true);
        foreach ($inputs as $path => $hash) {
            $path = (string) $path;
            // Keys were validated on intake; this class must not rely on that
            // to stay inside the root.
            RelativePath::assertValid($path, 'undiscovered input ' . $path);
            $absolute = $root . '/' . $path;
            $matches = $hash === null
                ? !self::readableAsItself($root, $absolute, $maxFileBytes)
                : self::hashOf($root, $absolute, $maxFileBytes) === $hash;
            if (!$matches) {
                throw ScanSnapshotChangedException::inputChangedAfterRead($path);
            }
        }
    }

    /**
     * The SHA-256 of a regular file inside the root, or null when it is absent,
     * not a regular file, outside the root once resolved, unreadable, or over the cap.
     */
    private static function hashOf(string $root, string $absolute, int $maxFileBytes): ?string
    {
        $resolved = realpath($absolute);
        if ($resolved === false || !RootGuard::contains($root, $resolved) || !self::regularAt($resolved)) {
            return null;
        }
        $handle = @fopen($resolved, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            // From the open handle rather than a stat of the path, so the check
            // is about the file actually read. A directory opens on Linux.
            if (!self::isRegular($handle)) {
                return null;
            }
            $context = hash_init('sha256');
            $read = hash_update_stream($context, $handle, $maxFileBytes + 1);

            return $read > $maxFileBytes ? null : hash_final($context);
        } finally {
            fclose($handle);
        }
    }

    /** Whether the path is an in-root regular file within the cap, reached without a link, that opens. */
    private static function readableAsItself(string $root, string $absolute, int $maxFileBytes): bool
    {
        // The root is a realpath, so a path that resolves to its own spelling
        // passed through no link.
        if (realpath($absolute) !== $absolute || !self::regularAt($absolute)) {
            return false;
        }
        $handle = @fopen($absolute, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $stat = fstat($handle);

            return self::isRegular($handle) && is_array($stat) && $stat['size'] <= $maxFileBytes;
        } finally {
            fclose($handle);
        }
    }

    /** Whether the path is a regular file now, asked before an open that would block on a FIFO. */
    private static function regularAt(string $path): bool
    {
        $stat = @stat($path);

        return is_array($stat) && ($stat['mode'] & 0o170000) === 0o100000;
    }

    /** @param resource $handle */
    private static function isRegular($handle): bool
    {
        $stat = fstat($handle);

        return is_array($stat) && ($stat['mode'] & 0o170000) === 0o100000;
    }
}
