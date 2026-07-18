<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

final readonly class NestJsRoleRule implements ClassificationRule
{
    public function id(): string
    {
        return 'nestjs.decorators.v1';
    }

    public function classify(NodeFact $node): array
    {
        $roles = $node->attributes['nestjs_roles'] ?? [];
        if (!is_array($roles)) {
            return [];
        }
        $facts = [];
        foreach (array_values(array_unique($roles)) as $role) {
            if (!is_string($role) || !in_array($role, ['nestjs.module', 'nestjs.controller', 'nestjs.provider'], true)) {
                continue;
            }
            $facts[] = new ClassificationFact(
                $node->localId,
                $role,
                $this->id(),
                Origin::FrameworkConvention,
                Confidence::Certain,
                $node->evidence,
                ['source' => '@nestjs/common decorator'],
            );
        }
        return $facts;
    }
}
