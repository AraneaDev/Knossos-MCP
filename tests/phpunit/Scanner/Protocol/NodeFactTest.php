<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Protocol;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-protocol')]
final class NodeFactTest extends TestCase
{
    private Evidence $evidence;

    protected function setUp(): void
    {
        $this->evidence = new Evidence('src/Foo.php', 1, 5);
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(NodeFact::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testConstructorStoresProperties(): void
    {
        $n = new NodeFact(
            localId: 'php:class:App\\Foo',
            kind: 'class',
            canonicalName: 'App\\Foo',
            displayName: 'App\\Foo',
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: $this->evidence,
        );

        assertSame('php:class:App\\Foo', $n->localId);
        assertSame('class', $n->kind);
        assertSame('App\\Foo', $n->canonicalName);
        assertSame('App\\Foo', $n->displayName);
        assertSame(Origin::Ast, $n->origin);
        assertSame(Confidence::Certain, $n->confidence);
        assertSame($this->evidence, $n->evidence);
        assertSame([], $n->attributes);
    }

    public function testRejectsEmptyLocalId(): void
    {
        $e = $this->evidence;
        assertThrows(
            static fn() => new NodeFact('', 'class', 'A', 'A', Origin::Ast, Confidence::Certain, $e),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyKind(): void
    {
        $e = $this->evidence;
        assertThrows(
            static fn() => new NodeFact('l', '', 'A', 'A', Origin::Ast, Confidence::Certain, $e),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyCanonicalName(): void
    {
        $e = $this->evidence;
        assertThrows(
            static fn() => new NodeFact('l', 'c', '', 'A', Origin::Ast, Confidence::Certain, $e),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyDisplayName(): void
    {
        $e = $this->evidence;
        assertThrows(
            static fn() => new NodeFact('l', 'c', 'A', '', Origin::Ast, Confidence::Certain, $e),
            InvalidArgumentException::class,
        );
    }

    public function testJsonSerialization(): void
    {
        $n = new NodeFact(
            localId: 'php:class:App\\Foo',
            kind: 'class',
            canonicalName: 'App\\Foo',
            displayName: 'App\\Foo',
            origin: Origin::Derived,
            confidence: Confidence::Probable,
            evidence: $this->evidence,
            attributes: ['extra' => 'value'],
        );
        $json = $n->jsonSerialize();

        assertSame('php:class:App\\Foo', $json['local_id']);
        assertSame('class', $json['kind']);
        assertSame('App\\Foo', $json['canonical_name']);
        assertSame('App\\Foo', $json['display_name']);
        assertSame('derived', $json['origin']);
        assertSame('probable', $json['confidence']);
        assertSame($this->evidence, $json['evidence']);
        assertSame(['extra' => 'value'], $json['attributes']);
    }
}
