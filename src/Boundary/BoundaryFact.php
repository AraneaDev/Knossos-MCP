<?php

declare(strict_types=1);

namespace Knossos\Boundary;

use InvalidArgumentException;

final readonly class BoundaryFact
{
    /**
     * @param array<string, mixed> $matcher
     * @param list<string> $nodeReferences
     * @param ?string $identityName Pre-suffix primary rule name used to derive the
     *     boundary's stable id. Null means "same as $name" (the common case: explicit
     *     boundaries and unmerged inferred boundaries). When an inferred boundary's
     *     display name carries a merged-from suffix (`composer:x (+node:y)`), this holds
     *     the surviving rule's base name so the stable id stays independent of which
     *     other rules happened to merge into it.
     */
    public function __construct(
        public string $name,
        public array $matcher,
        public string $source,
        public array $nodeReferences,
        public ?string $identityName = null,
    ) {
        if ($name === '' || !in_array($source, ['explicit', 'inferred'], true)) {
            throw new InvalidArgumentException('Boundary name and source are invalid.');
        }
        if ($identityName === '') {
            throw new InvalidArgumentException('Boundary identity name must not be empty.');
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
