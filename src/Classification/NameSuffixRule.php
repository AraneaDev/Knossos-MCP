<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

final readonly class NameSuffixRule implements ClassificationRule
{
    /** @param array<string, string> $suffixRoles @param list<string> $eligibleKinds */
    public function __construct(
        private string $ruleId,
        private array $suffixRoles,
        private array $eligibleKinds = ['class'],
        private Origin $origin = Origin::Derived,
        private Confidence $confidence = Confidence::Probable,
    ) {}

    public function id(): string
    {
        return $this->ruleId;
    }

    public function classify(NodeFact $node): array
    {
        if (!in_array($node->kind, $this->eligibleKinds, true)) {
            return [];
        }
        $facts = [];
        foreach ($this->suffixRoles as $suffix => $role) {
            if ($suffix !== '' && str_ends_with($node->displayName, $suffix)) {
                $facts[] = new ClassificationFact(
                    $node->localId,
                    $role,
                    $this->ruleId,
                    $this->origin,
                    $this->confidence,
                    $node->evidence,
                    ['matched_suffix' => $suffix],
                );
            }
        }
        return $facts;
    }
}
