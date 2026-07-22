<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\NestJsRoleRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('nestjs-role-rule')]
final class NestJsRoleRuleTest extends TestCase
{
    public function testIdReturnsConstant(): void
    {
        assertSame('nestjs.decorators.v1', (new NestJsRoleRule())->id());
    }

    public function testClassifyReturnsEmptyForNodeWithoutRolesAttribute(): void
    {
        $node = $this->makeNode(attributes: []);

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForNonArrayRolesAttribute(): void
    {
        $node = $this->makeNode(attributes: ['nestjs_roles' => 'not-an-array']);

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForEmptyRolesArray(): void
    {
        // Empty array should fall through the foreach body without emitting facts.
        $node = $this->makeNode(attributes: ['nestjs_roles' => []]);

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsModuleRole(): void
    {
        $node = $this->makeNode(attributes: ['nestjs_roles' => ['nestjs.module']]);

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('nestjs.module', $facts[0]->role);
    }

    public function testClassifyReturnsControllerRole(): void
    {
        $node = $this->makeNode(attributes: ['nestjs_roles' => ['nestjs.controller']]);

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('nestjs.controller', $facts[0]->role);
    }

    public function testClassifyReturnsProviderRole(): void
    {
        $node = $this->makeNode(attributes: ['nestjs_roles' => ['nestjs.provider']]);

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('nestjs.provider', $facts[0]->role);
    }

    public function testClassifyReturnsMultipleFactsForMultipleValidRoles(): void
    {
        $node = $this->makeNode(attributes: ['nestjs_roles' => ['nestjs.module', 'nestjs.controller']]);

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame(2, count($facts));
        $roles = array_map(static fn ($f): string => $f->role, $facts);
        assertArrayContains('nestjs.module', $roles);
        assertArrayContains('nestjs.controller', $roles);
    }

    public function testClassifySkipsUnknownRole(): void
    {
        $node = $this->makeNode(attributes: ['nestjs_roles' => ['mystery-role']]);

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifySkipsUnknownRoleAndKeepsKnownRole(): void
    {
        $node = $this->makeNode(attributes: ['nestjs_roles' => ['mystery', 'nestjs.provider']]);

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('nestjs.provider', $facts[0]->role);
    }

    public function testClassifySkipsNonStringEntriesAndKeepsStringEntries(): void
    {
        // array_unique preserves type, so an int and string 'nestjs.module' both survive.
        $node = $this->makeNode(attributes: ['nestjs_roles' => [123, null, 'nestjs.module']]);

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('nestjs.module', $facts[0]->role);
    }

    public function testClassifyReturnsEmptyWhenAllEntriesAreNonStrings(): void
    {
        $node = $this->makeNode(attributes: ['nestjs_roles' => [42, null, false]]);

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyDedupesIdenticalRoles(): void
    {
        // array_unique collapses duplicates before the in_array whitelist check.
        $node = $this->makeNode(attributes: ['nestjs_roles' => ['nestjs.module', 'nestjs.module']]);

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('nestjs.module', $facts[0]->role);
    }

    public function testClassifyEachFactCarriesDecoratorEvidence(): void
    {
        $node = $this->makeNode(attributes: ['nestjs_roles' => ['nestjs.module', 'nestjs.controller']]);

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame(2, count($facts));
        foreach ($facts as $fact) {
            assertSame(['source' => '@nestjs/common decorator'], $fact->attributes);
        }
    }

    public function testClassifyUsesFrameworkConventionOriginAndCertainConfidence(): void
    {
        $node = $this->makeNode(attributes: ['nestjs_roles' => ['nestjs.module']]);

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame(Origin::FrameworkConvention, $facts[0]->origin);
        assertSame(Confidence::Certain, $facts[0]->confidence);
    }

    public function testClassifyPropagatesLocalIdAndRuleId(): void
    {
        $node = $this->makeNode(
            localId: 'ts:class:AppUsersModule',
            attributes: ['nestjs_roles' => ['nestjs.module']],
        );

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame('ts:class:AppUsersModule', $facts[0]->nodeReference);
        assertSame('nestjs.decorators.v1', $facts[0]->ruleId);
    }

    public function testClassifyPropagatesNodeEvidence(): void
    {
        $node = $this->makeNode(
            localId: 'ts:class:HomeController',
            attributes: ['nestjs_roles' => ['nestjs.controller']],
            relativePath: 'src/home.controller.ts',
        );

        $facts = (new NestJsRoleRule())->classify($node);

        assertSame('src/home.controller.ts', $facts[0]->evidence->relativePath);
    }

    // ----- helpers -----

    private function makeNode(
        string $localId = 'ts:class:UsersModule',
        string $kind = 'class',
        array $attributes = [],
        string $relativePath = 'src/users/users.module.ts',
    ): NodeFact {
        return new NodeFact(
            $localId,
            $kind,
            'UsersModule',
            'UsersModule',
            Origin::Ast,
            Confidence::Certain,
            new Evidence($relativePath, 1, 10),
            $attributes,
        );
    }
}
