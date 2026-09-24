<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/** Infers roles from Symfony attributes and base classes, where that framework declares them. */
final readonly class SymfonyRoleRule implements ClassificationRule
{
    /** Base-class suffix => the role Symfony's autoconfiguration gives what extends it. */
    private const AUTOCONFIGURED_PARENTS = [
        '\\AbstractController' => 'symfony.controller',
        '\\Console\\Command\\Command' => 'symfony.command',
        '\\FixturesBundle\\Fixture' => 'symfony.fixture',
        '\\Authorization\\Voter\\Voter' => 'symfony.voter',
        '\\Form\\AbstractType' => 'symfony.form_type',
        '\\Twig\\Extension\\AbstractExtension' => 'symfony.twig_extension',
        // Not autoconfigured, but found the same way: `doctrine:migrations:migrate`
        // loads every class in the migrations directory.
        '\\Migrations\\AbstractMigration' => 'doctrine.migration',
    ];

    /** {@inheritDoc} */
    public function id(): string
    {
        return 'symfony.explicit.v1';
    }

    /** {@inheritDoc} */
    public function classify(NodeFact $node): array
    {
        if (!in_array($node->kind, ['class', 'method'], true)) {
            return [];
        }
        $roles = [];
        $parent = $node->attributes['extends'] ?? null;
        if (is_string($parent)) {
            // What Symfony autoconfigures from the base class alone.
            $qualified = '\\' . ltrim($parent, '\\');
            foreach (self::AUTOCONFIGURED_PARENTS as $suffix => $role) {
                if (str_ends_with($qualified, $suffix)) {
                    $roles[$role] = ['source' => 'extends', 'target' => $parent];
                }
            }
        }
        // Serializers, forms and Twig call an entity's accessors through
        // reflection, so none is referenced by name. A role, not a convention:
        // it makes an unused one possibly dead rather than hiding it.
        if ($node->kind === 'method'
            && str_contains($node->canonicalName, '\\Entity\\')
            && preg_match('/^(?:get|set|is|has|add|remove)[A-Z_]/', $node->displayName) === 1) {
            $roles['doctrine.entity_accessor'] = ['source' => 'naming', 'target' => $node->displayName];
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
