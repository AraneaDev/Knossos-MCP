<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * One read of a file discovery intends to use more than once.
 *
 * Exists so that "the bytes this scan used" is a thing the code can hold and
 * pass around, rather than something each consumer fetches for itself. A
 * manifest was read twice, once to hash and once to parse, and nothing tied
 * the two reads together: an edit landing between them left a {@see
 * ProjectUnit} carrying the first read's hash beside metadata from the second
 * read's content. Restore the file afterwards and both drift oracles compare
 * the restored bytes against a stored hash they match, and report nothing,
 * while the graph was built from metadata that never described those bytes.
 *
 * An interface rather than a bare function so a test can hand back different
 * content on a second call. That is the only way to demonstrate the defect
 * without a filesystem that changes underneath a running scan, and a fix
 * nothing can demonstrate is a fix nobody can keep.
 */
interface FileContentReader
{
    /**
     * The file, bounded by $maxBytes, or the reason there are no bytes to use.
     *
     * The bound is an argument rather than an assumption about the caller
     * because it is the only thing standing between this and an unbounded
     * allocation: discovery checks a file's size before asking, and a file that
     * grows between that check and this read is read whole unless the limit
     * travels with the request. A result rather than a nullable string for the
     * reason {@see FileContent} gives: unreadable and oversized are different
     * answers that deserve different diagnostics, and an empty file is a
     * legitimate third thing neither may be confused with.
     */
    public function read(string $absolutePath, int $maxBytes): FileContent;
}
