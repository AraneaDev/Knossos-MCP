<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

final readonly class SymfonyRoleRule implements ClassificationRule
{
    public function id(): string
    {
        return 'symfony.explicit.v1';
    }

    public function classify(NodeFact $node): array
    {
        if (!in_array($node->kind, ['class', 'method'], true)) {
            return [];
        }
        $roles = [];
        $parent = $node->attributes['extends'] ?? null;
        if (is_string($parent) && str_ends_with($parent, '\\AbstractController')) {
            $roles['symfony.controller'] = ['source' => 'extends', 'target' => $parent];
        }
        $interfaces = $node->attributes['implements'] ?? [];
        if (is_array($interfaces)) {
            foreach ($interfaces as $interface) {
                if (!is_string($interface)) {
                    continue;
                }
                if (str_ends_with($interface, '\\EventSubscriberInterface')) {
                    $roles['symfony.event_subscriber'] = ['source' => 'implements', 'target' => $interface];
                }
                if (str_ends_with($interface, '\\MessageHandlerInterface')) {
                    $roles['symfony.message_handler'] = ['source' => 'implements', 'target' => $interface];
                }
            }
        }
        $attributes = $node->attributes['php_attributes'] ?? [];
        if (is_array($attributes)) {
            foreach ($attributes as $attribute) {
                if (!is_string($attribute)) {
                    continue;
                }
                $short = basename(str_replace('\\', '/', $attribute));
                $role = match ($short) {
                    'AsCommand' => 'symfony.command',
                    'AsEventListener' => 'symfony.event_listener',
                    'AsMessageHandler' => 'symfony.message_handler',
                    'Route' => 'symfony.route_handler',
                    'AsAlias', 'Autoconfigure' => 'symfony.service',
                    default => null,
                };
                if ($role !== null) {
                    $roles[$role] = ['source' => 'attribute', 'target' => $attribute];
                }
            }
        }
        $facts = [];
        foreach ($roles as $role => $evidence) {
            $facts[] = new ClassificationFact($node->localId, $role, $this->id(), Origin::FrameworkConvention, Confidence::Certain, $node->evidence, $evidence);
        }
        return $facts;
    }
}
