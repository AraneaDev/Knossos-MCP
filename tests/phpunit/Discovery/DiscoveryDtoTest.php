<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\FileFingerprint;
use Knossos\Discovery\JsonConfig;
use Knossos\Discovery\ProjectUnit;
use PHPUnit\Framework\Attributes\Group;

/**
 * Direct tests for the 4 Discovery DTO files:
 *
 *   - src/Discovery/ProjectUnit.php      (4 promoted props, mut-active)
 *   - src/Discovery/DiscoveredFile.php   (7 promoted props + lineCount=0 default, mut-active)
 *   - src/Discovery/FileFingerprint.php  (constructor + static compute() with binary
 *                                        file read, mut-active)
 *   - src/Discovery/JsonConfig.php       (static decode() + private stripComments +
 *                                        stripTrailingCommas, mut-active)
 *
 * ProjectUnit + DiscoveredFile were inline-read during batch 11a's
 * M8 cross-batch signature fix; their constructor signatures are
 * already known (verified inline). FileFingerprint + JsonConfig
 * were read for this batch.
 *
 * Convention: public `decode()` entry point is used to exercise
 * JsonConfig's private stripComments/stripTrailingCommas helpers
 * without reflection — the public decode() with `allowComments=true`
 * routes through both helpers naturally.
 *
 * FileFingerprint::compute() requires real filesystem; tested via
 * the inline tempFile() helper.
 *
 * Conventions match batches 1-11a: bare global helpers from
 * `tests/phpunit/Support/Assertions.php`; class-level
 * `#[Group('discovery-dto')]`. NO `#[CoversClass]`. NO `assertTrue`.
 */
#[Group('discovery-dto')]
final class DiscoveryDtoTest extends \Knossos\Tests\Phpunit\KnossosTestCase
{
    /**
     * Create a temporary file with given content; returns the absolute
     * path. Caller is responsible for cleanup if needed (PHPUnit
     * tears down the temp dir at process exit).
     */
    private function tempFile(string $relativePath, string $content): string
    {
        $base = sys_get_temp_dir() . '/knossos-discovery-' . bin2hex(random_bytes(6));
        mkdir($base, 0700, true);
        $absolute = $base . '/' . $relativePath;
        $dir = dirname($absolute);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($absolute, $content);
        return $absolute;
    }

    // ===== ProjectUnit ====================================================

    public function testProjectUnitConstructorStoresPromotedProperties(): void
    {
        // M1 / ProjectUnit::__construct() success arm: 4 promoted props
        // assigned correctly (kind, configPath, contentHash required;
        // metadata defaults to []).
        $unit = new ProjectUnit(
            kind: 'composer',
            configPath: 'composer.json',
            contentHash: 'unit-hash-1',
            metadata: ['name' => 'foo/bar'],
        );
        assertSame('composer', $unit->kind);
        assertSame('composer.json', $unit->configPath);
        assertSame('unit-hash-1', $unit->contentHash);
        assertSame(['name' => 'foo/bar'], $unit->metadata);
    }

    public function testProjectUnitDefaultsMetadataToEmptyArray(): void
    {
        // M2 / ProjectUnit::__construct() default arg: metadata defaults
        // to [] when not provided.
        $unit = new ProjectUnit(
            kind: 'node',
            configPath: 'package.json',
            contentHash: 'unit-hash-2',
        );
        assertSame([], $unit->metadata);
    }

    // ===== DiscoveredFile =================================================

    public function testDiscoveredFileConstructorStoresAllSevenProps(): void
    {
        // M3 / DiscoveredFile::__construct() success arm: all 7 promoted
        // props assigned correctly. (Verified inline from source.)
        $file = new DiscoveredFile(
            relativePath: 'src/Foo.php',
            absolutePath: '/repo/src/Foo.php',
            language: 'php',
            size: 1024,
            mtime: 1_700_000_000,
            contentHash: 'content-hash-1',
            lineCount: 42,
        );
        assertSame('src/Foo.php', $file->relativePath);
        assertSame('/repo/src/Foo.php', $file->absolutePath);
        assertSame('php', $file->language);
        assertSame(1024, $file->size);
        assertSame(1_700_000_000, $file->mtime);
        assertSame('content-hash-1', $file->contentHash);
        assertSame(42, $file->lineCount);
    }

    public function testDiscoveredFileDefaultsLineCountToZero(): void
    {
        // M4 / DiscoveredFile::__construct() default arg: lineCount
        // defaults to 0 when not provided.
        $file = new DiscoveredFile(
            relativePath: 'src/Bar.php',
            absolutePath: '/repo/src/Bar.php',
            language: 'php',
            size: 512,
            mtime: 1_700_000_000,
            contentHash: 'content-hash-2',
        );
        assertSame(0, $file->lineCount);
    }

    // ===== FileFingerprint ================================================

    public function testFileFingerprintConstructorStoresPromotedProperties(): void
    {
        // M5 / FileFingerprint::__construct() success arm: 2 promoted
        // props (contentHash, lineCount).
        $fp = new FileFingerprint(contentHash: 'sha256-hex', lineCount: 7);
        assertSame('sha256-hex', $fp->contentHash);
        assertSame(7, $fp->lineCount);
    }

    public function testFileFingerprintComputeOnEmptyFileReturnsZeroLines(): void
    {
        // M6 / FileFingerprint::compute() empty-file arm: empty content
        // -> lineCount = 0 (no content seen, no trailing newline); hash
        // is the SHA-256 of empty content (sha256('') = e3b0c44...).
        $path = $this->tempFile('empty.txt', '');
        $fp = FileFingerprint::compute($path);
        assertNotSame(null, $fp);
        assertSame(0, $fp->lineCount);
        // SHA-256 of empty input is the well-known constant.
        assertSame('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $fp->contentHash);
    }

    public function testFileFingerprintComputeOnSingleNewlineFileReturnsOneLine(): void
    {
        // M7 / FileFingerprint::compute() single-newline arm: content
        // "a\n" -> 1 line (1 newline terminator); ends with newline so
        // no trailing-unterminated bump.
        $path = $this->tempFile('one-newline.txt', "a\n");
        $fp = FileFingerprint::compute($path);
        assertNotSame(null, $fp);
        assertSame(1, $fp->lineCount);
    }

    public function testFileFingerprintComputeOnSingleLineNoNewlineReturnsOneLine(): void
    {
        // M8 / FileFingerprint::compute() single-no-newline arm:
        // content "a" (no trailing newline) -> 1 line (sawContent=true,
        // endsWithNewline=false, increments lines to 1).
        $path = $this->tempFile('one-no-newline.txt', 'a');
        $fp = FileFingerprint::compute($path);
        assertNotSame(null, $fp);
        assertSame(1, $fp->lineCount);
    }

    public function testFileFingerprintComputeOnMultiLineFileCountsCorrectly(): void
    {
        // M9 / FileFingerprint::compute() multi-line arm: 3 lines + a
        // trailing unterminated line (4 total). "a\nb\nc\nd" -> 3
        // newlines + 1 trailing bump = 4 lines.
        $path = $this->tempFile('multi.txt', "a\nb\nc\nd");
        $fp = FileFingerprint::compute($path);
        assertNotSame(null, $fp);
        assertSame(4, $fp->lineCount);
    }

    public function testFileFingerprintComputeOnMissingFileReturnsNull(): void
    {
        // M10 / FileFingerprint::compute() not-found arm: fopen fails
        // -> returns null (not throws).
        $fp = FileFingerprint::compute('/nonexistent-' . bin2hex(random_bytes(4)) . '.txt');
        assertSame(null, $fp);
    }

    // ===== JsonConfig =====================================================

    public function testJsonConfigDecodeAcceptsSimpleObject(): void
    {
        // M11 / JsonConfig::decode() success arm: simple JSON object
        // decodes to associative array.
        $decoded = JsonConfig::decode('{"name":"foo","version":"1.0"}');
        assertSame(['name' => 'foo', 'version' => '1.0'], $decoded);
    }

    public function testJsonConfigDecodeAcceptsCommentsWhenFlagTrue(): void
    {
        // M12 / JsonConfig::decode() stripComments + decode arm: with
        // allowComments=true, line comments + block comments are
        // stripped before json_decode. The private stripComments() is
        // exercised via this public entry point.
        $json = <<<JSON
            {
                // comment line
                "name": "foo",
                /* block comment */
                "version": "1.0"
            }
            JSON;
        $decoded = JsonConfig::decode($json, allowComments: true);
        assertSame(['name' => 'foo', 'version' => '1.0'], $decoded);
    }

    public function testJsonConfigDecodeAcceptsTrailingCommasWhenFlagTrue(): void
    {
        // M13 / JsonConfig::decode() stripTrailingCommas + decode arm:
        // with allowComments=true, trailing commas in arrays/objects
        // are stripped. The private stripTrailingCommas() is exercised
        // via this public entry point.
        $json = <<<JSON
            {
                "items": ["a", "b",],
                "name": "foo",
            }
            JSON;
        $decoded = JsonConfig::decode($json, allowComments: true);
        assertSame(['items' => ['a', 'b'], 'name' => 'foo'], $decoded);
    }

    public function testJsonConfigDecodeIgnoresCommentsByDefault(): void
    {
        // M14 / JsonConfig::decode() default-allowComments=false arm:
        // line comments are NOT stripped by default; the resulting JSON
        // is invalid (comments are not JSON syntax) -> throws
        // DiscoveryException.
        assertThrows(
            fn() => JsonConfig::decode('{"name": "foo" // comment\n}'),
            DiscoveryException::class,
        );
    }

    public function testJsonConfigDecodeThrowsOnInvalidJsonSyntax(): void
    {
        // M15 / JsonConfig::decode() json_decode throw arm: malformed
        // JSON triggers JSON_THROW_ON_ERROR -> caught and rethrown as
        // DiscoveryException with the original JsonException as
        // previous.
        assertThrows(
            fn() => JsonConfig::decode('{"name": }'),
            DiscoveryException::class,
        );
    }

    public function testJsonConfigDecodeThrowsOnListAtRoot(): void
    {
        // M16 / JsonConfig::decode() array_is_list guard: JSON list at
        // root is rejected (configuration root must be an object).
        assertThrows(
            fn() => JsonConfig::decode('[1, 2, 3]'),
            DiscoveryException::class,
        );
    }

    public function testJsonConfigDecodeAcceptsNestedObjects(): void
    {
        // M17 / JsonConfig::decode() success arm with nested structures:
        // nested objects + arrays preserved in the decoded array.
        $json = '{"meta": {"author": "alice", "tags": ["cli", "json"]}, "version": "2.0"}';
        $decoded = JsonConfig::decode($json);
        assertSame(['author' => 'alice', 'tags' => ['cli', 'json']], $decoded['meta']);
        assertSame('2.0', $decoded['version']);
    }
}
