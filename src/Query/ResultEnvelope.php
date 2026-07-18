<?php

declare(strict_types=1);

namespace Knossos\Query;

use JsonSerializable;

final readonly class ResultEnvelope implements JsonSerializable
{
    /** @param array<string, mixed> $data @param list<array<string, mixed>> $evidence @param list<string> $warnings */
    public function __construct(
        public string $projectId,
        public string $snapshotId,
        public string $summary,
        public array $data,
        public array $evidence = [],
        public array $warnings = [],
        public bool $truncated = false,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'project_id' => $this->projectId,
            'snapshot_id' => $this->snapshotId,
            'summary' => $this->summary,
            'data' => $this->data,
            'evidence' => $this->evidence,
            'warnings' => $this->warnings,
            'truncated' => $this->truncated,
        ];
    }
}
