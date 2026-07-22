<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\TypeScriptFrameworkRoleRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('typescript-framework-role-rule')]
#[CoversClass(TypeScriptFrameworkRoleRule::class)]
final class TypeScriptFrameworkRoleRuleTest extends TestCase
{
    public function testIdReturnsConstant(): void
    {
        assertSame('typescript.application.v1', (new TypeScriptFrameworkRoleRule())->id());
    }

    public function testClassifyReturnsEmptyForNodeWithoutRolesAttribute(): void
    {
        $node = $this->makeNode(attributes: []);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForNonArrayRolesAttribute(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => 'oops-not-array']);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForEmptyRolesArray(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => []]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyEmitsNextjsLayoutRole(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => ['nextjs.layout']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('nextjs.layout', $facts[0]->role);
    }

    public function testClassifyEmitsNextjsPageRole(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => ['nextjs.page']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('nextjs.page', $facts[0]->role);
    }

    public function testClassifyEmitsNextjsRouteHandlerRole(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => ['nextjs.route_handler']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('nextjs.route_handler', $facts[0]->role);
    }

    public function testClassifyEmitsNextjsServerActionRole(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => ['nextjs.server_action']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('nextjs.server_action', $facts[0]->role);
    }

    public function testClassifyEmitsReactComponentRole(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => ['react.component']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('react.component', $facts[0]->role);
    }

    public function testClassifyEmitsReactHookRole(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => ['react.hook']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('react.hook', $facts[0]->role);
    }

    public function testClassifyEmitsStateStoreRole(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => ['state.store']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('state.store', $facts[0]->role);
    }

    public function testClassifyEmitsVueComponentRole(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => ['vue.component']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('vue.component', $facts[0]->role);
    }

    public function testClassifyEmitsVueComposableRole(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => ['vue.composable']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('vue.composable', $facts[0]->role);
    }

    public function testClassifyEmitsMultipleFactsWhenMultipleRolesAreValid(): void
    {
        // One role per framework family — 3 facts.
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => [
            'nextjs.page',
            'react.component',
            'vue.component',
        ]]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(3, count($facts));
        $roles = array_map(static fn ($f): string => $f->role, $facts);
        assertArrayContains('nextjs.page', $roles);
        assertArrayContains('react.component', $roles);
        assertArrayContains('vue.component', $roles);
    }

    public function testClassifyEmitsAllNineRolesInSinglePass(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => [
            'nextjs.layout',
            'nextjs.page',
            'nextjs.route_handler',
            'nextjs.server_action',
            'react.component',
            'react.hook',
            'state.store',
            'vue.component',
            'vue.composable',
        ]]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(9, count($facts));
    }

    public function testClassifySkipsUnknownRole(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => ['angular.component']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifySkipsUnknownRoleAndKeepsKnownRole(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => ['svelte.component', 'react.hook']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('react.hook', $facts[0]->role);
    }

    public function testClassifySkipsNonStringEntriesAndKeepsStringEntries(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => [42, null, 'react.component']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('react.component', $facts[0]->role);
    }

    public function testClassifyReturnsEmptyWhenAllEntriesAreNonStrings(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => [1, 0, false, null]]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyDedupesIdenticalRoles(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => ['react.component', 'react.component']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('react.component', $facts[0]->role);
    }

    public function testClassifyEachFactCarriesCompilerSyntaxEvidence(): void
    {
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => ['react.component', 'vue.component']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(2, count($facts));
        foreach ($facts as $fact) {
            assertSame(['source' => 'compiler syntax and application convention'], $fact->attributes);
        }
    }

    public function testClassifyUsesFrameworkConventionOriginAndProbableConfidence(): void
    {
        // Probable (NOT Certain) — distinguishes TypeScriptFramework from NestJs/Python/Symfony/Laravel.
        $node = $this->makeNode(attributes: ['typescript_framework_roles' => ['react.component']]);

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame(Origin::FrameworkConvention, $facts[0]->origin);
        assertSame(Confidence::Probable, $facts[0]->confidence);
    }

    public function testClassifyPropagatesLocalIdAndRuleId(): void
    {
        $node = $this->makeNode(
            localId: 'ts:class:App\\Components\\UserCard',
            attributes: ['typescript_framework_roles' => ['react.component']],
        );

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame('ts:class:App\\Components\\UserCard', $facts[0]->nodeReference);
        assertSame('typescript.application.v1', $facts[0]->ruleId);
    }

    public function testClassifyPropagatesNodeEvidence(): void
    {
        $node = $this->makeNode(
            localId: 'ts:class:App\\Hooks\\useAuth',
            attributes: ['typescript_framework_roles' => ['react.hook']],
            relativePath: 'src/hooks/useAuth.ts',
        );

        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);

        assertSame('src/hooks/useAuth.ts', $facts[0]->evidence->relativePath);
    }

    // ----- helpers -----

    private function makeNode(
        string $localId = 'ts:class:App\\Components\\AppComponent',
        string $kind = 'class',
        array $attributes = [],
        string $relativePath = 'src/components/AppComponent.tsx',
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
