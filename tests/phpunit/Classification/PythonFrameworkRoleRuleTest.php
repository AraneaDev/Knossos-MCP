<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\PythonFrameworkRoleRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('python-framework-role-rule')]
final class PythonFrameworkRoleRuleTest extends TestCase
{
    public function testIdReturnsConstant(): void
    {
        assertSame('python.framework.ast.v1', (new PythonFrameworkRoleRule())->id());
    }

    public function testClassifyReturnsEmptyForNodeWithoutRolesAttribute(): void
    {
        $node = $this->makeNode(attributes: []);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForNonArrayRolesAttribute(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => 'oops-not-array']);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForEmptyRolesArray(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => []]);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyEmitsDjangoModelRole(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => ['django.model']]);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('django.model', $facts[0]->role);
    }

    public function testClassifyEmitsDjangoViewRole(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => ['django.view']]);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('django.view', $facts[0]->role);
    }

    public function testClassifyEmitsDjangoMiddlewareRole(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => ['django.middleware']]);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('django.middleware', $facts[0]->role);
    }

    public function testClassifyEmitsFastapiRouteHandlerRole(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => ['fastapi.route_handler']]);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('fastapi.route_handler', $facts[0]->role);
    }

    public function testClassifyEmitsPythonTaskRole(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => ['python.task']]);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('python.task', $facts[0]->role);
    }

    public function testClassifyEmitsMultipleFactsForMultipleValidRoles(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => ['django.model', 'python.task']]);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame(2, count($facts));
        $roles = array_map(static fn ($f): string => $f->role, $facts);
        assertArrayContains('django.model', $roles);
        assertArrayContains('python.task', $roles);
    }

    public function testClassifySkipsUnknownRole(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => ['not.in.whitelist']]);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifySkipsUnknownRoleAndKeepsKnownRole(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => ['pylons.dispatcher', 'fastapi.route_handler']]);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('fastapi.route_handler', $facts[0]->role);
    }

    public function testClassifySkipsNonStringEntriesAndKeepsStringEntries(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => [0, false, 'python.task']]);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('python.task', $facts[0]->role);
    }

    public function testClassifyReturnsEmptyWhenAllEntriesAreNonStrings(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => [10, null, true]]);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyDedupesIdenticalRoles(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => ['django.model', 'django.model']]);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('django.model', $facts[0]->role);
    }

    public function testClassifyEachFactCarriesPythonAstEvidence(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => ['django.model', 'python.task']]);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame(2, count($facts));
        foreach ($facts as $fact) {
            assertSame(['source' => 'python AST decorator/base'], $fact->attributes);
        }
    }

    public function testClassifyUsesFrameworkConventionOriginAndCertainConfidence(): void
    {
        $node = $this->makeNode(attributes: ['python_framework_roles' => ['django.model']]);

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame(Origin::FrameworkConvention, $facts[0]->origin);
        assertSame(Confidence::Certain, $facts[0]->confidence);
    }

    public function testClassifyPropagatesLocalIdAndRuleId(): void
    {
        $node = $this->makeNode(
            localId: 'python:class:app.models.User',
            attributes: ['python_framework_roles' => ['django.model']],
        );

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame('python:class:app.models.User', $facts[0]->nodeReference);
        assertSame('python.framework.ast.v1', $facts[0]->ruleId);
    }

    public function testClassifyPropagatesNodeEvidence(): void
    {
        $node = $this->makeNode(
            localId: 'python:module:app.views',
            attributes: ['python_framework_roles' => ['django.view']],
            relativePath: 'src/app/views.py',
        );

        $facts = (new PythonFrameworkRoleRule())->classify($node);

        assertSame('src/app/views.py', $facts[0]->evidence->relativePath);
    }

    // ----- helpers -----

    private function makeNode(
        string $localId = 'python:class:app.models.User',
        string $kind = 'class',
        array $attributes = [],
        string $relativePath = 'src/app/models.py',
    ): NodeFact {
        return new NodeFact(
            $localId,
            $kind,
            'app.models.User',
            'User',
            Origin::Ast,
            Confidence::Certain,
            new Evidence($relativePath, 1, 10),
            $attributes,
        );
    }
}
