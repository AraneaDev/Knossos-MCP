<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use RuntimeException;

/**
 * A requested file the filesystem would not let the worker read as the file it
 * was asked for: gone, not a regular file, over the byte cap, or resolving
 * outside the project root.
 *
 * Kept apart from {@see WorkerInputException}, which also covers a path this
 * worker refuses by policy, because the two answer `input_hashes` differently.
 * A policy refusal says nothing about the tree. A failed read says the file is
 * not what discovery hashed at that moment, so it is reported as `null` and the
 * core fails the scan when the path was discovered, rather than keeping a graph
 * that silently lacks that file's facts.
 */
final class UnreadableFileException extends RuntimeException {}
