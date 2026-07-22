<?php

declare(strict_types=1);

namespace Knossos\Query;

use JsonSerializable;

final readonly class ResultEnvelope implements JsonSerializable
{
    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $evidence
     * @param list<string> $warnings
     * @param array<string, mixed>|null $staleness
     * @param list<array<string, mixed>> $nextSteps
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public string $projectId,
        public string $snapshotId,
        public string $summary,
        public array $data,
        public array $evidence = [],
        public array $warnings = [],
        public bool $truncated = false,
        public ?array $staleness = null,
        public array $nextSteps = [],
        public ?array $meta = null,
    ) {}

    /**
     * @param array<string, mixed>|null $staleness
     * @param list<array<string, mixed>>|null $nextSteps
     * @param array<string, mixed>|null $meta
     */
    public function with(?array $staleness = null, ?array $nextSteps = null, ?array $meta = null): self
    {
        return new self(
            $this->projectId,
            $this->snapshotId,
            $this->summary,
            $this->data,
            $this->evidence,
            $this->warnings,
            $this->truncated,
            $staleness ?? $this->staleness,
            $nextSteps ?? $this->nextSteps,
            $meta ?? $this->meta,
        );
    }

    /** @param list<string> $warnings */
    public function withWarnings(array $warnings): self
    {
        return new self(
            $this->projectId,
            $this->snapshotId,
            $this->summary,
            $this->data,
            $this->evidence,
            [...$this->warnings, ...$warnings],
            $this->truncated,
            $this->staleness,
            $this->nextSteps,
            $this->meta,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $out = [
            'project_id' => $this->projectId,
            'snapshot_id' => $this->snapshotId,
            'summary' => $this->summary,
            'data' => $this->data,
            'evidence' => $this->evidence,
            'warnings' => $this->warnings,
            'truncated' => $this->truncated,
        ];
        if ($this->staleness !== null) {
            $out['staleness'] = $this->staleness;
        }
        if ($this->nextSteps !== []) {
            $out['next_steps'] = $this->nextSteps;
        }
        if ($this->meta !== null) {
            $out['meta'] = $this->meta;
        }
        return $out;
    }
}
