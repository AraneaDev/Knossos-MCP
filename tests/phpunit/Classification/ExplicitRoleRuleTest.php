<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\ExplicitRoleRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('explicit-role-rule')]
final class ExplicitRoleRuleTest extends TestCase
{
    public function testIdReturnsConstructorValue(): void
    {
        $rule = new ExplicitRoleRule('explicit-role-test', []);

        assertSame('explicit-role-test', $rule->id());
    }

    public function testClassifyReturnsEmptyForUnknownCanonicalName(): void
    {
        $rule = new ExplicitRoleRule('explicit-role-test', [
            'App\\KnownService' => ['application.service'],
        ]);

        $node = $this->makeNode(canonicalName: 'App\\UnknownService');

        $facts = $rule->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyForCanonicalNameWithEmptyRolesList(): void
    {
        $rule = new ExplicitRoleRule('explicit-role-test', [
            'App\\NoRoleAssigned' => [],
        ]);

        $node = $this->makeNode(canonicalName: 'App\\NoRoleAssigned');

        $facts = $rule->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsSingleFactForSingleRole(): void
    {
        $rule = new ExplicitRoleRule('explicit-role-test', [
            'App\\UserService' => ['application.service'],
        ]);

        $node = $this->makeNode(canonicalName: 'App\\UserService');
        $facts = $rule->classify($node);

        assertSame(1, count($facts));
        assertSame('php:class:App\\UserService', $facts[0]->nodeReference);
        assertSame('application.service', $facts[0]->role);
        assertSame('explicit-role-test', $facts[0]->ruleId);
        assertSame(Origin::UserRule, $facts[0]->origin);
        assertSame(Confidence::Certain, $facts[0]->confidence);
    }

    public function testClassifyReturnsMultipleFactsForMultipleRoles(): void
    {
        $rule = new ExplicitRoleRule('explicit-role-test', [
            'App\\Hybrid' => ['application.service', 'data.source'],
        ]);

        $node = $this->makeNode(canonicalName: 'App\\Hybrid');
        $facts = $rule->classify($node);

        assertSame(2, count($facts));
        assertSame('application.service', $facts[0]->role);
        assertSame('data.source', $facts[1]->role);
    }

    public function testFactInheritsEvidenceFromNode(): void
    {
        $rule = new ExplicitRoleRule('explicit-role-test', [
            'App\\UserService' => ['application.service'],
        ]);

        $node = $this->makeNode(
            canonicalName: 'App\\UserService',
            relativePath: 'app/UserService.php',
            startLine: 5,
            endLine: 42,
        );

        $facts = $rule->classify($node);

        assertSame(1, count($facts));
        assertSame('app/UserService.php', $facts[0]->evidence->relativePath);
        assertSame(5, $facts[0]->evidence->startLine);
        assertSame(42, $facts[0]->evidence->endLine);
    }

    public function testRuleWithEmptyRolesMapReturnsEmptyForAnyNode(): void
    {
        $rule = new ExplicitRoleRule('explicit-role-test', []);

        $node = $this->makeNode(canonicalName: 'App\\Anything');
        $facts = $rule->classify($node);

        assertSame([], $facts);
    }

    public function testExplicitRuleOriginAndCertainConfidenceAreImmutable(): void
    {
        // ExplicitRoleRule hardcodes Origin::UserRule + Confidence::Certain — verify they
        // don't depend on the node's own origin/confidence. Construct two nodes inline
        // with deliberately different origin/confidence to make this assertion meaningful.
        $nodeAst = new NodeFact(
            'php:class:A',
            'class',
            'App\\A',
            'A',
            Origin::Ast,
            Confidence::Certain,
            new Evidence('app/A.php', 1, 5),
        );
        $nodeComposer = new NodeFact(
            'php:class:B',
            'class',
            'App\\B',
            'B',
            Origin::Composer,
            Confidence::Possible,
            new Evidence('app/B.php', 1, 5),
        );

        $rule = new ExplicitRoleRule('explicit-role-test', [
            'App\\A' => ['data.x'],
            'App\\B' => ['data.y'],
        ]);

        $factsA = $rule->classify($nodeAst);
        $factsB = $rule->classify($nodeComposer);

        assertSame(Origin::UserRule, $factsA[0]->origin);
        assertSame(Confidence::Certain, $factsA[0]->confidence);
        assertSame(Origin::UserRule, $factsB[0]->origin);
        assertSame(Confidence::Certain, $factsB[0]->confidence);

        // The node's own origin/confidence is intentionally ignored by the rule.
        // If the rule inherited the node's origin (e.g., Origin::Composer for nodeB),
        // assertSame() above would fail with "Failed asserting that Composer is identical to UserRule".
    }

    public function testCustomRuleIdPropagatesToFacts(): void
    {
        $rule = new ExplicitRoleRule('project-specific-roles', [
            'App\\UserService' => ['application.service'],
        ]);

        $node = $this->makeNode(canonicalName: 'App\\UserService');
        $facts = $rule->classify($node);

        assertSame('project-specific-roles', $facts[0]->ruleId);
    }

    public function testMultipleCanonicalNamesEachReturnTheirOwnRoles(): void
    {
        $rule = new ExplicitRoleRule('explicit-role-test', [
            'App\\ServiceA' => ['application.service'],
            'App\\ServiceB' => ['data.source'],
            'App\\ServiceC' => ['infrastructure.transport'],
        ]);

        $nodeA = $this->makeNode(canonicalName: 'App\\ServiceA');
        $nodeB = $this->makeNode(canonicalName: 'App\\ServiceB');
        $nodeC = $this->makeNode(canonicalName: 'App\\ServiceC');

        assertSame('application.service', $rule->classify($nodeA)[0]->role);
        assertSame('data.source', $rule->classify($nodeB)[0]->role);
        assertSame('infrastructure.transport', $rule->classify($nodeC)[0]->role);
    }

    // ----- helpers -----

    /**
     * Build a NodeFact with the canonical name driving localId + displayName.
     * `displayName` is the trailing segment after the last backslash (the FQN leaf).
     * ExplicitRoleRule only reads canonicalName + evidence, so displayName + localId
     * are derived but unused by the rule under test.
     */
    private function makeNode(
        string $canonicalName,
        ?string $kind = null,
        string $relativePath = 'app/Foo.php',
        int $startLine = 1,
        int $endLine = 10,
    ): NodeFact {
        $lastSlash = strrpos($canonicalName, '\\');
        $displayName = (false === $lastSlash)
            ? $canonicalName
            : substr($canonicalName, $lastSlash + 1);

        return new NodeFact(
            'php:class:' . $canonicalName,
            $kind ?? 'class',
            $canonicalName,
            $displayName,
            Origin::Ast,
            Confidence::Certain,
            new Evidence($relativePath, $startLine, $endLine),
        );
    }
}
