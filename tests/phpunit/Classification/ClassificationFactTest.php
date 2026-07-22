<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use InvalidArgumentException;
use Knossos\Classification\ClassificationFact;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('classification-fact')]
final class ClassificationFactTest extends TestCase
{
    public function testConstructorAssignsAllFieldsViaNamedArgs(): void
    {
        $evidence = new Evidence('src/Service.php', 10, 50);

        $fact = new ClassificationFact(
            nodeReference: 'php:class:Service',
            role: 'application.service',
            ruleId: 'name-suffix-service',
            origin: Origin::Derived,
            confidence: Confidence::Probable,
            evidence: $evidence,
            attributes: ['matched_suffix' => 'Service'],
        );

        assertSame('php:class:Service', $fact->nodeReference);
        assertSame('application.service', $fact->role);
        assertSame('name-suffix-service', $fact->ruleId);
        assertSame(Origin::Derived, $fact->origin);
        assertSame(Confidence::Probable, $fact->confidence);
        assertSame($evidence, $fact->evidence);
        assertSame(['matched_suffix' => 'Service'], $fact->attributes);
    }

    public function testConstructorAssignsAllFieldsViaPositionalArgs(): void
    {
        $evidence = new Evidence('src/Repository.php', 1, 30);

        $fact = new ClassificationFact(
            'php:class:Repository',
            'data.repository',
            'rule-repository',
            Origin::UserRule,
            Confidence::Certain,
            $evidence,
        );

        assertSame('php:class:Repository', $fact->nodeReference);
        assertSame('data.repository', $fact->role);
        assertSame('rule-repository', $fact->ruleId);
        assertSame(Origin::UserRule, $fact->origin);
        assertSame(Confidence::Certain, $fact->confidence);
        assertSame($evidence, $fact->evidence);
        assertSame([], $fact->attributes);
    }

    public function testReadonlyFieldsCannotBeReassigned(): void
    {
        $fact = $this->makeFact();

        $error = captureThrows(static function () use ($fact): void {
            $fact->role = 'hacked';
        }, \Error::class);

        assertContains('readonly', $error->getMessage());
    }

    public function testEmptyNodeReferenceThrows(): void
    {
        assertThrows(
            fn(): ClassificationFact => new ClassificationFact(
                nodeReference: '',
                role: 'application.controller',
                ruleId: 'rule-foo',
                origin: Origin::Derived,
                confidence: Confidence::Probable,
                evidence: new Evidence('src/Controller.php', 1, 5),
            ),
            InvalidArgumentException::class,
        );
    }

    public function testEmptyRoleThrows(): void
    {
        assertThrows(
            fn(): ClassificationFact => new ClassificationFact(
                nodeReference: 'php:class:Foo',
                role: '',
                ruleId: 'rule-foo',
                origin: Origin::Derived,
                confidence: Confidence::Probable,
                evidence: new Evidence('src/Foo.php', 1, 5),
            ),
            InvalidArgumentException::class,
        );
    }

    public function testEmptyRuleIdThrows(): void
    {
        assertThrows(
            fn(): ClassificationFact => new ClassificationFact(
                nodeReference: 'php:class:Foo',
                role: 'application.foo',
                ruleId: '',
                origin: Origin::Derived,
                confidence: Confidence::Probable,
                evidence: new Evidence('src/Foo.php', 1, 5),
            ),
            InvalidArgumentException::class,
        );
    }

    public function testRoleStartingWithUppercaseThrows(): void
    {
        assertThrows(
            fn(): ClassificationFact => new ClassificationFact(
                nodeReference: 'php:class:Foo',
                role: 'Application.foo',
                ruleId: 'rule-foo',
                origin: Origin::Derived,
                confidence: Confidence::Probable,
                evidence: new Evidence('src/Foo.php', 1, 5),
            ),
            InvalidArgumentException::class,
        );
    }

    public function testRoleStartingWithDigitThrows(): void
    {
        assertThrows(
            fn(): ClassificationFact => new ClassificationFact(
                nodeReference: 'php:class:Foo',
                role: '1nope.something',
                ruleId: 'rule-foo',
                origin: Origin::Derived,
                confidence: Confidence::Probable,
                evidence: new Evidence('src/Foo.php', 1, 5),
            ),
            InvalidArgumentException::class,
        );
    }

    public function testRoleWithUnderscoreAndDotAndDashIsAccepted(): void
    {
        $fact = new ClassificationFact(
            nodeReference: 'php:class:Foo',
            role: 'application.foo_bar-baz.qux',
            ruleId: 'rule-foo',
            origin: Origin::Derived,
            confidence: Confidence::Probable,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );

        assertSame('application.foo_bar-baz.qux', $fact->role);
    }

    public function testEmptyAttributesArrayAccepted(): void
    {
        $fact = $this->makeFact();

        assertSame([], $fact->attributes);
    }

    public function testNonEmptyAttributesArrayPropagates(): void
    {
        $attrs = ['matched_suffix' => 'Controller', 'source' => 'rule-engine'];

        $fact = new ClassificationFact(
            nodeReference: 'php:class:FooController',
            role: 'application.controller',
            ruleId: 'name-suffix-controller',
            origin: Origin::Derived,
            confidence: Confidence::Probable,
            evidence: new Evidence('src/Controller.php', 1, 100),
            attributes: $attrs,
        );

        assertSame($attrs, $fact->attributes);
    }

    // ----- helper -----

    private function makeFact(): ClassificationFact
    {
        return new ClassificationFact(
            nodeReference: 'php:class:Foo',
            role: 'application.foo',
            ruleId: 'rule-foo',
            origin: Origin::Derived,
            confidence: Confidence::Probable,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
    }
}
