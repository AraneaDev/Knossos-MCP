<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

use InvalidArgumentException;
use JsonSerializable;

/**
 * One component a scanner found: a class, method, function, route, and so on.
 *
 * A wire DTO rather than a graph row: workers are untrusted, so a fact is
 * validated on arrival and only then persisted.
 */
final readonly class NodeFact implements JsonSerializable
{
    /** @param array<string, scalar|null|array<mixed>> $attributes */
    public function __construct(
        public string $localId,
        public string $kind,
        public string $canonicalName,
        public string $displayName,
        public Origin $origin,
        public Confidence $confidence,
        public Evidence $evidence,
        public array $attributes = [],
    ) {
        if ($localId === '' || $kind === '' || $canonicalName === '' || $displayName === '') {
            throw new InvalidArgumentException('Node identity, kind, and names must not be empty.');
        }
    }

    /**
     * The wire shape of the node fact.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'local_id' => $this->localId,
            'kind' => $this->kind,
            'canonical_name' => $this->canonicalName,
            'display_name' => $this->displayName,
            'origin' => $this->origin->value,
            'confidence' => $this->confidence->value,
            'evidence' => $this->evidence,
            'attributes' => $this->attributes,
        ];
    }
}
