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

    /**
     * A worker declaring this reports, on every scan result, an `input_hashes`
     * object: the SHA-256 of the raw bytes of every project file it read to
     * derive facts, including the requested files themselves, or null for a
     * read of such a file that failed. The core refuses facts derived from
     * bytes discovery did not hash.
     */
    public const CAPABILITY_INPUT_HASHES = 'input_hashes';

    public const METHOD_INITIALIZE = 'initialize';
    public const METHOD_SCAN = 'scan';
    public const METHOD_CANCEL = 'cancel';
    public const METHOD_SHUTDOWN = 'shutdown';

    /** Notification carrying one contribution while a scan request runs. */
    public const NOTIFICATION_CONTRIBUTION = 'scan/contribution';

    /**
     * Notification carrying part of a scan request's `input_hashes` map, for a
     * map too large for the one frame the final result travels on. The core
     * merges every part with the result's own field.
     */
    public const NOTIFICATION_INPUT_HASHES = 'scan/input_hashes';

    /**
     * Notification a worker sends while a scan request is busy with nothing
     * to report yet, such as building a program. Like every notification it
     * restarts the request's inactivity timeout; it carries nothing.
     */
    public const NOTIFICATION_HEARTBEAT = 'scan/heartbeat';

    private function __construct() {}
}
