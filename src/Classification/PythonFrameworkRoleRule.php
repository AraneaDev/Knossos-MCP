<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

final readonly class PythonFrameworkRoleRule implements ClassificationRule
{
    private const ROLES = [
        'django.middleware',
        'django.model',
        'django.view',
        'fastapi.route_handler',
        'python.task',
    ];

    public function id(): string
    {
        return 'python.framework.ast.v1';
    }

    public function classify(NodeFact $node): array
    {
        $roles = $node->attributes['python_framework_roles'] ?? [];
        if (!is_array($roles)) {
            return [];
        }
        $facts = [];
        foreach (array_values(array_unique($roles)) as $role) {
            if (!is_string($role) || !in_array($role, self::ROLES, true)) {
                continue;
            }
            $facts[] = new ClassificationFact(
                $node->localId,
                $role,
                $this->id(),
                Origin::FrameworkConvention,
                Confidence::Certain,
                $node->evidence,
                ['source' => 'python AST decorator/base'],
            );
        }
        return $facts;
    }
}
