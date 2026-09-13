<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

/**
 * Wire constants for the scanner worker protocol.
 *
 * The versions here gate compatibility: a worker announcing a different protocol
 * or output schema version is refused rather than half-understood. They do not
 * key the contribution cache; a cached contribution is reused only while its
 * file content hash, scanner version and configuration hash all still match,
 * so a change to what a worker emits invalidates the cache by bumping that
 * worker's own version.
 */
final class Protocol
{
    public const VERSION = '1.0';
    public const OUTPUT_SCHEMA_VERSION = '1.0';

    /**
     * A worker declaring this reports, on every contribution for a file whose
     * bytes it read, the SHA-256 of exactly those raw bytes. The core then
     * refuses facts parsed from bytes discovery did not hash.
     */
    public const CAPABILITY_CONTENT_HASH = 'content_hash';

    public const METHOD_INITIALIZE = 'initialize';
    public const METHOD_SCAN = 'scan';
    public const METHOD_CANCEL = 'cancel';
    public const METHOD_SHUTDOWN = 'shutdown';

    private function __construct() {}
}
