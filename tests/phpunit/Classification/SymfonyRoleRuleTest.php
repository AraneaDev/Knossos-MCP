<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\SymfonyRoleRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('symfony-role-rule')]
final class SymfonyRoleRuleTest extends TestCase
{
    public function testIdReturnsConstant(): void
    {
        assertSame('symfony.explicit.v1', (new SymfonyRoleRule())->id());
    }

    public function testClassifyReturnsEmptyForIneligibleKindInterface(): void
    {
        // Only 'class' and 'method' kinds are eligible.
        $node = $this->makeNode(kind: 'interface');

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForIneligibleKindProperty(): void
    {
        $node = $this->makeNode(kind: 'property');

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForClassWithoutAnyRelevantAttributes(): void
    {
        $node = $this->makeNode(kind: 'class', attributes: []);

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForClassWithUnrelatedExtends(): void
    {
        $node = $this->makeNode(
            kind: 'class',
            attributes: ['extends' => 'Symfony\\Component\\HttpFoundation\\Response'],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsControllerRoleWhenExtendsAbstractController(): void
    {
        $node = $this->makeNode(
            kind: 'class',
            canonicalName: 'App\\Controller\\HomeController',
            attributes: ['extends' => 'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController'],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('symfony.controller', $facts[0]->role);
        assertSame('symfony.explicit.v1', $facts[0]->ruleId);
        assertSame('extends', $facts[0]->attributes['source']);
        assertSame('Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController', $facts[0]->attributes['target']);
    }

    public function testClassifyReturnsEventSubscriberRoleWhenImplementsEventSubscriberInterface(): void
    {
        $node = $this->makeNode(
            kind: 'class',
            attributes: ['implements' => ['Symfony\\Component\\EventDispatcher\\EventSubscriberInterface']],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('symfony.event_subscriber', $facts[0]->role);
        assertSame('implements', $facts[0]->attributes['source']);
        assertSame('Symfony\\Component\\EventDispatcher\\EventSubscriberInterface', $facts[0]->attributes['target']);
    }

    public function testClassifyReturnsMessageHandlerRoleWhenImplementsMessageHandlerInterface(): void
    {
        $node = $this->makeNode(
            kind: 'class',
            attributes: ['implements' => ['Symfony\\Messenger\\Handler\\MessageHandlerInterface']],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('symfony.message_handler', $facts[0]->role);
    }

    public function testClassifyReturnsEmptyForUnknownInterfaceInImplements(): void
    {
        $node = $this->makeNode(
            kind: 'class',
            attributes: ['implements' => ['Psr\\Log\\LoggerInterface']],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifySkipsNonStringEntryInImplementsArray(): void
    {
        $node = $this->makeNode(
            kind: 'class',
            attributes: ['implements' => [123, null, 'Symfony\\Component\\EventDispatcher\\EventSubscriberInterface']],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('symfony.event_subscriber', $facts[0]->role);
    }

    public function testClassifyReturnsEmptyWhenImplementsIsNotAnArray(): void
    {
        $node = $this->makeNode(
            kind: 'class',
            attributes: ['implements' => 'not-an-array'],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsCommandRoleForAsCommandAttribute(): void
    {
        $node = $this->makeNode(
            kind: 'method',
            attributes: ['php_attributes' => ['Symfony\\Component\\Console\\Attribute\\AsCommand']],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('symfony.command', $facts[0]->role);
    }

    public function testClassifyReturnsEventListenerRoleForAsEventListenerAttribute(): void
    {
        $node = $this->makeNode(
            kind: 'method',
            attributes: ['php_attributes' => ['Symfony\\Component\\EventDispatcher\\Attribute\\AsEventListener']],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('symfony.event_listener', $facts[0]->role);
    }

    public function testClassifyReturnsMessageHandlerRoleForAsMessageHandlerAttribute(): void
    {
        $node = $this->makeNode(
            kind: 'method',
            attributes: ['php_attributes' => ['Symfony\\Messenger\\Attribute\\AsMessageHandler']],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('symfony.message_handler', $facts[0]->role);
    }

    public function testClassifyReturnsRouteHandlerRoleForRouteAttribute(): void
    {
        $node = $this->makeNode(
            kind: 'method',
            attributes: ['php_attributes' => ['Symfony\\Component\\Routing\\Attribute\\Route']],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('symfony.route_handler', $facts[0]->role);
    }

    public function testClassifyReturnsServiceRoleForAsAliasAttribute(): void
    {
        $node = $this->makeNode(
            kind: 'class',
            attributes: ['php_attributes' => ['Symfony\\Component\\DependencyInjection\\Attribute\\AsAlias']],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('symfony.service', $facts[0]->role);
    }

    public function testClassifyReturnsServiceRoleForAutoconfigureAttribute(): void
    {
        // Autoconfigure can live in different namespaces — basename extraction normalizes.
        $node = $this->makeNode(
            kind: 'class',
            attributes: ['php_attributes' => ['Symfony\\Component\\DependencyInjection\\Attribute\\Autoconfigure']],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('symfony.service', $facts[0]->role);
    }

    public function testClassifyReturnsEmptyForUnknownPhpAttributeShortName(): void
    {
        $node = $this->makeNode(
            kind: 'method',
            attributes: ['php_attributes' => ['App\\Custom\\Attribute\\SomethingUnknown']],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifySkipsNonStringEntryInPhpAttributesArray(): void
    {
        $node = $this->makeNode(
            kind: 'method',
            attributes: ['php_attributes' => [42, 'Symfony\\Component\\Console\\Attribute\\AsCommand']],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('symfony.command', $facts[0]->role);
    }

    public function testClassifyReturnsEmptyWhenPhpAttributesIsNotAnArray(): void
    {
        $node = $this->makeNode(
            kind: 'method',
            attributes: ['php_attributes' => 'oops-not-an-array'],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyEmitsMultipleFactsWhenMultipleSourcesMatch(): void
    {
        // Symfony class extending AbstractController AND implementing EventSubscriberInterface:
        // both rules fire → 2 facts.
        $node = $this->makeNode(
            kind: 'class',
            canonicalName: 'App\\Controller\\SubscribingController',
            attributes: [
                'extends' => 'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController',
                'implements' => ['Symfony\\Component\\EventDispatcher\\EventSubscriberInterface'],
            ],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(2, count($facts));
        $roles = array_map(static fn ($f): string => $f->role, $facts);
        assertArrayContains('symfony.controller', $roles);
        assertArrayContains('symfony.event_subscriber', $roles);
    }

    public function testClassifyEmitsMultipleFactsForMultiplePhpAttributes(): void
    {
        $node = $this->makeNode(
            kind: 'method',
            attributes: [
                'php_attributes' => [
                    'Symfony\\Component\\Routing\\Attribute\\Route',
                    'Symfony\\Component\\EventDispatcher\\Attribute\\AsEventListener',
                ],
            ],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(2, count($facts));
        $roles = array_map(static fn ($f): string => $f->role, $facts);
        assertArrayContains('symfony.route_handler', $roles);
        assertArrayContains('symfony.event_listener', $roles);
    }

    public function testClassifyInheritsEvidenceFromNode(): void
    {
        $node = $this->makeNode(
            kind: 'class',
            canonicalName: 'App\\Controller\\HomeController',
            relativePath: 'src/Controller/HomeController.php',
            startLine: 5,
            endLine: 42,
            attributes: ['extends' => 'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController'],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('src/Controller/HomeController.php', $facts[0]->evidence->relativePath);
        assertSame(5, $facts[0]->evidence->startLine);
        assertSame(42, $facts[0]->evidence->endLine);
    }

    public function testClassifyUsesFrameworkConventionOriginAndCertainConfidence(): void
    {
        $node = $this->makeNode(
            kind: 'class',
            attributes: ['extends' => 'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController'],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(Origin::FrameworkConvention, $facts[0]->origin);
        assertSame(Confidence::Certain, $facts[0]->confidence);
    }

    public function testClassifyMatchesAbstractControllerSuffixRegardlessOfNamespace(): void
    {
        // str_ends_with matches the simple class name 'AbstractController' regardless of full FQN.
        $node = $this->makeNode(
            kind: 'class',
            attributes: ['extends' => 'Vendor\\Framework\\Controller\\AbstractController'],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('symfony.controller', $facts[0]->role);
    }

    public function testClassifyHandlesEmptyExtendsValueAsNotMatching(): void
    {
        $node = $this->makeNode(
            kind: 'class',
            attributes: ['extends' => ''],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyHandlesEmptyImplementsArray(): void
    {
        $node = $this->makeNode(
            kind: 'class',
            attributes: ['implements' => []],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyHandlesEmptyPhpAttributesArray(): void
    {
        $node = $this->makeNode(
            kind: 'class',
            attributes: ['php_attributes' => []],
        );

        $facts = (new SymfonyRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyRespectsNodeKindMethodForAttributePath(): void
    {
        // Method nodes can also have php_attributes that should classify even though
        // they're not 'class' kind. The kind check accepts class OR method.
        $nodeMethod = $this->makeNode(
            kind: 'method',
            attributes: ['php_attributes' => ['Symfony\\Component\\Console\\Attribute\\AsCommand']],
        );
        $nodeClass = $this->makeNode(
            kind: 'class',
            attributes: ['php_attributes' => ['Symfony\\Component\\Console\\Attribute\\AsCommand']],
        );

        $rule = new SymfonyRoleRule();

        assertSame(1, count($rule->classify($nodeMethod)));
        assertSame(1, count($rule->classify($nodeClass)));
    }

    // ----- helpers -----

    private function makeNode(
        string $kind,
        string $canonicalName = 'App\\Service\\Foo',
        array $attributes = [],
        string $relativePath = 'src/Service/Foo.php',
        int $startLine = 1,
        int $endLine = 10,
    ): NodeFact {
        $lastSlash = strrpos($canonicalName, '\\');
        $displayName = (false === $lastSlash)
            ? $canonicalName
            : substr($canonicalName, $lastSlash + 1);

        return new NodeFact(
            'php:' . $kind . ':' . $canonicalName,
            $kind,
            $canonicalName,
            $displayName,
            Origin::Ast,
            Confidence::Certain,
            new Evidence($relativePath, $startLine, $endLine),
            $attributes,
        );
    }
}
