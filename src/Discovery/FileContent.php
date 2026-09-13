<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * What one bounded read came back with: the bytes, nothing at all, or more
 * bytes than the caller agreed to hold.
 *
 * Oversized is a state of its own rather than another null, because the two
 * call for different answers. A file that cannot be read is a dropped unit and
 * a DISCOVERY_CONFIG_UNREADABLE; a file that outgrew the limit between the size
 * check and the read is the same DISCOVERY_FILE_TOO_LARGE discovery already
 * reports for a file that was over the limit when it looked. Collapsing them
 * would tell the caller a growing manifest was unreadable, which is not why it
 * was dropped and not what a caller would act on.
 */
final readonly class FileContent
{
    private function __construct(public ?string $bytes, public bool $oversized) {}

    /** Bytes that came in at or under the caller's limit, which is the only case anything may be parsed from. */
    public static function of(string $bytes): self
    {
        return new self($bytes, false);
    }

    /**
     * More bytes than the limit allows.
     *
     * Carries none of them: the point of the bound is that the overage is
     * never held, so there is nothing here to hand back.
     */
    public static function oversized(): self
    {
        return new self(null, true);
    }

    /** Nothing to read: gone, denied, or not a file any more. */
    public static function unreadable(): self
    {
        return new self(null, false);
    }
}
