<?php

declare(strict_types=1);

namespace Knossos\Scan;

use RuntimeException;

/**
 * The tree moved underneath a scan: a file the language workers read is not the
 * file discovery hashed.
 *
 * Why this is fatal rather than a warning. Discovery hashes each file and the
 * workers read the same paths again for themselves, so two reads of one path
 * produce the graph and the hash that is supposed to describe it. A write
 * landing between them leaves graph facts parsed from content no stored hash
 * ever matched, and the drift oracles — which compare the tree against those
 * stored hashes — then report the graph `fresh`. A false `fresh` is the worst
 * outcome this subsystem has: every later answer is computed from source that
 * was never there, and nothing triggers a repair. A scan that fails honestly
 * costs a rerun, which is the trade taken everywhere else here.
 *
 * Deliberately its own class and deliberately not a {@see ScanCancelledException}:
 * the transports treat cancellation as "the caller asked to stop" and may drop
 * the response entirely, while this is a fault the caller has to hear about.
 */
final class ScanSnapshotChangedException extends RuntimeException
{
    /**
     * The re-read hashed differently: the file was rewritten while the scan ran.
     *
     * The path is in the message because that is all a reader needs to act —
     * the message reaches an agent verbatim through `refresh_if_stale`'s
     * warning, which carries no structured fields to look a file up in.
     */
    public static function contentChanged(string $relativePath): self
    {
        return new self(sprintf(
            'Scan aborted: %s changed while the scan was running, so its graph facts match no recorded hash. Rerun the scan once the tree has settled.',
            $relativePath,
        ));
    }

    /**
     * The file was discovered and then removed before the scan could re-read it.
     *
     * Not the same fault as a rewrite, and named separately for that reason,
     * but it is not nothing either: a worker may have parsed the file before it
     * vanished, so the scan would commit facts about content that no longer
     * exists and nothing can attest to. Unverifiable is not verified.
     */
    public static function disappeared(string $relativePath): self
    {
        return new self(sprintf(
            'Scan aborted: %s was removed while the scan was running, so what the workers parsed from it can no longer be verified. Rerun the scan once the tree has settled.',
            $relativePath,
        ));
    }

    /**
     * The file is still there but its bytes could not be read a second time.
     *
     * Treated exactly as severely as a removal, for the same reason: the scan
     * cannot show that what it parsed is what it hashed. Kept distinct in the
     * message because the operator's next step differs — a permission or path
     * change is a condition to correct, not a race to wait out.
     */
    public static function unreadable(string $relativePath): self
    {
        return new self(sprintf(
            'Scan aborted: %s could not be re-read to verify the content the scan parsed. Check the path is still a readable file, then rerun the scan.',
            $relativePath,
        ));
    }

    /**
     * The worker reported parsing bytes whose hash is not the one discovery
     * recorded.
     *
     * The case the post-worker re-read cannot see: the file changed while the
     * worker read it and changed back before the scan checked, so disk and
     * record agree again while the facts describe neither. Only the worker's own
     * hash of what it parsed shows it.
     */
    public static function parsedDifferently(string $relativePath): self
    {
        return new self(sprintf(
            'Scan aborted: %s was parsed from different content than the scan hashed, so its graph facts match no recorded hash. Rerun the scan once the tree has settled.',
            $relativePath,
        ));
    }

    /**
     * A worker reported reading a file, via `input_hashes`, from bytes whose
     * hash is not the one discovery recorded. The read named is usually the
     * requested file's own — a worker lists every file it read, including the
     * one it was asked for — but the same check also catches a file read only
     * to resolve another's facts, which carries no content_hash of its own.
     *
     * Distinct from parsedDifferently(): that one fires from a contribution's
     * own content_hash, this one from the broader input_hashes map, and the
     * two can name the same file for the same rewrite depending on which
     * check the core runs first.
     */
    public static function inputReadDifferently(string $relativePath): self
    {
        return new self(sprintf(
            'Scan aborted: %s was read from different content than the scan hashed, so graph facts derived from it match no recorded hash. Rerun the scan once the tree has settled.',
            $relativePath,
        ));
    }

    /**
     * A worker reported, via `input_hashes`, that it tried to read a
     * discovered file and failed. The file named is usually a requested file
     * itself, but the same check also catches a file read only to resolve
     * another's facts.
     *
     * Discovery read it moments earlier, so a failure now means the file was
     * removed or its permissions changed mid-scan, and the facts derived
     * without it describe a tree that existed at no point.
     */
    public static function inputUnreadable(string $relativePath): self
    {
        return new self(sprintf(
            'Scan aborted: %s could not be read while the scan derived graph facts from it, so those facts cannot be verified. Check the path is still a readable file, then rerun the scan.',
            $relativePath,
        ));
    }
}
