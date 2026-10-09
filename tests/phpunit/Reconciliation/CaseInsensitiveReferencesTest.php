<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Reconciliation\CaseInsensitiveReferences;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * PHP symbol names match without regard to case, a property name and every
 * other language's names do not, and an undeclared name has one spelling
 * whatever order the files arrive in.
 */
#[Group('reconciliation')]
final class CaseInsensitiveReferencesTest extends KnossosTestCase
{
    public function testFoldsEveryPhpSymbolSegmentButAProperty(): void
    {
        self::assertSame('php:class:app\\foo', CaseInsensitiveReferences::fold('php:class:App\\Foo'));
        self::assertSame('php:method:app\\foo::dothing', CaseInsensitiveReferences::fold('php:method:App\\Foo::doThing'));
        self::assertSame('php:property:app\\foo::$Bar', CaseInsensitiveReferences::fold('php:property:App\\Foo::$Bar'));
        self::assertSame('php:method_of_property:app\\foo::$Opts::flag', CaseInsensitiveReferences::fold('php:method_of_property:App\\Foo::$Opts::Flag'));
        self::assertSame('php:function:app\\helper', CaseInsensitiveReferences::fold('php:function:App\\Helper'));
    }

    public function testLeavesOtherKindsAndLanguagesAsWritten(): void
    {
        foreach (['php:module:src/Foo.php', 'php:route:GET /A => X', 'ts:class:web/a.ts#Foo', 'py:function:App.Foo', 'php:class:', 'php:class'] as $reference) {
            self::assertFalse(CaseInsensitiveReferences::applies($reference), $reference);
            self::assertSame($reference, CaseInsensitiveReferences::fold($reference));
        }
    }

    public function testKeysTheGraphByMatchingKeys(): void
    {
        $references = new CaseInsensitiveReferences(
            ['php:class:App\\Foo' => 'b', 'php:class:App\\FOO' => 'a', 'ts:class:web/a.ts#Foo' => 'c'],
            ['php:method:App\\Foo::make' => 'php:class:App\\Bar'],
            ['php:class:App\\Child' => ['php:class:App\\Foo'], 'php:class:App\\CHILD' => ['php:trait:App\\Logs']],
            [],
        );

        self::assertSame(['php:class:app\\foo' => 'a'], $references->nodeMap(), 'the lower id, whatever order');
        self::assertSame(['php:method:app\\foo::make' => 'php:class:app\\bar'], $references->returnTypes());
        self::assertSame(['php:class:app\\child' => ['php:class:app\\foo', 'php:trait:app\\logs']], $references->inheritanceSources());
    }

    public function testComparesIdsAsStringsNeverAsNumbers(): void
    {
        $references = new CaseInsensitiveReferences(['php:class:App\\Foo' => '999', 'php:class:App\\FOO' => '1e3'], [], [], []);

        self::assertSame(['php:class:app\\foo' => '1e3'], $references->nodeMap(), 'byte order, though 1e3 is the larger number');
    }

    public function testNamesAnExternalByOneSpelling(): void
    {
        $contributions = [
            $this->contribution('php:class:datetime', 'php:method:datetime::createFromFormat', 'php:namespaced_function:App\\STRLEN'),
            $this->contribution('php:class:DateTime', 'php:method:Bar::$Opts::x', 'php:function:strlen'),
        ];
        $forward = new CaseInsensitiveReferences(['php:class:App\\Bar' => 'n1'], [], [], $contributions);
        $reverse = new CaseInsensitiveReferences(['php:class:App\\Bar' => 'n1'], [], [], array_reverse($contributions));

        foreach ([$forward, $reverse] as $references) {
            self::assertSame('php:class:DateTime', $references->spelling('php:class:datetime'));
            self::assertSame('php:method:DateTime::createFromFormat', $references->spelling('php:method:DATETIME::CREATEFROMFORMAT'));
            self::assertSame('php:function:STRLEN', $references->spelling('php:function:strlen'), 'a function is spelled apart from a type');
            self::assertSame('php:method:App\\Bar::go', $references->spelling('php:method:app\\bar::go'), 'a declared type keeps its spelling');
            self::assertSame('php:method:Bar::$opts::x', $references->spelling('php:method:Bar::$opts::x'), 'a property is its own name');
            self::assertSame('ts:class:web/a.ts#foo', $references->spelling('ts:class:web/a.ts#foo'));
        }
    }

    private function contribution(string ...$targets): ScanContribution
    {
        $edges = array_map(
            static fn(string $target): EdgeFact => new EdgeFact('calls', 'php:module:a.php', $target, Origin::Ast, Confidence::Certain, new Evidence('a.php', 1, 1)),
            $targets,
        );

        return new ScanContribution('knossos.php:file:a.php', [], $edges);
    }
}
