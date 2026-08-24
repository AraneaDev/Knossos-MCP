<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/**
 * Shared classify() implementation for rules that persist worker framework-role
 * attributes as architecture classifications.
 *
 * Each concrete rule provides the language-specific roles whitelist, the node
 * attribute key, and its confidence/evidence metadata.  The classify() loop
 * itself is identical across languages: filter the attribute array against the
 * whitelist, skip non-strings and unknowns, deduplicate, and emit one
 * ClassificationFact per surviving role.
 */
abstract readonly class AbstractFrameworkRoleRule implements ClassificationRule
{
    /**
     * The set of framework roles this rule recognises.
     *
     * @return list<string>
     */
    abstract protected function knownRoles(): array;

    /** The node-attribute key that holds the worker-sourced roles array. */
    abstract protected function attributeKey(): string;

    /** Confidence assigned to every fact emitted by this rule. */
    abstract protected function confidence(): Confidence;

    /**
     * Evidence metadata carried on every fact emitted by this rule.
     *
     * @return array<string, string>
     */
    abstract protected function evidenceMeta(): array;

    /** {@inheritDoc} */
    public function classify(NodeFact $node): array
    {
        $roles = $node->attributes[$this->attributeKey()] ?? [];
        if (!is_array($roles)) {
            return [];
        }
        $facts = [];
        foreach (array_values(array_unique($roles)) as $role) {
            if (!is_string($role) || !in_array($role, $this->knownRoles(), true)) {
                continue;
            }
            $facts[] = new ClassificationFact(
                $node->localId,
                $role,
                $this->id(),
                Origin::FrameworkConvention,
                $this->confidence(),
                $node->evidence,
                $this->evidenceMeta(),
            );
        }

        return $facts;
    }
}
