<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use RuntimeException;

/**
 * A requested file this worker refuses because of what it read in it: an
 * extensionless script whose shebang does not name PHP.
 *
 * Unlike a refusal by extension, which reads nothing and says nothing about
 * the tree, this refusal is decided by bytes, and discovery routed the file
 * here because its bytes named PHP when it hashed them. A file swapped for
 * another script and restored around the probe would otherwise lose its facts
 * from a graph reported fresh. The refusal therefore carries evidence for
 * `input_hashes`: the hash of the whole file as read in one bounded read that
 * reached the same verdict, which a stable tree matches, or null when that
 * read failed, was over the byte cap, or found PHP after all.
 */
final class RefusedAfterReadException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $contentHash)
    {
        parent::__construct($message);
    }
}
