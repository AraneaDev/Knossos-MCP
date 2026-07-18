<?php

declare(strict_types=1);

namespace Knossos\Discovery;

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
