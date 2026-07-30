<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

use InvalidArgumentException;
use JsonSerializable;

/**
 * One relationship a scanner found, with the evidence that justifies it.
 *
 * Endpoints are canonical names rather than ids, because a worker analyses one
 * file and cannot know what the rest of the graph will contain; resolution to ids
 * happens during reconciliation.
 */
final readonly class EdgeFact implements JsonSerializable
{
    /** @param array<string, scalar|null|array<mixed>> $attributes */
    public function __construct(
        public string $kind,
        public string $sourceReference,
        public string $targetReference,
        public Origin $origin,
        public Confidence $confidence,
        public Evidence $evidence,
        public array $attributes = [],
    ) {
        if ($kind === '' || $sourceReference === '' || $targetReference === '') {
            throw new InvalidArgumentException('Edge kind and references must not be empty.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind,
            'source' => $this->sourceReference,
            'target' => $this->targetReference,
            'origin' => $this->origin->value,
            'confidence' => $this->confidence->value,
            'evidence' => $this->evidence,
            'attributes' => $this->attributes,
        ];
    }
}
