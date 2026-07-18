<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

final readonly class TypeScriptFrameworkRoleRule implements ClassificationRule
{
    private const ROLES = [
        'nextjs.layout',
        'nextjs.page',
        'nextjs.route_handler',
        'nextjs.server_action',
        'react.component',
        'react.hook',
        'state.store',
        'vue.component',
        'vue.composable',
    ];

    public function id(): string
    {
        return 'typescript.application.v1';
    }

    public function classify(NodeFact $node): array
    {
        $roles = $node->attributes['typescript_framework_roles'] ?? [];
        if (!is_array($roles)) {
            return [];
        }
        $facts = [];
        foreach (array_values(array_unique($roles)) as $role) {
            if (!is_string($role) || !in_array($role, self::ROLES, true)) {
                continue;
            }
            $facts[] = new ClassificationFact($node->localId, $role, $this->id(), Origin::FrameworkConvention, Confidence::Probable, $node->evidence, ['source' => 'compiler syntax and application convention']);
        }
        return $facts;
    }
}
