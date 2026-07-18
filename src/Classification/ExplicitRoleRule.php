<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

final readonly class ExplicitRoleRule implements ClassificationRule
{
    /** @param array<string, list<string>> $rolesByCanonicalName */
    public function __construct(private string $ruleId, private array $rolesByCanonicalName) {}

    public function id(): string
    {
        return $this->ruleId;
    }

    public function classify(NodeFact $node): array
    {
        $facts = [];
        foreach ($this->rolesByCanonicalName[$node->canonicalName] ?? [] as $role) {
            $facts[] = new ClassificationFact(
                $node->localId,
                $role,
                $this->ruleId,
                Origin::UserRule,
                Confidence::Certain,
                $node->evidence,
            );
        }
        return $facts;
    }
}
