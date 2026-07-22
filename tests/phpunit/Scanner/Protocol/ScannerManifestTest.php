<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Protocol;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\ScannerManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-protocol')]
final class ScannerManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function minimalData(): array
    {
        return [
            'id' => 'test.knossos',
            'version' => '0.1.0',
            'protocol_version' => '1.0',
            'output_schema_version' => '1.0',
            'languages' => ['php'],
            'file_extensions' => ['php'],
            'capabilities' => ['scan'],
        ];
    }

    // ----- shape -----

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(ScannerManifest::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    // ----- constructor happy path -----

    public function testConstructorStoresProperties(): void
    {
        $m = new ScannerManifest(
            id: 'test.knossos',
            version: '0.1.0',
            protocolVersion: '1.0',
            outputSchemaVersion: '1.0',
            languages: ['php', 'python'],
            fileExtensions: ['php', 'py'],
            capabilities: ['scan', 'cancel'],
        );

        assertSame('test.knossos', $m->id);
        assertSame('0.1.0', $m->version);
        assertSame('1.0', $m->protocolVersion);
        assertSame('1.0', $m->outputSchemaVersion);
        assertSame(['php', 'python'], $m->languages);
        assertSame(['php', 'py'], $m->fileExtensions);
        assertSame(['scan', 'cancel'], $m->capabilities);
    }

    // ----- constructor validation -----

    public function testRejectsEmptyId(): void
    {
        assertThrows(
            static fn() => new ScannerManifest('', 'v', 'p', 'o', ['php'], ['php'], []),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyVersion(): void
    {
        assertThrows(
            static fn() => new ScannerManifest('id', '', 'p', 'o', ['php'], ['php'], []),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyProtocolVersion(): void
    {
        assertThrows(
            static fn() => new ScannerManifest('id', 'v', '', 'o', ['php'], ['php'], []),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyOutputSchemaVersion(): void
    {
        assertThrows(
            static fn() => new ScannerManifest('id', 'v', 'p', '', ['php'], ['php'], []),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyLanguagesList(): void
    {
        assertThrows(
            static fn() => new ScannerManifest('id', 'v', 'p', 'o', [], ['php'], []),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsNonStringInLanguages(): void
    {
        assertThrows(
            static fn() => new ScannerManifest('id', 'v', 'p', 'o', ['php', 42], ['php'], []),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyStringInFileExtensions(): void
    {
        assertThrows(
            static fn() => new ScannerManifest('id', 'v', 'p', 'o', ['php'], [''], []),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEmptyStringInCapabilities(): void
    {
        assertThrows(
            static fn() => new ScannerManifest('id', 'v', 'p', 'o', ['php'], ['php'], ['']),
            InvalidArgumentException::class,
        );
    }

    // ----- fromArray -----

    public function testFromArrayConstructsFromValidData(): void
    {
        $m = ScannerManifest::fromArray(self::minimalData());

        assertSame('test.knossos', $m->id);
        assertSame('0.1.0', $m->version);
        assertSame('1.0', $m->protocolVersion);
        assertSame('1.0', $m->outputSchemaVersion);
        assertSame(['php'], $m->languages);
        assertSame(['php'], $m->fileExtensions);
        assertSame(['scan'], $m->capabilities);
    }

    /** @return list<array{string, mixed, string}> */
    public static function missingStringFieldProvider(): array
    {
        return [
            'missing id' => ['id', null, 'id'],
            'version not a string' => ['version', 42, 'version'],
            'missing protocol_version' => ['protocol_version', null, 'protocol_version'],
            'output_schema_version not a string' => ['output_schema_version', true, 'output_schema_version'],
        ];
    }

    /** @return list<array{string, mixed, string}> */
    public static function missingListFieldProvider(): array
    {
        return [
            'languages not a list' => ['languages', 'not-array', 'languages'],
            'file_extensions associative' => ['file_extensions', ['a' => 'php'], 'file_extensions'],
            'capabilities null' => ['capabilities', null, 'capabilities'],
        ];
    }

    #[DataProvider('missingStringFieldProvider')]
    public function testFromArrayRejectsInvalidStringField(string $key, mixed $value, string $expectedSnippet): void
    {
        $data = self::minimalData();
        $data[$key] = $value;

        assertThrows(
            static fn() => ScannerManifest::fromArray($data),
            InvalidArgumentException::class,
        );
    }

    #[DataProvider('missingListFieldProvider')]
    public function testFromArrayRejectsInvalidListField(string $key, mixed $value, string $expectedSnippet): void
    {
        $data = self::minimalData();
        $data[$key] = $value;

        assertThrows(
            static fn() => ScannerManifest::fromArray($data),
            InvalidArgumentException::class,
        );
    }

    // ----- jsonSerialize -----

    public function testJsonSerialization(): void
    {
        $m = ScannerManifest::fromArray(self::minimalData());
        $json = $m->jsonSerialize();

        assertSame('test.knossos', $json['id']);
        assertSame('0.1.0', $json['version']);
        assertSame('1.0', $json['protocol_version']);
        assertSame('1.0', $json['output_schema_version']);
        assertSame(['php'], $json['languages']);
        assertSame(['php'], $json['file_extensions']);
        assertSame(['scan'], $json['capabilities']);
    }
}
