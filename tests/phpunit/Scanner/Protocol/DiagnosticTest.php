<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Protocol;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-protocol')]
final class DiagnosticTest extends TestCase
{
    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(Diagnostic::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testConstructorStoresProperties(): void
    {
        $d = new Diagnostic('warning', 'CODE', 'Message');
        assertSame('warning', $d->severity);
        assertSame('CODE', $d->code);
        assertSame('Message', $d->message);
        assertSame(null, $d->evidence);
    }

    public function testConstructorStoresOptionalEvidence(): void
    {
        $e = new Evidence('src/Foo.php', 1, 5);
        $d = new Diagnostic('error', 'ERR', 'msg', $e);
        assertSame($e, $d->evidence);
    }

    #[DataProvider('validSeverityProvider')]
    public function testAcceptsValidSeverity(string $severity): void
    {
        $d = new Diagnostic($severity, 'CODE', 'msg');
        assertSame($severity, $d->severity);
    }

    /** @return list<array{string}> */
    public static function validSeverityProvider(): array
    {
        return [['info'], ['warning'], ['error']];
    }

    public function testRejectsInvalidSeverity(): void
    {
        assertThrows(
            static fn() => new Diagnostic('critical', 'CODE', 'msg'),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyCode(): void
    {
        assertThrows(
            static fn() => new Diagnostic('info', '', 'msg'),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyMessage(): void
    {
        assertThrows(
            static fn() => new Diagnostic('info', 'CODE', ''),
            InvalidArgumentException::class,
        );
    }

    public function testJsonSerializationWithoutEvidence(): void
    {
        $d = new Diagnostic('info', 'X', 'msg');
        $json = $d->jsonSerialize();

        assertSame('info', $json['severity']);
        assertSame('X', $json['code']);
        assertSame('msg', $json['message']);
        $this->assertArrayNotHasKey('evidence', $json);
    }

    public function testJsonSerializationWithEvidence(): void
    {
        $e = new Evidence('src/Foo.php', 1, 5);
        $d = new Diagnostic('warning', 'DEAD_CODE', 'Dead code found', $e);
        $json = $d->jsonSerialize();

        $this->assertNotNull($json['evidence']);
        // Evidence is passed raw (not flattened) in jsonSerialize;
        // verify its properties via the object's own accessors.
        assertSame('src/Foo.php', $json['evidence']->relativePath);
        assertSame(1, $json['evidence']->startLine);
        assertSame(5, $json['evidence']->endLine);
    }
}
