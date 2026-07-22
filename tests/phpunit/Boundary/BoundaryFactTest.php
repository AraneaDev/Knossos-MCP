<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Boundary;

use InvalidArgumentException;
use Knossos\Boundary\BoundaryFact;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('boundary-fact')]
final class BoundaryFactTest extends TestCase
{
    public function testConstructorAssignsAllFieldsViaNamedArgs(): void
    {
        $fact = new BoundaryFact(
            name: 'payments',
            matcher: ['type' => 'path_prefix', 'value' => 'src/payments/'],
            source: 'explicit',
            nodeReferences: ['node-a', 'node-b'],
        );

        assertSame('payments', $fact->name);
        assertSame(['type' => 'path_prefix', 'value' => 'src/payments/'], $fact->matcher);
        assertSame('explicit', $fact->source);
        assertSame(['node-a', 'node-b'], $fact->nodeReferences);
    }

    public function testConstructorAssignsAllFieldsViaPositionalArgs(): void
    {
        $fact = new BoundaryFact(
            'checkout',
            ['type' => 'namespace_prefix', 'value' => 'App\\Checkout\\'],
            'inferred',
            ['node-c'],
        );

        assertSame('checkout', $fact->name);
        assertSame(['type' => 'namespace_prefix', 'value' => 'App\\Checkout\\'], $fact->matcher);
        assertSame('inferred', $fact->source);
        assertSame(['node-c'], $fact->nodeReferences);
    }

    public function testReadonlyFieldsCannotBeReassigned(): void
    {
        $fact = new BoundaryFact(
            name: 'payments',
            matcher: ['type' => 'path_prefix', 'value' => 'src/payments/'],
            source: 'explicit',
            nodeReferences: [],
        );

        $error = captureThrows(static function () use ($fact): void {
            $fact->name = 'hacked';
        }, \Error::class);

        assertContains('readonly', $error->getMessage());
    }

    public function testEmptyNodeReferencesListIsAccepted(): void
    {
        $fact = new BoundaryFact(
            name: 'empty-list',
            matcher: ['type' => 'path_prefix', 'value' => 'src/'],
            source: 'explicit',
            nodeReferences: [],
        );

        assertSame([], $fact->nodeReferences);
    }

    public function testRejectsEmptyName(): void
    {
        assertThrows(
            static function (): BoundaryFact {
                return new BoundaryFact(
                    name: '',
                    matcher: ['type' => 'path_prefix', 'value' => 'src/'],
                    source: 'explicit',
                    nodeReferences: [],
                );
            },
            InvalidArgumentException::class,
        );
    }

    public function testRejectsInvalidSource(): void
    {
        // 'unknown' is not in the ['explicit', 'inferred'] allowed set.
        assertThrows(
            static function (): BoundaryFact {
                return new BoundaryFact(
                    name: 'payments',
                    matcher: ['type' => 'path_prefix', 'value' => 'src/'],
                    source: 'unknown',
                    nodeReferences: [],
                );
            },
            InvalidArgumentException::class,
        );
    }

    public function testRejectsExplicitSourceWithCapitalLetter(): void
    {
        // 'Explicit' vs 'explicit' — verifies case-sensitive in_array check (3rd arg = true).
        assertThrows(
            static function (): BoundaryFact {
                return new BoundaryFact(
                    name: 'payments',
                    matcher: ['type' => 'path_prefix', 'value' => 'src/'],
                    source: 'Explicit',
                    nodeReferences: [],
                );
            },
            InvalidArgumentException::class,
        );
    }

    public function testRejectsNonListNodeReferences(): void
    {
        // Associative array (string keys) violates array_is_list check.
        assertThrows(
            static function (): BoundaryFact {
                return new BoundaryFact(
                    name: 'payments',
                    matcher: ['type' => 'path_prefix', 'value' => 'src/'],
                    source: 'explicit',
                    nodeReferences: ['non-list-key' => 'a', 'another-key' => 'b'],
                );
            },
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyStringInNodeReferences(): void
    {
        // List containing '' violates the non-empty-string-per-reference check.
        assertThrows(
            static function (): BoundaryFact {
                return new BoundaryFact(
                    name: 'payments',
                    matcher: ['type' => 'path_prefix', 'value' => 'src/'],
                    source: 'explicit',
                    nodeReferences: ['node-a', '', 'node-b'],
                );
            },
            InvalidArgumentException::class,
        );
    }

    public function testRejectsNonStringInNodeReferences(): void
    {
        // Integer in node references violates the is_string check.
        assertThrows(
            static function (): BoundaryFact {
                return new BoundaryFact(
                    name: 'payments',
                    matcher: ['type' => 'path_prefix', 'value' => 'src/'],
                    source: 'explicit',
                    nodeReferences: [42, 'node-a'],
                );
            },
            InvalidArgumentException::class,
        );
    }

    public function testExplicitSourceIsAccepted(): void
    {
        $fact = new BoundaryFact(
            name: 'explicit-boundary',
            matcher: ['type' => 'path_prefix', 'value' => 'src/'],
            source: 'explicit',
            nodeReferences: [],
        );

        assertSame('explicit', $fact->source);
    }

    public function testInferredSourceIsAccepted(): void
    {
        $fact = new BoundaryFact(
            name: 'inferred-boundary',
            matcher: ['type' => 'namespace_prefix', 'value' => 'App\\'],
            source: 'inferred',
            nodeReferences: [],
        );

        assertSame('inferred', $fact->source);
    }

    public function testMatcherArrayWithVariousShapesIsAccepted(): void
    {
        // The matcher field is loosely typed (array<string, mixed>) so any
        // shape that contains the expected keys passes.
        $shape1 = new BoundaryFact(
            name: 'b1',
            matcher: [],
            source: 'explicit',
            nodeReferences: [],
        );
        assertSame([], $shape1->matcher);

        $shape2 = new BoundaryFact(
            name: 'b2',
            matcher: ['type' => 'path_prefix', 'value' => 'src/', 'extra-key' => 'allowed'],
            source: 'inferred',
            nodeReferences: [],
        );
        assertSame(['type' => 'path_prefix', 'value' => 'src/', 'extra-key' => 'allowed'], $shape2->matcher);
    }

    public function testLargeNodeReferencesListPropagates(): void
    {
        $refs = [];
        for ($i = 0; $i < 50; $i++) {
            $refs[] = 'node-' . $i;
        }

        $fact = new BoundaryFact(
            name: 'large',
            matcher: ['type' => 'path_prefix', 'value' => 'src/'],
            source: 'explicit',
            nodeReferences: $refs,
        );

        assertSame(50, count($fact->nodeReferences));
        assertSame('node-0', $fact->nodeReferences[0]);
        assertSame('node-49', $fact->nodeReferences[49]);
    }
}
