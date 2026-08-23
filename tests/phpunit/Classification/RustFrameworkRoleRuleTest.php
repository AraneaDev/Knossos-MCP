<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\RustFrameworkRoleRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('rust-framework-role-rule')]
final class RustFrameworkRoleRuleTest extends TestCase
{
    public function testIdReturnsConstant(): void
    {
        assertSame('rust.framework.ast.v1', (new RustFrameworkRoleRule())->id());
    }

    public function testClassifyReturnsEmptyWithoutRolesAttribute(): void
    {
        assertSame([], (new RustFrameworkRoleRule())->classify($this->makeNode()));
    }

    public function testClassifyReturnsEmptyForNonArrayRoles(): void
    {
        $node = $this->makeNode(attributes: ['rust_framework_roles' => 'not-an-array']);

        assertSame([], (new RustFrameworkRoleRule())->classify($node));
    }

    public function testClassifyEmitsRouteHandlerRole(): void
    {
        $node = $this->makeNode(attributes: ['rust_framework_roles' => ['rust.route_handler']]);

        $facts = (new RustFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('rust.route_handler', $facts[0]->role);
        assertSame('rust.framework.ast.v1', $facts[0]->ruleId);
        assertSame(Origin::FrameworkConvention, $facts[0]->origin);
        assertSame(Confidence::Certain, $facts[0]->confidence);
    }

    public function testClassifySkipsUnknownAndNonStringRoles(): void
    {
        $node = $this->makeNode(attributes: [
            'rust_framework_roles' => [null, 42, 'rust.unknown', 'rust.route_handler'],
        ]);

        $facts = (new RustFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('rust.route_handler', $facts[0]->role);
    }

    public function testClassifyDeduplicatesRole(): void
    {
        $node = $this->makeNode(attributes: [
            'rust_framework_roles' => ['rust.route_handler', 'rust.route_handler'],
        ]);

        $facts = (new RustFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyCarriesWorkerEvidence(): void
    {
        $node = $this->makeNode(
            localId: 'rust:function:crate::health',
            relativePath: 'src/routes.rs',
            attributes: ['rust_framework_roles' => ['rust.route_handler']],
        );

        $facts = (new RustFrameworkRoleRule())->classify($node);

        assertSame('rust:function:crate::health', $facts[0]->nodeReference);
        assertSame('src/routes.rs', $facts[0]->evidence->relativePath);
        assertSame(['source' => 'rust AST framework route'], $facts[0]->attributes);
    }

    private function makeNode(
        string $localId = 'rust:function:crate::health',
        string $relativePath = 'src/main.rs',
        array $attributes = [],
    ): NodeFact {
        return new NodeFact(
            $localId,
            'function',
            'crate::health',
            'health',
            Origin::Ast,
            Confidence::Certain,
            new Evidence($relativePath, 4, 4),
            $attributes,
        );
    }
}
