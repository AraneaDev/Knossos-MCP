<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * A manifest discovery found — composer.json, package.json, pyproject.toml.
 *
 * These decide which language workers run and which frameworks are enriched, so
 * they are recorded as findings rather than read ad hoc later.
 */
final readonly class ProjectUnit
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $kind,
        public string $configPath,
        public string $contentHash,
        public array $metadata = [],
    ) {}
}
