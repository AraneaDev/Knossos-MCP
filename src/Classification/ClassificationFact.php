<?php

declare(strict_types=1);

namespace Knossos\Classification;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\Origin;

final readonly class ClassificationFact
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $nodeReference,
        public string $role,
        public string $ruleId,
        public Origin $origin,
        public Confidence $confidence,
        public Evidence $evidence,
        public array $attributes = [],
    ) {
        foreach ([$nodeReference, $role, $ruleId] as $value) {
            if ($value === '') {
                throw new InvalidArgumentException('Classification reference, role, and rule ID must not be empty.');
            }
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/', $role)) {
            throw new InvalidArgumentException('Classification roles use lowercase namespaced identifiers.');
        }
    }
}
