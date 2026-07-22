<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Protocol;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-protocol')]
final class EdgeFactTest extends TestCase
{
    private Evidence $evidence;

    protected function setUp(): void
    {
        $this->evidence = new Evidence('src/Foo.php', 1, 5);
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(EdgeFact::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testConstructorStoresProperties(): void
    {
        $e = new EdgeFact(
            kind: 'depends_on',
            sourceReference: 'php:class:App\\Foo',
            targetReference: 'php:class:App\\Bar',
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: $this->evidence,
        );

        assertSame('depends_on', $e->kind);
        assertSame('php:class:App\\Foo', $e->sourceReference);
        assertSame('php:class:App\\Bar', $e->targetReference);
        assertSame(Origin::Ast, $e->origin);
        assertSame(Confidence::Certain, $e->confidence);
        assertSame($this->evidence, $e->evidence);
        assertSame([], $e->attributes);
    }

    public function testRejectsEmptyKind(): void
    {
        $e = $this->evidence;
        assertThrows(
            static fn() => new EdgeFact('', 'src', 'tgt', Origin::Ast, Confidence::Certain, $e),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptySourceReference(): void
    {
        $e = $this->evidence;
        assertThrows(
            static fn() => new EdgeFact('k', '', 'tgt', Origin::Ast, Confidence::Certain, $e),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyTargetReference(): void
    {
        $e = $this->evidence;
        assertThrows(
            static fn() => new EdgeFact('k', 'src', '', Origin::Ast, Confidence::Certain, $e),
            InvalidArgumentException::class,
        );
    }

    public function testJsonSerialization(): void
    {
        $e = new EdgeFact(
            kind: 'depends_on',
            sourceReference: 'src',
            targetReference: 'tgt',
            origin: Origin::Derived,
            confidence: Confidence::Probable,
            evidence: $this->evidence,
            attributes: ['extra' => 'value'],
        );
        $json = $e->jsonSerialize();

        assertSame('depends_on', $json['kind']);
        assertSame('src', $json['source']);
        assertSame('tgt', $json['target']);
        assertSame('derived', $json['origin']);
        assertSame('probable', $json['confidence']);
        assertSame($this->evidence, $json['evidence']);
        assertSame(['extra' => 'value'], $json['attributes']);
    }
}
