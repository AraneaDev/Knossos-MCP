<?php

declare(strict_types=1);

namespace Knossos\Boundary;

use InvalidArgumentException;

final readonly class BoundaryFact
{
    /** @param array<string, mixed> $matcher @param list<string> $nodeReferences */
    public function __construct(
        public string $name,
        public array $matcher,
        public string $source,
        public array $nodeReferences,
    ) {
        if ($name === '' || !in_array($source, ['explicit', 'inferred'], true)) {
            throw new InvalidArgumentException('Boundary name and source are invalid.');
        }
        if (!array_is_list($nodeReferences)) {
            throw new InvalidArgumentException('Boundary members must be a list.');
        }
        foreach ($nodeReferences as $reference) {
            if (!is_string($reference) || $reference === '') {
                throw new InvalidArgumentException('Boundary member references must be non-empty strings.');
            }
        }
    }
}
