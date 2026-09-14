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
     * Discovery read it moments earlier as a regular file, so a failure now
     * means that, while the scan ran, the file was removed, its permissions
     * changed, or the path became a link or a directory, and the facts derived
     * without its bytes describe a tree that existed at no point.
     */
    public static function inputUnreadable(string $relativePath): self
    {
        return new self(sprintf(
            'Scan aborted: %s could not be read while the scan derived graph facts from it, because it was missing, unreadable, or had become a link or a directory, so those facts cannot be verified. Check the path is a readable regular file, then rerun the scan.',
            $relativePath,
        ));
    }

    /**
     * A file discovery never hashed, such as a `node_modules` declaration, an
     * ignored module or a file that existed only briefly, was read by a worker
     * during the scan and no longer matches that read when the scan is about to
     * commit: its bytes differ, it has since appeared, or it has since
     * disappeared.
     *
     * Such a file has no recorded hash, so the re-read just before commit is
     * the only evidence of what the workers derived facts from. It is not
     * tracked for freshness afterwards; this only keeps a scan from committing
     * facts that match no state of the file at commit.
     */
    public static function inputChangedAfterRead(string $relativePath): self
    {
        return new self(sprintf(
            'Scan aborted: %s was read during the scan with different content than it has now, or has since appeared or disappeared, so graph facts derived from it cannot be verified. Rerun the scan once the tree has settled.',
            $relativePath,
        ));
    }

    /**
     * Two reads of one file discovery never hashed, in different scan requests,
     * reported different results: two hashes, or a hash and a failed read.
     *
     * At least one of them describes content the file no longer has, and the
     * re-read before commit could only vouch for one, so the scan fails as soon
     * as the second report arrives. Named apart from inputChangedAfterRead()
     * because nothing has been compared with disk yet: the workers disagree
     * with each other.
     */
    public static function inputReadInconsistently(string $relativePath): self
    {
        return new self(sprintf(
            'Scan aborted: %s was read more than once during the scan with different results, so graph facts derived from it cannot be verified. Rerun the scan once the tree has settled.',
            $relativePath,
        ));
    }
}
