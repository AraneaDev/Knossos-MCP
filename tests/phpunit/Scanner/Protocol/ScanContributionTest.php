<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Protocol;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-protocol')]
final class ScanContributionTest extends TestCase
{
    private Evidence $evidence;

    protected function setUp(): void
    {
        $this->evidence = new Evidence('src/Foo.php', 1, 5);
    }

    // ----- shape -----

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(ScanContribution::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    // ----- happy path -----

    public function testConstructorStoresOwnerKey(): void
    {
        $c = new ScanContribution('test.knossos:file:src/Foo.php');
        assertSame('test.knossos:file:src/Foo.php', $c->ownerKey);
        assertSame([], $c->nodes);
        assertSame([], $c->edges);
        assertSame([], $c->diagnostics);
    }

    public function testConstructorStoresNodesEdgesAndDiagnostics(): void
    {
        $n = new NodeFact('l', 'c', 'A', 'A', Origin::Ast, Confidence::Certain, $this->evidence);
        $e = new EdgeFact('k', 'src', 'tgt', Origin::Ast, Confidence::Certain, $this->evidence);
        $d = new Diagnostic('info', 'X', 'msg');
        $c = new ScanContribution('o', [$n], [$e], [$d]);

        assertSame([$n], $c->nodes);
        assertSame([$e], $c->edges);
        assertSame([$d], $c->diagnostics);
    }

    // ----- validation -----

    public function testRejectsEmptyOwnerKey(): void
    {
        assertThrows(
            static fn() => new ScanContribution(''),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsNonListNodes(): void
    {
        assertThrows(
            static fn() => new ScanContribution('o', ['not-a-node']),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsWrongInstanceInNodes(): void
    {
        assertThrows(
            static fn() => new ScanContribution('o', [new \stdClass()]),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsNonListEdges(): void
    {
        assertThrows(
            static fn() => new ScanContribution('o', [], ['not-an-edge']),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsNonListDiagnostics(): void
    {
        assertThrows(
            static fn() => new ScanContribution('o', [], [], ['not-a-diagnostic']),
            InvalidArgumentException::class,
        );
    }

    // ----- jsonSerialize -----

    public function testJsonSerialization(): void
    {
        $c = new ScanContribution('o');
        $json = $c->jsonSerialize();

        assertSame('o', $json['owner_key']);
        assertSame([], $json['nodes']);
        assertSame([], $json['edges']);
        assertSame([], $json['diagnostics']);
    }
}
