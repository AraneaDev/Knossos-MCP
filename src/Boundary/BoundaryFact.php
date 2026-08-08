<?php

declare(strict_types=1);

namespace Knossos\Boundary;

use InvalidArgumentException;

/** One boundary and the nodes that belong to it, with whether it was declared or inferred. */
final readonly class BoundaryFact
{
    /**
     * @param array<string, mixed> $matcher
     * @param list<string> $nodeReferences
     * @param ?string $identityName Pre-suffix primary rule identity used to derive the
     *     boundary's stable id. Null means "same as $name" — always true for explicit
     *     boundaries and for inferred boundaries whose id is not pinned separately
     *     (namespace/module/python-package rules). Inferred manifest boundaries
     *     (composer/node/python) always carry a non-null identityName, even unmerged
     *     and even when it is identical to $name: this pins their id against two
     *     manifests that declare the same name (which would otherwise collide on both
     *     the internal rule key and the id) without renaming the boundary itself, since
     *     boundary names are a public reference surface that policies resolve by name.
     *     When an inferred boundary's display name carries a merged-from suffix (e.g.
     *     `composer:acme/lib (+node:web-app)`), identityName holds the surviving rule's
     *     pre-suffix identity so the stable id stays independent of which other rules
     *     happened to merge into it.
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
