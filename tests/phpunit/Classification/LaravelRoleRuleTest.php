<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\LaravelRoleRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('laravel-role-rule')]
final class LaravelRoleRuleTest extends TestCase
{
    public function testIdReturnsConstant(): void
    {
        assertSame('laravel.explicit.types.v1', (new LaravelRoleRule())->id());
    }

    public function testClassifyReturnsEmptyForMethodKind(): void
    {
        $node = $this->makeNode(kind: 'method', attributes: ['extends' => 'Illuminate\\Routing\\Controller']);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForFunctionKind(): void
    {
        $node = $this->makeNode(kind: 'function', attributes: ['extends' => 'Illuminate\\Routing\\Controller']);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForFileKind(): void
    {
        $node = $this->makeNode(kind: 'file', attributes: ['extends' => 'Illuminate\\Routing\\Controller']);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForClassWithoutRelevantAttributes(): void
    {
        $node = $this->makeNode(attributes: []);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForUnrelatedExtends(): void
    {
        $node = $this->makeNode(attributes: ['extends' => 'App\\Models\\InternalThing']);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForUnrelatedImplements(): void
    {
        $node = $this->makeNode(attributes: ['implements' => ['Psr\\Log\\LoggerInterface']]);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyWhenImplementsIsNotArray(): void
    {
        $node = $this->makeNode(attributes: ['implements' => 'oops-not-an-array']);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsControllerRoleForRoutingControllerExtends(): void
    {
        $node = $this->makeNode(attributes: ['extends' => 'Illuminate\\Routing\\Controller']);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('laravel.controller', $facts[0]->role);
        assertSame('extends', $facts[0]->attributes['relation']);
        assertSame('Illuminate\\Routing\\Controller', $facts[0]->attributes['target']);
    }

    public function testClassifyReturnsModelRoleForEloquentModelExtends(): void
    {
        $node = $this->makeNode(attributes: ['extends' => 'Illuminate\\Database\\Eloquent\\Model']);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('laravel.model', $facts[0]->role);
    }

    public function testClassifyReturnsCommandRoleForFoundationCommandExtends(): void
    {
        $node = $this->makeNode(attributes: ['extends' => 'Illuminate\\Foundation\\Console\\Command']);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('laravel.command', $facts[0]->role);
    }

    public function testClassifyReturnsCommandRoleForIlluminateCommandExtends(): void
    {
        // Both Illuminate\Foundation\Console\Command and Illuminate\Console\Command map to laravel.command.
        $node = $this->makeNode(attributes: ['extends' => 'Illuminate\\Console\\Command']);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('laravel.command', $facts[0]->role);
    }

    public function testClassifyReturnsProviderRoleForServiceProviderExtends(): void
    {
        $node = $this->makeNode(attributes: ['extends' => 'Illuminate\\Support\\ServiceProvider']);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('laravel.provider', $facts[0]->role);
    }

    public function testClassifyReturnsProviderRoleForEventServiceProviderExtends(): void
    {
        $node = $this->makeNode(attributes: ['extends' => 'Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider']);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('laravel.provider', $facts[0]->role);
    }

    public function testClassifyReturnsMiddlewareRoleForHttpMiddlewareExtends(): void
    {
        $node = $this->makeNode(attributes: ['extends' => 'Illuminate\\Foundation\\Http\\Middleware']);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('laravel.middleware', $facts[0]->role);
    }

    public function testClassifyReturnsQueuedRoleForShouldQueueImplements(): void
    {
        $node = $this->makeNode(attributes: ['implements' => ['Illuminate\\Contracts\\Queue\\ShouldQueue']]);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('laravel.queued', $facts[0]->role);
        assertSame('implements', $facts[0]->attributes['relation']);
        assertSame('Illuminate\\Contracts\\Queue\\ShouldQueue', $facts[0]->attributes['target']);
    }

    public function testClassifyReturnsEventDispatcherRoleForDispatcherImplements(): void
    {
        $node = $this->makeNode(attributes: ['implements' => ['Illuminate\\Contracts\\Events\\Dispatcher']]);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('laravel.event_dispatcher', $facts[0]->role);
    }

    public function testClassifyReturnsMultipleFactsForExtendsPlusImplements(): void
    {
        // A Controller that also implements ShouldQueue — both rules fire → 2 facts.
        $node = $this->makeNode(attributes: [
            'extends' => 'Illuminate\\Routing\\Controller',
            'implements' => ['Illuminate\\Contracts\\Queue\\ShouldQueue'],
        ]);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame(2, count($facts));
        $roles = array_map(static fn ($f): string => $f->role, $facts);
        assertArrayContains('laravel.controller', $roles);
        assertArrayContains('laravel.queued', $roles);
    }

    public function testClassifyReturnsMultipleFactsForMultipleKnownInterfaces(): void
    {
        // Two known interfaces → two facts (queued + event_dispatcher).
        $node = $this->makeNode(attributes: ['implements' => [
            'Illuminate\\Contracts\\Queue\\ShouldQueue',
            'Illuminate\\Contracts\\Events\\Dispatcher',
        ]]);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame(2, count($facts));
        $roles = array_map(static fn ($f): string => $f->role, $facts);
        assertArrayContains('laravel.queued', $roles);
        assertArrayContains('laravel.event_dispatcher', $roles);
    }

    public function testClassifyReturnsOneFactWhenOneOfTwoInterfacesIsUnknown(): void
    {
        // Mixed array: one known (ShouldQueue) + one unknown (LoggerInterface) → 1 fact.
        $node = $this->makeNode(attributes: ['implements' => [
            'Illuminate\\Contracts\\Queue\\ShouldQueue',
            'Psr\\Log\\LoggerInterface',
        ]]);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('laravel.queued', $facts[0]->role);
    }

    public function testClassifyReturnsEmptyWhenAllInterfacesAreUnknown(): void
    {
        $node = $this->makeNode(attributes: ['implements' => ['Psr\\Log\\LoggerInterface', 'ArrayAccess']]);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifySkipsNonStringEntriesInImplementsArray(): void
    {
        // Mixed-type implements array — only the string ShouldQueue should emit.
        $node = $this->makeNode(attributes: ['implements' => [123, null, 'Illuminate\\Contracts\\Queue\\ShouldQueue']]);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('laravel.queued', $facts[0]->role);
    }

    public function testClassifyReturnsEmptyWhenImplementsArrayIsEmpty(): void
    {
        $node = $this->makeNode(attributes: ['implements' => []]);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForNonStringExtends(): void
    {
        // If the AST emitter hands us an integer for `extends`, the is_string guard rejects it.
        $node = $this->makeNode(attributes: ['extends' => 42]);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyUsesFrameworkConventionOriginAndCertainConfidence(): void
    {
        $node = $this->makeNode(attributes: ['extends' => 'Illuminate\\Routing\\Controller']);

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame(Origin::FrameworkConvention, $facts[0]->origin);
        assertSame(Confidence::Certain, $facts[0]->confidence);
    }

    public function testClassifyPropagatesLocalIdAndRuleId(): void
    {
        $node = $this->makeNode(
            localId: 'php:class:App\\Http\\Controllers\\HomeController',
            attributes: ['extends' => 'Illuminate\\Routing\\Controller'],
        );

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame('php:class:App\\Http\\Controllers\\HomeController', $facts[0]->nodeReference);
        assertSame('laravel.explicit.types.v1', $facts[0]->ruleId);
    }

    public function testClassifyPropagatesNodeEvidence(): void
    {
        $node = $this->makeNode(
            localId: 'php:class:App\\Models\\User',
            attributes: ['extends' => 'Illuminate\\Database\\Eloquent\\Model'],
            relativePath: 'app/Models/User.php',
        );

        $facts = (new LaravelRoleRule())->classify($node);

        assertSame('app/Models/User.php', $facts[0]->evidence->relativePath);
    }

    // ----- helpers -----

    private function makeNode(
        string $localId = 'php:class:App\\Controller\\Foo',
        string $kind = 'class',
        array $attributes = [],
        string $relativePath = 'app/Http/Controllers/Foo.php',
    ): NodeFact {
        $lastSlash = strrpos($localId, '\\');
        $displayName = (false === $lastSlash)
            ? substr($localId, strrpos($localId, ':') + 1)
            : substr($localId, $lastSlash + 1);

        return new NodeFact(
            $localId,
            $kind,
            $localId,
            $displayName,
            Origin::Ast,
            Confidence::Certain,
            new Evidence($relativePath, 1, 10),
            $attributes,
        );
    }
}
