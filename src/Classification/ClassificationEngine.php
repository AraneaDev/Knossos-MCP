<?php

declare(strict_types=1);

namespace Knossos\Classification;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\ScanContribution;

final readonly class ClassificationEngine
{
    /** @param list<ClassificationRule> $rules */
    public function __construct(private array $rules)
    {
        foreach ($rules as $rule) {
            if (!$rule instanceof ClassificationRule) {
                throw new InvalidArgumentException('Classification rules must implement ClassificationRule.');
            }
        }
    }

    /** @param list<ScanContribution> $contributions @return list<ClassificationFact> */
    public function classify(array $contributions): array
    {
        $facts = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                foreach ($this->rules as $rule) {
                    foreach ($rule->classify($node) as $fact) {
                        if ($fact->nodeReference !== $node->localId || $fact->ruleId !== $rule->id()) {
                            throw new InvalidArgumentException(sprintf('Rule %s emitted inconsistent provenance.', $rule->id()));
                        }
                        $key = implode("\0", [$fact->nodeReference, $fact->role, $fact->ruleId]);
                        $facts[$key] = $fact;
                    }
                }
            }
        }
        ksort($facts, SORT_STRING);
        return array_values($facts);
    }
}
