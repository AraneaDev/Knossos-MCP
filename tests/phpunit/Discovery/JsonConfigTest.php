<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\JsonConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('json-config')]
final class JsonConfigTest extends TestCase
{
    public function testDecodeReturnsAssociativeArrayForValidObject(): void
    {
        $decoded = JsonConfig::decode('{"name": "app", "version": "1.0"}');

        assertSame(['name' => 'app', 'version' => '1.0'], $decoded);
    }

    public function testEmptyJsonObjectIsRejectedAsRootConfiguration(): void
    {
        // {} decodes to [] which PHP considers a list (array_is_list([]) is true),
        // so the source rejects empty objects as non-object roots.
        $error = captureThrows(
            static fn () => JsonConfig::decode('{}'),
            DiscoveryException::class,
        );

        assertSame('Configuration root must be a JSON object.', $error->getMessage());
    }

    public function testDecodeReturnsNestedStructures(): void
    {
        $decoded = JsonConfig::decode('{"a": {"b": [1, 2, 3]}, "c": null}');

        assertSame(['a' => ['b' => [1, 2, 3]], 'c' => null], $decoded);
    }

    public function testDecodeThrowsOnMalformedJson(): void
    {
        $error = captureThrows(
            static fn () => JsonConfig::decode('{not valid json'),
            DiscoveryException::class,
        );

        $this->assertStringStartsWith('Invalid JSON configuration:', $error->getMessage());
    }

    public function testDecodeChainsOriginalJsonExceptionAsPrevious(): void
    {
        $error = captureThrows(
            static fn () => JsonConfig::decode('{'),
            DiscoveryException::class,
        );

        $this->assertNotNull($error->getPrevious());
        $this->assertInstanceOf(\JsonException::class, $error->getPrevious());
    }

    public function testDecodeRejectsJsonArrayRoot(): void
    {
        $error = captureThrows(
            static fn () => JsonConfig::decode('[1, 2, 3]'),
            DiscoveryException::class,
        );

        assertSame('Configuration root must be a JSON object.', $error->getMessage());
    }

    public function testDecodeRejectsEmptyJsonArrayRoot(): void
    {
        $error = captureThrows(
            static fn () => JsonConfig::decode('[]'),
            DiscoveryException::class,
        );

        assertSame('Configuration root must be a JSON object.', $error->getMessage());
    }

    public function testDecodeRejectsScalarRoot(): void
    {
        $error = captureThrows(
            static fn () => JsonConfig::decode('42'),
            DiscoveryException::class,
        );

        assertSame('Configuration root must be a JSON object.', $error->getMessage());
    }

    public function testDecodeRejectsStringRoot(): void
    {
        $error = captureThrows(
            static fn () => JsonConfig::decode('"plain string"'),
            DiscoveryException::class,
        );

        assertSame('Configuration root must be a JSON object.', $error->getMessage());
    }

    public function testDecodeStripsSingleLineCommentsWhenFlagIsTrue(): void
    {
        $json = <<<'JSON'
        {
            // single line comment
            "key": "value"
        }
        JSON;

        $decoded = JsonConfig::decode($json, allowComments: true);

        assertSame(['key' => 'value'], $decoded);
    }

    public function testDecodeStripsMultiLineCommentsWhenFlagIsTrue(): void
    {
        $json = <<<'JSON'
        {
            /* multi
               line
               comment */
            "key": "value"
        }
        JSON;

        $decoded = JsonConfig::decode($json, allowComments: true);

        assertSame(['key' => 'value'], $decoded);
    }

    public function testDecodeRejectsCommentsByDefault(): void
    {
        $json = '{"key": "value" /* with comment */}';

        $error = captureThrows(
            static fn () => JsonConfig::decode($json),
            DiscoveryException::class,
        );

        $this->assertStringStartsWith('Invalid JSON configuration:', $error->getMessage());
    }

    public function testDecodeStripsTrailingCommasBeforeClosingBraceWhenFlagIsTrue(): void
    {
        $json = '{"a": 1, "b": 2,}';

        $decoded = JsonConfig::decode($json, allowComments: true);

        assertSame(['a' => 1, 'b' => 2], $decoded);
    }

    public function testDecodeStripsTrailingCommaBeforeClosingBracketWhenFlagIsTrue(): void
    {
        $json = '{"list": [1, 2, 3,]}';

        $decoded = JsonConfig::decode($json, allowComments: true);

        assertSame(['list' => [1, 2, 3]], $decoded);
    }

    public function testDecodeKeepsCommentLikeContentInsideStringValues(): void
    {
        // The // inside a string must NOT be stripped as a line comment.
        $decoded = JsonConfig::decode(
            '{"url": "https://example.com/path"}',
            allowComments: true,
        );

        assertSame(['url' => 'https://example.com/path'], $decoded);
    }

    public function testDecodeKeepsBlockCommentLikeContentInsideStringValues(): void
    {
        // The /* */ inside a string must NOT be stripped as a comment.
        $decoded = JsonConfig::decode(
            '{"text": "before /* inside */ after"}',
            allowComments: true,
        );

        assertSame(['text' => 'before /* inside */ after'], $decoded);
    }

    public function testDecodeKeepsEscapedQuotesInsideStrings(): void
    {
        // \" inside a string is an escape, not a string terminator.
        $decoded = JsonConfig::decode(
            '{"key": "value with \\"escaped\\" quotes"}',
            allowComments: true,
        );

        assertSame(['key' => 'value with "escaped" quotes'], $decoded);
    }

    public function testDecodeKeepsTrailingCommaInsideStringValue(): void
    {
        // A comma inside a string must NOT be treated as a trailing comma.
        $decoded = JsonConfig::decode(
            '{"sentence": "hello, world",}',
            allowComments: true,
        );

        assertSame(['sentence' => 'hello, world'], $decoded);
    }

    public function testDecodeReturnsBooleanAndNumberValues(): void
    {
        $decoded = JsonConfig::decode('{"enabled": true, "count": 0, "ratio": 3.14}');

        assertSame(['enabled' => true, 'count' => 0, 'ratio' => 3.14], $decoded);
    }

    public function testClassIsFinalAndHasPrivateConstructor(): void
    {
        $reflection = new \ReflectionClass(JsonConfig::class);
        $constructor = $reflection->getConstructor();

        $this->assertTrue($reflection->isFinal());
        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }
}
