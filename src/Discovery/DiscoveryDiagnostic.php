<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/** Something discovery could not read, reported so an incomplete file set is visible. */
final readonly class DiscoveryDiagnostic
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
        public ?string $relativePath = null,
    ) {}
}
