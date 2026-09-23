<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\SymfonyRoleRule;
use Knossos\Query\ReportableComponent;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Symfony autoconfigures what extends its base classes: a console command, a
 * Doctrine fixture, a security voter, a form type, a Twig extension. None is
 * referenced by name, and the roles the rule did assign were never counted as
 * conventions, so every one read as probably dead. A Doctrine entity's
 * accessors are called by the serializer, forms and Twig through reflection:
 * possibly dead at most, never probably.
 */
#[Group('classification')]
final class SymfonyConventionsTest extends KnossosTestCase
{
    /** @return array<string, array{string, string}> */
    public static function autoconfigured(): array
    {
        return [
            'console command' => ['Symfony\\Component\\Console\\Command\\Command', 'symfony.command'],
            'doctrine fixture' => ['Doctrine\\Bundle\\FixturesBundle\\Fixture', 'symfony.fixture'],
            'security voter' => ['Symfony\\Component\\Security\\Core\\Authorization\\Voter\\Voter', 'symfony.voter'],
            'form type' => ['Symfony\\Component\\Form\\AbstractType', 'symfony.form_type'],
            'twig extension' => ['Twig\\Extension\\AbstractExtension', 'symfony.twig_extension'],
            'controller' => ['Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController', 'symfony.controller'],
        ];
    }

    #[DataProvider('autoconfigured')]
    public function testAnAutoconfiguredClassIsAConvention(string $parent, string $role): void
    {
        $facts = (new SymfonyRoleRule())->classify($this->node('class', 'App\\X', 'X', ['extends' => $parent]));

        self::assertSame([$role], array_map(static fn($fact): string => $fact->role, $facts));
        self::assertTrue(ReportableComponent::isDiscoveredByConvention([$role]));
    }

    public function testAnEntityAccessorIsAFrameworkRoleButNoConvention(): void
    {
        $rule = new SymfonyRoleRule();

        $facts = $rule->classify($this->node('method', 'App\\Entity\\Addon::getName', 'getName'));
        self::assertSame(['doctrine.entity_accessor'], array_map(static fn($fact): string => $fact->role, $facts));
        self::assertSame(Origin::FrameworkConvention, $facts[0]->origin);
        self::assertFalse(ReportableComponent::isDiscoveredByConvention(['doctrine.entity_accessor']));

        // An ordinary method of an entity, and an accessor outside one, get nothing.
        self::assertSame([], $rule->classify($this->node('method', 'App\\Entity\\Addon::recalculate', 'recalculate')));
        self::assertSame([], $rule->classify($this->node('method', 'App\\Service\\Pricing::getName', 'getName')));
    }

    /** @param array<string, mixed> $attributes */
    private function node(string $kind, string $canonical, string $display, array $attributes = []): NodeFact
    {
        return new NodeFact('php:' . $kind . ':' . $canonical, $kind, $canonical, $display, Origin::Ast, Confidence::Certain, new Evidence('src/X.php', 1, 1), $attributes);
    }
}
