<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use InvalidArgumentException;
use Knossos\Classification\ClassificationEngine;
use Knossos\Classification\ClassificationFact;
use Knossos\Classification\ExplicitRoleRule;
use Knossos\Classification\LaravelPathRoleRule;
use Knossos\Classification\LaravelRoleRule;
use Knossos\Classification\NameSuffixRule;
use Knossos\Classification\NestJsRoleRule;
use Knossos\Classification\PythonFrameworkRoleRule;
use Knossos\Classification\SymfonyRoleRule;
use Knossos\Classification\TestModuleRule;
use Knossos\Classification\TypeScriptFrameworkRoleRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Direct tests for the 10 mut-active Classification module files:
 *
 *   - src/Classification/ClassificationEngine.php      (Engine dispatcher)
 *   - src/Classification/LaravelRoleRule.php           (extends/implements on Illuminate)
 *   - src/Classification/NestJsRoleRule.php            (decorators)
 *   - src/Classification/PythonFrameworkRoleRule.php   (django/fastapi/python)
 *   - src/Classification/SymfonyRoleRule.php           (AbstractController / interfaces / PHP attributes)
 *   - src/Classification/LaravelPathRoleRule.php       (path-fragment convention)
 *   - src/Classification/TestModuleRule.php            (tests/spec directory + filename convention)
 *   - src/Classification/NameSuffixRule.php            (constructor-driven suffix matching)
 *   - src/Classification/ExplicitRoleRule.php          (constructor-driven canonical-name roles)
 *   - src/Classification/TypeScriptFrameworkRoleRule.php (nextjs/react/vue/state)
 *
 * (`ClassificationRule.php` is the structural-infimum interface per batch 7;
 * `ClassificationFact.php` is exercised transitively through each rule's
 * positive path.)
 *
 * This is the second mut-active single-file batch per the engine-divergence
 * cohort (after batch 8's Boundary module). Expect the engine MSI to land
 * in the 20-50% band under the per-mutation selection lottery; PHPUnit ground
 * truth at 100% line coverage is the binding surface per the documented
 * pattern.
 */
#[Group('classification')]
final class ClassificationModuleTest extends KnossosTestCase
{
    private static function makeNode(
        string $localId,
        string $kind = 'class',
        string $canonicalName = 'App\\X',
        string $displayName = 'X',
        string $relativePath = 'src/X.php',
        array $attributes = [],
    ): NodeFact {
        return new NodeFact(
            localId: $localId,
            kind: $kind,
            canonicalName: $canonicalName,
            displayName: $displayName,
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence($relativePath, 1, 1),
            attributes: $attributes,
        );
    }

    // ===== LaravelRoleRule =================================================

    public function testLaravelRoleRuleRecognisesExtendsBase(): void
    {
        // Positive: a class extending `Illuminate\\Routing\\Controller`
        // produces `laravel.controller` fact.
        $node = self::makeNode(
            'php:class:App\\Http\\UserController',
            canonicalName: 'App\\Http\\UserController',
            displayName: 'UserController',
            attributes: ['extends' => 'Illuminate\\Routing\\Controller'],
        );
        $facts = (new LaravelRoleRule())->classify($node);
        assertSame(1, count($facts));
        assertSame('laravel.controller', $facts[0]->role);
        // nodeReference stores the localId from the source (line 65
        // of LaravelRoleRule.php), NOT the canonicalName.
        assertSame('php:class:App\\Http\\UserController', $facts[0]->nodeReference);
        assertSame('laravel.explicit.types.v1', $facts[0]->ruleId);
        assertSame(['relation' => 'extends', 'target' => 'Illuminate\\Routing\\Controller'], $facts[0]->attributes);
    }

    public function testLaravelRoleRuleRecognisesImplementsInterface(): void
    {
        // Positive: a class implementing `Illuminate\\Contracts\\Queue\\ShouldQueue`
        // produces `laravel.queued` fact.
        $node = self::makeNode(
            'php:class:App\\Jobs\\SendInvoice',
            canonicalName: 'App\\Jobs\\SendInvoice',
            displayName: 'SendInvoice',
            attributes: ['implements' => ['Illuminate\\Contracts\\Queue\\ShouldQueue']],
        );
        $facts = (new LaravelRoleRule())->classify($node);
        assertSame(1, count($facts));
        assertSame('laravel.queued', $facts[0]->role);
    }

    public function testLaravelRoleRuleReturnsEmptyForNonClass(): void
    {
        // Negative: a function-shaped node triggers `$node->kind !== 'class'`
        // early return.
        $node = self::makeNode('php:function:foo', kind: 'function');
        assertSame([], (new LaravelRoleRule())->classify($node));
    }

    // ===== NestJsRoleRule ==================================================

    public function testNestJsRoleRuleRecognisesModuleDecorator(): void
    {
        // Positive: a node tagged with `nestjs_roles = ['nestjs.module']`
        // produces the corresponding fact.
        $node = self::makeNode(
            'ts:class:src/app.module.ts#AppModule',
            kind: 'class',
            canonicalName: 'src/app.module.ts#AppModule',
            attributes: ['nestjs_roles' => ['nestjs.module']],
        );
        $facts = (new NestJsRoleRule())->classify($node);
        assertSame(1, count($facts));
        assertSame('nestjs.module', $facts[0]->role);
        assertSame(['source' => '@nestjs/common decorator'], $facts[0]->attributes);
    }

    public function testNestJsRoleRuleSkipsUnknownRoles(): void
    {
        // Negative: a role outside the whitelist is silently skipped.
        $node = self::makeNode('ts:class:src/x.ts#X', attributes: ['nestjs_roles' => ['nestjs.unknown']]);
        assertSame([], (new NestJsRoleRule())->classify($node));
    }

    // ===== PythonFrameworkRoleRule =========================================

    public function testPythonFrameworkRoleRuleRecognisesDjangoView(): void
    {
        $node = self::makeNode(
            'py:class:shop/views.py',
            kind: 'class',
            canonicalName: 'shop.views.HomeView',
            attributes: ['python_framework_roles' => ['django.view']],
        );
        $facts = (new PythonFrameworkRoleRule())->classify($node);
        assertSame(1, count($facts));
        assertSame('django.view', $facts[0]->role);
        assertSame('python.framework.ast.v1', $facts[0]->ruleId);
    }

    public function testPythonFrameworkRoleRuleSkipsUnknownRoles(): void
    {
        $node = self::makeNode('py:class:shop/x.py', attributes: ['python_framework_roles' => ['python.unknown']]);
        assertSame([], (new PythonFrameworkRoleRule())->classify($node));
    }

    // ===== SymfonyRoleRule =================================================

    public function testSymfonyRoleRuleRecognisesAbstractController(): void
    {
        $node = self::makeNode(
            'php:class:App\\Controller\\HomeController',
            canonicalName: 'App\\Controller\\HomeController',
            attributes: ['extends' => 'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController'],
        );
        $facts = (new SymfonyRoleRule())->classify($node);
        assertSame(1, count($facts));
        assertSame('symfony.controller', $facts[0]->role);
    }

    public function testSymfonyRoleRuleRecognisesMessageHandlerAttribute(): void
    {
        // Positive: `php_attributes` containing `Symfony\\Messenger\\Attribute\\AsMessageHandler`
        // maps to `symfony.message_handler`.
        $node = self::makeNode(
            'php:class:App\\Handler\\InvoiceHandler',
            attributes: ['php_attributes' => ['Symfony\\Messenger\\Attribute\\AsMessageHandler']],
        );
        $facts = (new SymfonyRoleRule())->classify($node);
        assertSame(1, count($facts));
        assertSame('symfony.message_handler', $facts[0]->role);
    }

    public function testSymfonyRoleRuleRecognisesEventSubscriberInterface(): void
    {
        // Positive: `implements` array containing an interface whose
        // basename is `EventSubscriberInterface` produces
        // `symfony.event_subscriber` fact (line 28-30 of SymfonyRoleRule).
        $node = self::makeNode(
            'php:class:App\\Subscriber\\InvoiceEventSubscriber',
            attributes: ['implements' => ['Symfony\\Component\\EventDispatcher\\EventSubscriberInterface']],
        );
        $facts = (new SymfonyRoleRule())->classify($node);
        assertSame(1, count($facts));
        assertSame('symfony.event_subscriber', $facts[0]->role);
    }

    public function testSymfonyRoleRuleSkipsMethods(): void
    {
        // Negative: a method-kind node triggers early return.
        $node = self::makeNode('php:method:App\\X::y', kind: 'method');
        assertSame([], (new SymfonyRoleRule())->classify($node));
    }

    // ===== LaravelPathRoleRule ==============================================

    public function testLaravelPathRoleRuleMatchesControllersDirectory(): void
    {
        $node = self::makeNode(
            'php:class:app/Http/Controllers/UserController.php',
            relativePath: 'app/Http/Controllers/UserController.php',
        );
        $facts = (new LaravelPathRoleRule())->classify($node);
        assertSame(1, count($facts));
        assertSame('laravel.controller', $facts[0]->role);
        assertSame(Confidence::Probable, $facts[0]->confidence);
    }

    public function testLaravelPathRoleRuleSkipsUnmatchedPath(): void
    {
        $node = self::makeNode('php:class:app/Helper/Random.php', relativePath: 'app/Helper/Random.php');
        assertSame([], (new LaravelPathRoleRule())->classify($node));
    }

    // ===== TestModuleRule ==================================================

    public function testTestModuleRuleRecognisesTestsDirectory(): void
    {
        $node = self::makeNode(
            'ts:class:tests/checkout.test.ts#CheckoutFlow',
            kind: 'class',
            canonicalName: 'tests/checkout.test.ts#CheckoutFlow',
            relativePath: 'tests/checkout.test.ts',
        );
        $facts = (new TestModuleRule())->classify($node);
        assertSame(1, count($facts));
        assertSame('quality.test_module', $facts[0]->role);
        assertSame('core.test.modules.v1', $facts[0]->ruleId);
    }

    public function testTestModuleRuleRecognisesFilenameConvention(): void
    {
        // Filename ending in `.test.ts` outside `tests/` directory still
        // matches via the basename-rule fallback in isTestPath().
        $node = self::makeNode(
            'ts:class:src/checkout.test.ts#Flow',
            canonicalName: 'src/checkout.test.ts#Flow',
            relativePath: 'src/checkout.test.ts',
        );
        $facts = (new TestModuleRule())->classify($node);
        assertSame(1, count($facts));
    }

    public function testTestModuleRuleSkipsProductionPath(): void
    {
        $node = self::makeNode('ts:class:src/checkout.ts#Flow', relativePath: 'src/checkout.ts');
        assertSame([], (new TestModuleRule())->classify($node));
    }

    // ===== NameSuffixRule ==================================================

    public function testNameSuffixRuleMatchesConfiguredSuffix(): void
    {
        // Constructor-driven: a `Repository` suffix maps to `app.repository` role.
        $rule = new NameSuffixRule('app.suffix.v1', ['Repository' => 'app.repository']);
        $node = self::makeNode(
            'php:class:App\\CheckoutRepo',
            canonicalName: 'App\\CheckoutRepo',
            displayName: 'CheckoutRepository',
        );
        $facts = $rule->classify($node);
        assertSame(1, count($facts));
        assertSame('app.repository', $facts[0]->role);
        assertSame('app.suffix.v1', $facts[0]->ruleId);
    }

    public function testNameSuffixRuleSkipsSuffixlessNode(): void
    {
        $rule = new NameSuffixRule('app.suffix.v1', ['Repository' => 'app.repository']);
        $node = self::makeNode(
            'php:class:App\\Helper',
            canonicalName: 'App\\Helper',
            displayName: 'Helper',
        );
        assertSame([], $rule->classify($node));
    }

    // ===== ExplicitRoleRule ================================================

    public function testExplicitRoleRuleMatchesCanonicalName(): void
    {
        $rule = new ExplicitRoleRule('user.explicit.v1', [
            'App\\PaymentService' => ['payments.partner'],
            'App\\InvoiceService' => ['billing.partner'],
        ]);
        $node = self::makeNode(
            'php:class:App\\PaymentService',
            canonicalName: 'App\\PaymentService',
        );
        $facts = $rule->classify($node);
        assertSame(1, count($facts));
        assertSame('payments.partner', $facts[0]->role);
        assertSame(Origin::UserRule, $facts[0]->origin);
    }

    public function testExplicitRoleRuleSkipsMissing(): void
    {
        $rule = new ExplicitRoleRule('user.explicit.v1', ['App\\Unknown' => ['x']]);
        $node = self::makeNode('php:class:App\\Other', canonicalName: 'App\\Other');
        assertSame([], $rule->classify($node));
    }

    // ===== TypeScriptFrameworkRoleRule =====================================

    public function testTypeScriptFrameworkRoleRuleRecognisesReactComponent(): void
    {
        $node = self::makeNode(
            'ts:class:src/components/Button.tsx#Button',
            kind: 'class',
            canonicalName: 'src/components/Button.tsx#Button',
            attributes: ['typescript_framework_roles' => ['react.component']],
        );
        $facts = (new TypeScriptFrameworkRoleRule())->classify($node);
        assertSame(1, count($facts));
        assertSame('react.component', $facts[0]->role);
        assertSame('typescript.application.v1', $facts[0]->ruleId);
        assertSame(Confidence::Probable, $facts[0]->confidence);
    }

    public function testTypeScriptFrameworkRoleRuleSkipsUnknownRoles(): void
    {
        $node = self::makeNode('ts:class:src/x.ts', attributes: ['typescript_framework_roles' => ['unknown.framework']]);
        assertSame([], (new TypeScriptFrameworkRoleRule())->classify($node));
    }

    // ===== ClassificationEngine ===========================================

    public function testClassificationEngineDispatchesAcrossRules(): void
    {
        // Engine positive path: a node triggers multiple rules;
        // produces dedup'd, ksort'd list.
        $node = self::makeNode(
            'php:class:App\\Http\\CheckoutController',
            canonicalName: 'App\\Http\\CheckoutController',
            displayName: 'CheckoutController',
            relativePath: 'app/Http/Controllers/CheckoutController.php',
            attributes: [
                'extends' => 'Illuminate\\Routing\\Controller',
                'implements' => ['Illuminate\\Contracts\\Queue\\ShouldQueue'],
            ],
        );
        $nodeFact = $node;
        $contribution = new ScanContribution('knossos.php', [$nodeFact]);
        $engine = new ClassificationEngine([new LaravelPathRoleRule(), new LaravelRoleRule()]);
        $facts = $engine->classify([$contribution]);
        // laravel.controller (path), laravel.controller (extends), laravel.queued (implements).
        assertSame(3, count($facts));
        // ksort on the dedup key (nodeReference \\0 role \\0 ruleId) puts laravel.controller/extends before queued.
        assertSame('laravel.controller', $facts[0]->role);
        assertSame('laravel.queued', $facts[2]->role);
    }

    public function testClassificationEngineConstructorRejectsNonRule(): void
    {
        // Engine throw path: a non-ClassificationRule in the constructor array throws.
        assertThrows(
            fn() => new ClassificationEngine(['not-a-rule']),
            InvalidArgumentException::class,
        );
    }

    public function testClassificationEngineThrowsOnInconsistentProvenance(): void
    {
        // Engine throw path: a fake rule that emits a fact with the wrong
        // nodeReference or ruleId surfaces via the inline consistency guard.
        $nodeFact = self::makeNode('php:class:App\\X', canonicalName: 'App\\X');
        $badRule = new class implements \Knossos\Classification\ClassificationRule {
            public function id(): string
            {
                return 'fake.id';
            }
            public function classify(NodeFact $node): array
            {
                return [new ClassificationFact(
                    'WRONG-LOCAL-ID',
                    'fake.role',
                    'fake.id',
                    Origin::Ast,
                    Confidence::Certain,
                    $node->evidence,
                )];
            }
        };
        $contribution = new ScanContribution('knossos.php', [$nodeFact]);
        $engine = new ClassificationEngine([$badRule]);
        assertThrows(
            fn() => $engine->classify([$contribution]),
            InvalidArgumentException::class,
        );
    }

    public function testClassificationEngineDeduplicatesIdenticalFacts(): void
    {
        // Engine dedup path: two rules producing the SAME (nodeReference, role, ruleId)
        // triple collapse into one fact via the `\0` key.
        $nodeFact = self::makeNode('php:class:App\\X', canonicalName: 'App\\X');
        $node = $nodeFact;
        $ruleA = new NameSuffixRule('fake.suffix.v1', ['X' => 'app.x']);
        $ruleB = new NameSuffixRule('fake.suffix.v1', ['X' => 'app.x']);
        $engine = new ClassificationEngine([$ruleA, $ruleB]);
        $facts = $engine->classify([new ScanContribution('knossos.php', [$node])]);
        assertSame(1, count($facts));
    }

    public function testClassificationEngineSkipsUnmatchedContribution(): void
    {
        // Engine boundary: a contribution with empty / missing nodes produces
        // an empty fact list.
        $engine = new ClassificationEngine([new LaravelRoleRule(), new TestModuleRule()]);
        assertSame([], $engine->classify([new ScanContribution('knossos.php', [])]));
    }
}
