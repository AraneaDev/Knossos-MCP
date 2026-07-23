<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Error;
use Knossos\Reconciliation\ReconciliationResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[Group('reconciliation-result')]
final class ReconciliationResultTest extends TestCase
{
    public function testClassIsFinal(): void
    {
        $this->assertTrue((new ReflectionClass(ReconciliationResult::class))->isFinal());
    }

    public function testClassIsReadonly(): void
    {
        $this->assertTrue((new ReflectionClass(ReconciliationResult::class))->isReadOnly());
    }

    /**
     * @return list<ReflectionProperty>
     */
    private static function publicProperties(): array
    {
        return (new ReflectionClass(ReconciliationResult::class))->getProperties(ReflectionProperty::IS_PUBLIC);
    }

    public function testHasExactlyEightPublicPropertiesInExpectedOrder(): void
    {
        // Locks the constructor's promoted-property order into the public surface —
        // reordering any of the fields would change this canonical list.
        assertSame(
            ['projectId', 'scanId', 'files', 'nodes', 'edges', 'diagnostics', 'unresolvedNodes', 'phaseMilliseconds'],
            array_map(static fn (ReflectionProperty $p): string => $p->getName(), self::publicProperties()),
        );
    }

    public function testPropertyTypesAreStringStringIntIntIntIntIntArray(): void
    {
        $expected = ['string', 'string', 'int', 'int', 'int', 'int', 'int', 'array'];
        $actual = array_map(
            static fn (ReflectionProperty $p): string => (string) $p->getType(),
            self::publicProperties(),
        );
        // Kills type mutations on any promoted parameter (e.g. int → float).
        assertSame($expected, $actual);
    }

    public function testAllPropertiesArePublic(): void
    {
        foreach (self::publicProperties() as $prop) {
            $this->assertTrue($prop->isPublic());
        }
    }

    public function testConstructorStoresAllEightProperties(): void
    {
        $result = new ReconciliationResult(
            projectId: 'pid',
            scanId: 'sid',
            files: 10,
            nodes: 100,
            edges: 200,
            diagnostics: 5,
            unresolvedNodes: 2,
            phaseMilliseconds: ['prepare' => 1.5],
        );

        assertSame('pid', $result->projectId);
        assertSame('sid', $result->scanId);
        assertSame(10, $result->files);
        assertSame(100, $result->nodes);
        assertSame(200, $result->edges);
        assertSame(5, $result->diagnostics);
        assertSame(2, $result->unresolvedNodes);
        assertSame(['prepare' => 1.5], $result->phaseMilliseconds);
    }

    public function testPhaseMillisecondsDefaultsToEmptyArray(): void
    {
        $result = new ReconciliationResult('pid', 'sid', 1, 2, 3, 4, 5);
        assertSame([], $result->phaseMilliseconds);
    }

    public function testAcceptsZeroCountsForAllIntFields(): void
    {
        // Confirms the source has no implicit upper/lower-bound validation.
        $result = new ReconciliationResult('', '', 0, 0, 0, 0, 0);
        assertSame(0, $result->files);
        assertSame(0, $result->nodes);
        assertSame(0, $result->edges);
        assertSame(0, $result->diagnostics);
        assertSame(0, $result->unresolvedNodes);
    }

    public function testAcceptsEmptyStringsForIdFields(): void
    {
        // The source's ctor doesn't validate the two string fields beyond the
        // type system, so empty strings must be valid.
        $result = new ReconciliationResult('', '', 1, 2, 3, 4, 5);
        assertSame('', $result->projectId);
        assertSame('', $result->scanId);
    }

    public function testAllConstructorParametersAreRequiredAndNonNullableExceptPhaseMilliseconds(): void
    {
        // Kills any mutation that adds a default value or relaxes null-allowance
        // on the original seven fields. phaseMilliseconds is the sole exception:
        // it must have a default so existing call sites keep compiling.
        $ctor = (new ReflectionClass(ReconciliationResult::class))->getConstructor();
        foreach ($ctor->getParameters() as $param) {
            if ($param->getName() === 'phaseMilliseconds') {
                $this->assertTrue($param->isOptional(), 'phaseMilliseconds must be optional');
                continue;
            }
            $this->assertFalse($param->isOptional(), $param->getName() . ' must be required');
            $this->assertFalse($param->allowsNull(), $param->getName() . ' must disallow null');
        }
    }

    public function testReadonlyClassRejectsMutatingPropertyAssignment(): void
    {
        // PHP rejects writing to readonly properties at runtime with an Error.
        // We catch + assert the original value is preserved.
        $result = new ReconciliationResult('pid', 'sid', 1, 2, 3, 4, 5);
        $error = null;
        try {
            $result->projectId = 'new';
        } catch (Error $e) {
            $error = $e;
        }
        $this->assertNotNull($error);
        assertSame('pid', $result->projectId);
    }
}
