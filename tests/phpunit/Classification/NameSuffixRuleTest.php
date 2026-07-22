<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\NameSuffixRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('name-suffix-rule')]
final class NameSuffixRuleTest extends TestCase
{
    public function testIdReturnsConstructorValue(): void
    {
        $rule = $this->makeRule(ruleId: 'name-suffix-test');

        assertSame('name-suffix-test', $rule->id());
    }

    public function testClassifyReturnsEmptyForIneligibleKind(): void
    {
        // Function/interface kinds are NOT in the default eligible kinds (default = ['class']).
        $node = $this->makeNode(kind: 'function', displayName: 'AppController');

        $facts = $this->makeRule()->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsEmptyWhenNoSuffixMatches(): void
    {
        $node = $this->makeNode(kind: 'class', displayName: 'App\\Plain');

        $facts = $this->makeRule()->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyReturnsFactForMatchingSuffix(): void
    {
        // displayName 'UserController' ends with 'Controller' → role 'application.controller'
        $node = $this->makeNode(
            kind: 'class',
            displayName: 'UserController',
            canonicalName: 'App\\UserController',
            localId: 'php:class:App\\UserController',
        );

        $facts = $this->makeRule()->classify($node);

        assertSame(1, count($facts));
        assertSame('php:class:App\\UserController', $facts[0]->nodeReference);
        assertSame('application.controller', $facts[0]->role);
        assertSame('name-suffix-default', $facts[0]->ruleId);
        assertSame(['matched_suffix' => 'Controller'], $facts[0]->attributes);
    }

    public function testClassifyReturnsMultipleFactsWhenMultipleSuffixesMatch(): void
    {
        // For a single displayName to match multiple entries, the suffixes must be
        // nested such that one IS a suffix of the other (and the other matches last).
        // 'UserServiceImpl' ends with both 'Impl' (last 4 chars) AND 'ServiceImpl' (last 11 chars).
        // Note: 'ServiceImpl' does NOT end with 'Service' — str_ends_with is conservative.
        $node = $this->makeNode(kind: 'class', displayName: 'UserServiceImpl');

        $suffixes = [
            'Impl' => 'infrastructure.implementation',
            'ServiceImpl' => 'application.service',
        ];

        $rule = new NameSuffixRule('name-suffix-multi', $suffixes);
        $facts = $rule->classify($node);

        assertSame(2, count($facts));
        $roles = array_map(static fn ($f): string => $f->role, $facts);
        assertSame(['infrastructure.implementation', 'application.service'], $roles);
        $suffixesMatched = array_map(static fn ($f): string => $f->attributes['matched_suffix'], $facts);
        assertSame(['Impl', 'ServiceImpl'], $suffixesMatched);
    }

    public function testClassifySkipsEmptySuffixInIteration(): void
    {
        // An empty suffix in suffixRoles would false-positive match ANY name.
        // The source's `if ($suffix !== '' && ...)` guard prevents this.
        $suffixes = [
            '' => 'application.empty',          // should be skipped
            'Controller' => 'application.controller',
        ];

        $node = $this->makeNode(kind: 'class', displayName: 'UserController');
        $facts = (new NameSuffixRule('name-suffix-empty-guard', $suffixes))->classify($node);

        assertSame(1, count($facts));
        assertSame('application.controller', $facts[0]->role);
        assertSame(['matched_suffix' => 'Controller'], $facts[0]->attributes);
    }

    public function testCustomEligibleKindsIsRespected(): void
    {
        // Eligible kinds = ['interface'] instead of default ['class']
        $node = $this->makeNode(kind: 'interface', displayName: 'UserRepository');

        $rule = new NameSuffixRule(
            ruleId: 'name-suffix-interface',
            suffixRoles: ['Repository' => 'data.repository'],
            eligibleKinds: ['interface'],
            origin: Origin::FrameworkConvention,
            confidence: Confidence::Certain,
        );
        $facts = $rule->classify($node);

        assertSame(1, count($facts));
        assertSame('data.repository', $facts[0]->role);
        assertSame(Origin::FrameworkConvention, $facts[0]->origin);
        assertSame(Confidence::Certain, $facts[0]->confidence);
    }

    public function testClassifyReturnsEmptyForCustomKindIneligibleNode(): void
    {
        // With custom eligible kinds = ['interface'], a class node should be rejected.
        $node = $this->makeNode(kind: 'class', displayName: 'UserRepository');

        $rule = new NameSuffixRule(
            ruleId: 'name-suffix-interface-only',
            suffixRoles: ['Repository' => 'data.repository'],
            eligibleKinds: ['interface'],
        );
        $facts = $rule->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyInheritsEvidenceFromNode(): void
    {
        $node = $this->makeNode(
            kind: 'class',
            displayName: 'UserService',
            canonicalName: 'App\\UserService',
            localId: 'php:class:App\\UserService',
            relativePath: 'src/UserService.php',
            startLine: 12,
            endLine: 88,
        );

        $facts = $this->makeRule()->classify($node);

        assertSame(1, count($facts));
        assertSame('src/UserService.php', $facts[0]->evidence->relativePath);
        assertSame(12, $facts[0]->evidence->startLine);
        assertSame(88, $facts[0]->evidence->endLine);
    }

    public function testEmptySuffixRolesProducesEmptyFacts(): void
    {
        $node = $this->makeNode(kind: 'class', displayName: 'UserController');

        $facts = (new NameSuffixRule('name-suffix-no-rules', []))->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyRespectsExactSuffix(): void
    {
        // 'entity' suffix is too specific — only 'OrderEntity' matches; 'EntityType' does not.
        $matches = $this->makeNode(kind: 'class', displayName: 'OrderEntity');
        $doesNotMatch = $this->makeNode(kind: 'class', displayName: 'EntityType');

        $rule = new NameSuffixRule('rule-str-entity', ['Entity' => 'data.entity']);

        assertSame(1, count($rule->classify($matches)));
        assertSame([], $rule->classify($doesNotMatch));
    }

    // ----- helpers -----

    private function makeRule(string $ruleId = 'name-suffix-default'): NameSuffixRule
    {
        return new NameSuffixRule(
            ruleId: $ruleId,
            suffixRoles: [
                'Controller' => 'application.controller',
                'Service' => 'application.service',
                'Repository' => 'data.repository',
                'Factory' => 'data.factory',
                'Provider' => 'infrastructure.provider',
            ],
        );
    }

    private function makeNode(
        string $kind,
        string $displayName,
        string $canonicalName = 'App\\Foo',
        string $localId = 'php:class:Foo',
        string $relativePath = 'src/Foo.php',
        int $startLine = 1,
        int $endLine = 10,
    ): NodeFact {
        return new NodeFact(
            $localId,
            $kind,
            $canonicalName,
            $displayName,
            Origin::Ast,
            Confidence::Certain,
            new Evidence($relativePath, $startLine, $endLine),
        );
    }
}
