<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

final readonly class LaravelRoleRule implements ClassificationRule
{
    private const BASE_ROLES = [
        'Illuminate\\Routing\\Controller' => 'laravel.controller',
        'Illuminate\\Database\\Eloquent\\Model' => 'laravel.model',
        'Illuminate\\Foundation\\Console\\Command' => 'laravel.command',
        'Illuminate\\Console\\Command' => 'laravel.command',
        'Illuminate\\Support\\ServiceProvider' => 'laravel.provider',
        'Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider' => 'laravel.provider',
        'Illuminate\\Foundation\\Http\\Middleware' => 'laravel.middleware',
    ];

    private const INTERFACE_ROLES = [
        'Illuminate\\Contracts\\Queue\\ShouldQueue' => 'laravel.queued',
        'Illuminate\\Contracts\\Events\\Dispatcher' => 'laravel.event_dispatcher',
    ];

    public function id(): string
    {
        return 'laravel.explicit.types.v1';
    }

    public function classify(NodeFact $node): array
    {
        if ($node->kind !== 'class') {
            return [];
        }
        $roles = [];
        $parent = $node->attributes['extends'] ?? null;
        if (is_string($parent) && isset(self::BASE_ROLES[$parent])) {
            $roles[self::BASE_ROLES[$parent]] = ['relation' => 'extends', 'target' => $parent];
        }
        $interfaces = $node->attributes['implements'] ?? [];
        if (is_array($interfaces)) {
            foreach ($interfaces as $interface) {
                if (is_string($interface) && isset(self::INTERFACE_ROLES[$interface])) {
                    $roles[self::INTERFACE_ROLES[$interface]] = ['relation' => 'implements', 'target' => $interface];
                }
            }
        }
        $facts = [];
        foreach ($roles as $role => $attributes) {
            $facts[] = new ClassificationFact(
                $node->localId,
                $role,
                $this->id(),
                Origin::FrameworkConvention,
                Confidence::Certain,
                $node->evidence,
                $attributes,
            );
        }
        return $facts;
    }
}
