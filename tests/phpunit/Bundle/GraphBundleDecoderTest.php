<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Bundle;

use InvalidArgumentException;
use Knossos\Bundle\GraphBundleDecoder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('graph-bundle-decoder')]
final class GraphBundleDecoderTest extends TestCase
{
    private const TABLES = ['files', 'nodes', 'edges', 'classifications', 'boundaries', 'memberships', 'diagnostics'];

    // ----- shape -----

    public function testClassIsFinal(): void
    {
        $this->assertTrue((new \ReflectionClass(GraphBundleDecoder::class))->isFinal());
    }

    public function testDecodeAndValidateIsPublicInstanceMethod(): void
    {
        $method = (new \ReflectionClass(GraphBundleDecoder::class))->getMethod('decodeAndValidate');

        $this->assertTrue($method->isPublic());
        $this->assertFalse($method->isStatic());
    }

    public function testEncodeCanonicalIsPublicStaticMethod(): void
    {
        $method = (new \ReflectionClass(GraphBundleDecoder::class))->getMethod('encodeCanonical');

        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    // ----- constants -----

    public function testFormatConstant(): void
    {
        assertSame('knossos.graph.bundle', GraphBundleDecoder::FORMAT);
    }

    public function testVersionConstant(): void
    {
        assertSame(2, GraphBundleDecoder::VERSION);
    }

    public function testMaxCompressedBytesConstant(): void
    {
        assertSame(10_000_000, GraphBundleDecoder::MAX_COMPRESSED_BYTES);
    }

    public function testMaxUncompressedBytesConstant(): void
    {
        assertSame(8_000_000, GraphBundleDecoder::MAX_UNCOMPRESSED_BYTES);
    }

    public function testMaxFactsConstant(): void
    {
        assertSame(200_000, GraphBundleDecoder::MAX_FACTS);
    }

    public function testMaxStructuralTokensConstant(): void
    {
        assertSame(2_000_000, GraphBundleDecoder::MAX_STRUCTURAL_TOKENS);
    }

    // ----- encodeCanonical() -----

    public function testEncodeCanonicalReturnsStringForScalarInput(): void
    {
        $json = GraphBundleDecoder::encodeCanonical('hello');

        assertSame('"hello"', $json);
    }

    public function testEncodeCanonicalReturnsStringForIntegerInput(): void
    {
        $json = GraphBundleDecoder::encodeCanonical(42);

        assertSame('42', $json);
    }

    public function testEncodeCanonicalEncodesNullAsJsonNull(): void
    {
        $json = GraphBundleDecoder::encodeCanonical(null);

        assertSame('null', $json);
    }

    public function testEncodeCanonicalEncodesBoolAsJsonBoolean(): void
    {
        assertSame('true', GraphBundleDecoder::encodeCanonical(true));
        assertSame('false', GraphBundleDecoder::encodeCanonical(false));
    }

    public function testEncodeCanonicalEncodesEmptyListAsJsonArray(): void
    {
        assertSame('[]', GraphBundleDecoder::encodeCanonical([]));
    }

    public function testEncodeCanonicalPreservesOrderInList(): void
    {
        $json = GraphBundleDecoder::encodeCanonical(['c', 'a', 'b']);

        assertSame('["c","a","b"]', $json);
    }

    public function testEncodeCanonicalSortsObjectKeysAlphabetically(): void
    {
        $json = GraphBundleDecoder::encodeCanonical(['z' => 1, 'a' => 2, 'm' => 3]);

        assertSame('{"a":2,"m":3,"z":1}', $json);
    }

    public function testEncodeCanonicalSortsNestedObjectKeys(): void
    {
        $json = GraphBundleDecoder::encodeCanonical([
            'zeta' => ['y' => 1, 'a' => 2],
            'alpha' => ['z' => 1, 'b' => 2],
        ]);

        assertSame('{"alpha":{"b":2,"z":1},"zeta":{"a":2,"y":1}}', $json);
    }

    public function testEncodeCanonicalSortsKeysInsideListElements(): void
    {
        // The list order is preserved, but canonical() recurses into each
        // list element and applies ksort to elements that are themselves
        // associative. Each row in the list is its own object that gets
        // keyed-sorted independently.
        $json = GraphBundleDecoder::encodeCanonical([
            ['z' => 1, 'a' => 2],
            ['z' => 3, 'a' => 4],
        ]);

        assertSame('[{"a":2,"z":1},{"a":4,"z":3}]', $json);
    }

    public function testEncodeCanonicalPreservesUnescapedSlashes(): void
    {
        $json = GraphBundleDecoder::encodeCanonical('https://example.com/path');

        $this->assertStringContainsString('https://example.com/path', $json);
        $this->assertStringNotContainsString('\\/', $json);
    }

    public function testEncodeCanonicalPreservesUnicode(): void
    {
        $json = GraphBundleDecoder::encodeCanonical('café');

        assertSame('"café"', $json);
    }

    // ----- decodeAndValidate(): input bounds -----

    public function testDecodeRejectsEmptyString(): void
    {
        $error = captureThrows(
            static fn () => (new GraphBundleDecoder())->decodeAndValidate(''),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('empty or exceeds the compressed byte limit', $error->getMessage());
    }

    public function testDecodeRejectsStringExceedingCompressedByteLimit(): void
    {
        // A string longer than MAX_COMPRESSED_BYTES (10 MB) is rejected at
        // the size gate before any decompression is attempted.
        $oversized = str_repeat('a', GraphBundleDecoder::MAX_COMPRESSED_BYTES + 1);

        $error = captureThrows(
            static fn () => (new GraphBundleDecoder())->decodeAndValidate($oversized),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('empty or exceeds the compressed byte limit', $error->getMessage());
    }

    public function testDecodeRejectsHighTokenDensityBeforeJsonDecode(): void
    {
        // A tiny gzip that expands to a dense scalar array whose structural-token
        // count exceeds MAX_STRUCTURAL_TOKENS. Left unchecked, json_decode would
        // allocate millions of zvals; the density gate must reject it first, and
        // it fires before the shape/checksum checks (the payload is not a bundle).
        $dense = '[' . str_repeat('0,', GraphBundleDecoder::MAX_STRUCTURAL_TOKENS + 10) . '0]';
        $compressed = gzencode($dense, 9, ZLIB_ENCODING_GZIP);
        $this->assertIsString($compressed);
        $this->assertLessThan(GraphBundleDecoder::MAX_COMPRESSED_BYTES, strlen($compressed));

        $error = captureThrows(
            static fn () => (new GraphBundleDecoder())->decodeAndValidate($compressed),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('structural token density', $error->getMessage());
    }

    public function testDecodeRejectsInvalidGzipData(): void
    {
        // A short, non-gzip byte string.
        $error = captureThrows(
            static fn () => (new GraphBundleDecoder())->decodeAndValidate('not-gzip-data'),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('not valid bounded gzip data', $error->getMessage());
    }

    public function testDecodeRejectsInvalidJsonAfterGzipDecompression(): void
    {
        // Gzip-compress invalid JSON to exercise the json_decode failure path
        // with JSON_THROW_ON_ERROR. The gzdecode succeeds but json_decode throws.
        $compressed = gzencode('{invalid json}');
        $this->assertNotFalse($compressed);

        $this->expectException(\JsonException::class);
        (new GraphBundleDecoder())->decodeAndValidate($compressed);
    }

    public function testDecodeRejectsJsonExceedingDecodeDepth(): void
    {
        // Build deeply nested JSON that exceeds the 128 depth limit passed to
        // json_decode. Create 129 levels of nesting.
        $nested = '{"a":';
        for ($i = 0; $i < 128; ++$i) {
            $nested .= '{"a":';
        }
        $nested .= 'null';
        for ($i = 0; $i < 129; ++$i) {
            $nested .= '}';
        }

        $compressed = gzencode($nested);
        $this->assertNotFalse($compressed);

        $this->expectException(\JsonException::class);
        (new GraphBundleDecoder())->decodeAndValidate($compressed);
    }

    // ----- decodeAndValidate(): root shape -----

    public function testDecodeRejectsRootThatIsJsonString(): void
    {
        $bundle = $this->wrapRoot('"just a string"');

        $this->expectBundleError($bundle, 'Bundle root is invalid');
    }

    public function testDecodeRejectsRootThatIsJsonList(): void
    {
        $bundle = $this->wrapRoot('[1, 2, 3]');

        $this->expectBundleError($bundle, 'Bundle root is invalid');
    }

    public function testDecodeRejectsRootWithMissingManifestKey(): void
    {
        $bundle = $this->wrapRoot('{"payload": []}');

        $this->expectBundleError($bundle, 'Bundle root is invalid');
    }

    public function testDecodeRejectsRootWithMissingPayloadKey(): void
    {
        $bundle = $this->wrapRoot('{"manifest": {}}');

        $this->expectBundleError($bundle, 'Bundle root is invalid');
    }

    public function testDecodeRejectsRootWithExtraTopLevelKey(): void
    {
        $bundle = $this->wrapRoot('{"manifest": {}, "payload": {}, "extra": 1}');

        $this->expectBundleError($bundle, 'Bundle root is invalid');
    }

    public function testDecodeRejectsRootWithReversedManifestAndPayloadKeys(): void
    {
        // The check is order-sensitive: `array_keys($bundle) !== ['manifest', 'payload']`.
        $bundle = $this->wrapRoot('{"payload": [], "manifest": {}}');

        $this->expectBundleError($bundle, 'Bundle root is invalid');
    }

    // ----- decodeAndValidate(): manifest + payload object checks -----

    public function testDecodeRejectsManifestThatIsNotAnObject(): void
    {
        $bundle = $this->encode(['manifest' => 'not-an-object', 'payload' => $this->minimalPayload()]);

        $this->expectBundleError($bundle, 'Bundle manifest must be an object');
    }

    public function testDecodeRejectsManifestThatIsAList(): void
    {
        $bundle = $this->encode(['manifest' => [1, 2], 'payload' => $this->minimalPayload()]);

        $this->expectBundleError($bundle, 'Bundle manifest must be an object');
    }

    public function testDecodeRejectsPayloadThatIsNotAnObject(): void
    {
        $bundle = $this->encode(['manifest' => $this->validManifest($this->minimalPayload()), 'payload' => 'not-an-object']);

        // The root-shape gate passes (keys are exactly ['manifest','payload']);
        // the rejection comes from the payload's per-field object() check.
        $this->expectBundleError($bundle, 'Bundle payload must be an object');
    }

    public function testDecodeRejectsPayloadThatIsAList(): void
    {
        $bundle = $this->encode(['manifest' => $this->validManifest($this->minimalPayload()), 'payload' => [1, 2, 3]]);

        // As above — the root-shape gate passes; payload-as-list is rejected
        // by the per-field object() check.
        $this->expectBundleError($bundle, 'Bundle payload must be an object');
    }

    // ----- decodeAndValidate(): known-key gate -----

    public function testDecodeRejectsUnknownManifestKey(): void
    {
        // NOTE: PHP `+` keeps left-side keys on conflicts (so `format` etc.
        // stay intact); it ADDS new keys without overwriting. Switching to
        // `array_merge` would override 'mystery' away — wrong here; required
        // when overriding an existing default in tests that need to replace it.
        $bundle = $this->encode(['manifest' => $this->validManifest($this->minimalPayload()) + ['mystery' => 1], 'payload' => $this->minimalPayload()]);

        $this->expectBundleError($bundle, 'Bundle manifest contains unknown keys: mystery');
    }

    public function testDecodeRejectsUnknownPayloadKey(): void
    {
        $bundle = $this->encode(['manifest' => $this->validManifest($this->minimalPayload()), 'payload' => $this->minimalPayload() + ['wat' => 'who']]);

        $this->expectBundleError($bundle, 'Bundle payload contains unknown keys: wat');
    }

    public function testDecodeRejectsMultipleUnknownManifestKeys(): void
    {
        // Multiple unknown keys exercise implode(', ', $unknown) which produces
        // a different string than single-key implode, catching mutants on the
        // glue string.
        $bundle = $this->encode(['manifest' => $this->validManifest($this->minimalPayload()) + ['alpha' => 1, 'beta' => 2], 'payload' => $this->minimalPayload()]);

        $this->expectBundleError($bundle, 'Bundle manifest contains unknown keys: alpha, beta');
    }

    public function testDecodeRejectsMultipleUnknownPayloadKeys(): void
    {
        $bundle = $this->encode(['manifest' => $this->validManifest($this->minimalPayload()), 'payload' => $this->minimalPayload() + ['extra1' => 'a', 'extra2' => 'b']]);

        $this->expectBundleError($bundle, 'Bundle payload contains unknown keys: extra1, extra2');
    }

    // ----- decodeAndValidate(): format/version gate -----

    public function testDecodeRejectsWrongFormat(): void
    {
        $bundle = $this->encode([
            'manifest' => $this->validManifest($this->minimalPayload(), ['format' => 'wrong']),
            'payload' => $this->minimalPayload(),
        ]);

        $this->expectBundleError($bundle, 'Bundle format or schema version is unsupported');
    }

    public function testDecodeRejectsMissingFormat(): void
    {
        $bundle = $this->encode([
            'manifest' => array_merge($this->validManifest($this->minimalPayload()), ['format' => null]),
            'payload' => $this->minimalPayload(),
        ]);

        $this->expectBundleError($bundle, 'Bundle format or schema version is unsupported');
    }

    public function testDecodeRejectsWrongVersion(): void
    {
        $bundle = $this->encode([
            'manifest' => $this->validManifest($this->minimalPayload(), ['version' => 1]),
            'payload' => $this->minimalPayload(),
        ]);

        $this->expectBundleError($bundle, 'Bundle format or schema version is unsupported');
    }

    public function testDecodeRejectsNonIntegerVersion(): void
    {
        $bundle = $this->encode([
            'manifest' => $this->validManifest($this->minimalPayload(), ['version' => '2']),
            'payload' => $this->minimalPayload(),
        ]);

        $this->expectBundleError($bundle, 'Bundle format or schema version is unsupported');
    }

    // ----- decodeAndValidate(): redaction gate -----

    public function testDecodeRejectsInvalidRedactionMode(): void
    {
        $bundle = $this->encode([
            'manifest' => $this->validManifest($this->minimalPayload(), ['redaction' => 'obfuscate']),
            'payload' => $this->minimalPayload(),
        ]);

        $this->expectBundleError($bundle, 'Bundle redaction mode is invalid');
    }

    public function testDecodeAcceptsNonePathsAndStrictRedaction(): void
    {
        foreach (['none', 'paths', 'strict'] as $mode) {
            $payload = $this->minimalPayload();
            $bundle = $this->encode([
                'manifest' => $this->validManifest($payload, ['redaction' => $mode]),
                'payload' => $payload,
            ]);
            $result = (new GraphBundleDecoder())->decodeAndValidate($bundle);
            assertSame($mode, $result['manifest']['redaction']);
        }
    }

    // ----- decodeAndValidate(): checksum + byte-size gates -----

    public function testDecodeRejectsBadChecksum(): void
    {
        $bundle = $this->encode([
            'manifest' => $this->validManifest($this->minimalPayload(), ['checksum' => 'sha256:wrong']),
            'payload' => $this->minimalPayload(),
        ]);

        $this->expectBundleError($bundle, 'Bundle checksum validation failed');
    }

    public function testDecodeRejectsMissingChecksumPrefix(): void
    {
        $bundle = $this->encode([
            'manifest' => $this->validManifest($this->minimalPayload(), ['checksum' => 'wrong']),
            'payload' => $this->minimalPayload(),
        ]);

        $this->expectBundleError($bundle, 'Bundle checksum validation failed');
    }

    public function testDecodeRejectsWrongUncompressedBytes(): void
    {
        $bundle = $this->encode([
            'manifest' => $this->validManifest($this->minimalPayload(), ['uncompressed_bytes' => 999999]),
            'payload' => $this->minimalPayload(),
        ]);

        $this->expectBundleError($bundle, 'Bundle declared byte size is invalid');
    }

    // ----- decodeAndValidate(): per-table list gate -----

    public function testDecodeRejectsNonListTable(): void
    {
        // Each of the 7 fact tables must be a JSON list. Try with files
        // being an object instead.
        $payload = array_merge($this->minimalPayload(), ['files' => ['this' => 'is-an-object']]);
        $bundle = $this->encode([
            'manifest' => $this->validManifest($payload),
            'payload' => $payload,
        ]);

        $this->expectBundleError($bundle, 'Bundle table files must be a list');
    }

    public function testDecodeRejectsNonListTableForEveryTableName(): void
    {
        // Same gate, applied to each of the 7 tables — the source's
        // error message names which table is wrong.
        foreach (self::TABLES as $table) {
            $payload = array_merge($this->minimalPayload(), [$table => ['oops' => 'not-a-list']]);
            $bundle = $this->encode([
                'manifest' => $this->validManifest($payload),
                'payload' => $payload,
            ]);

            $this->expectBundleError($bundle, 'Bundle table ' . $table . ' must be a list');
        }
    }

    // ----- decodeAndValidate(): fact-count gate -----

    public function testDecodeRejectsFactCountMismatch(): void
    {
        // 3 actual rows across all 7 tables, manifest claims 5.
        $payload = $this->minimalPayload() + [
            'files' => [['id' => 'f1'], ['id' => 'f2'], ['id' => 'f3']],
        ];
        $bundle = $this->encode([
            'manifest' => $this->validManifest($payload, ['fact_count' => 5]),
            'payload' => $payload,
        ]);

        $this->expectBundleError($bundle, 'Bundle fact count is invalid');
    }

    public function testDecodeRejectsFactCountExceedingLimit(): void
    {
        // manifest['fact_count'] = MAX + 1, but no rows. The mismatch
        // check fires first; either branch routes to the same error.
        $bundle = $this->encode([
            'manifest' => $this->validManifest($this->minimalPayload(), ['fact_count' => GraphBundleDecoder::MAX_FACTS + 1]),
            'payload' => $this->minimalPayload(),
        ]);

        $this->expectBundleError($bundle, 'Bundle fact count is invalid');
    }

    // ----- decodeAndValidate(): happy path -----

    public function testDecodeReturnsManifestPayloadAndCountsForValidBundle(): void
    {
        $payload = array_merge($this->minimalPayload(), [
            'files' => [['id' => 'f1'], ['id' => 'f2']],
            'nodes' => [['id' => 'n1']],
        ]);
        $bundle = $this->encode([
            'manifest' => $this->validManifest($payload),
            'payload' => $payload,
        ]);

        $result = (new GraphBundleDecoder())->decodeAndValidate($bundle);

        assertSame(GraphBundleDecoder::FORMAT, $result['manifest']['format']);
        assertSame(GraphBundleDecoder::VERSION, $result['manifest']['version']);
        assertSame(3, $result['fact_count']);
        // The returned checksum is the bare hex (no 'sha256:' prefix),
        // and equals sha256(canonicalJson(payload)).
        $this->assertIsString($result['checksum']);
        $this->assertStringStartsNotWith('sha256:', $result['checksum']);
        assertSame(hash('sha256', GraphBundleDecoder::encodeCanonical($payload)), $result['checksum']);
        assertSame(2, count($result['payload']['files']));
        assertSame(1, count($result['payload']['nodes']));
    }

    public function testDecodeReturnsManifestEchoesChecksumWithSha256Prefix(): void
    {
        // The RETURNED 'manifest' field still has the 'sha256:' prefix on
        // its checksum; only the top-level 'checksum' key is stripped.
        $payload = $this->minimalPayload();
        $bundle = $this->encode([
            'manifest' => $this->validManifest($payload),
            'payload' => $payload,
        ]);

        $result = (new GraphBundleDecoder())->decodeAndValidate($bundle);

        $this->assertStringStartsWith('sha256:', $result['manifest']['checksum']);
    }

    public function testDecodeAcceptsAllEmptyTables(): void
    {
        // Every payload table may be empty; only structural validity matters.
        $bundle = $this->encode([
            'manifest' => $this->validManifest($this->minimalPayload()),
            'payload' => $this->minimalPayload(),
        ]);

        $result = (new GraphBundleDecoder())->decodeAndValidate($bundle);

        assertSame(0, $result['fact_count']);
        foreach (self::TABLES as $table) {
            assertSame([], $result['payload'][$table]);
        }
    }

    // ----- private helpers -----

    /**
     * Compress `$root` (already a JSON-encoded string) into a gzipped bundle.
     * Used only by the root-shape tests where we feed intentionally-invalid
     * top-level JSON to exercise the gzdecode / json_decode failure paths.
     */
    private function wrapRoot(string $jsonRoot): string
    {
        $compressed = gzencode($jsonRoot);
        $this->assertNotFalse($compressed);

        return $compressed;
    }

    /**
     * Build a valid manifest for the given payload, optionally overriding
     * fields. Re-computes checksum, uncompressed_bytes, and fact_count by
     * default so callers can pass simple overrides without re-deriving them.
     */
    private function validManifest(array $payload, array $overrides = []): array
    {
        $payloadJson = GraphBundleDecoder::encodeCanonical($payload);
        $defaults = [
            'format' => GraphBundleDecoder::FORMAT,
            'version' => GraphBundleDecoder::VERSION,
            'redaction' => 'none',
            'checksum' => 'sha256:' . hash('sha256', $payloadJson),
            'uncompressed_bytes' => strlen($payloadJson),
            'fact_count' => $this->countFacts($payload),
            'created_at' => '2025-01-01T00:00:00+00:00',
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * Compress a {manifest, payload} pair into a valid gzipped bundle.
     * Canonical JSON encoding ensures both `array_keys === ['manifest','payload']`
     * and the checksum round-trips.
     *
     * @param array<string, mixed> $bundle
     */
    private function encode(array $bundle): string
    {
        $json = GraphBundleDecoder::encodeCanonical($bundle);
        $compressed = gzencode($json);
        $this->assertNotFalse($compressed);

        return $compressed;
    }

    /**
     * Minimal-valid payload: every table key is present as an empty list,
     * required scan fields are populated.
     *
     * @return array<string, mixed>
     */
    private function minimalPayload(): array
    {
        $payload = [
            'project_name' => 'proj',
            'scan' => ['scanner_set_hash' => 'h', 'finished_at' => '2025-01-01T00:00:00+00:00'],
        ];
        foreach (self::TABLES as $table) {
            $payload[$table] = [];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function countFacts(array $payload): int
    {
        $sum = 0;
        foreach (self::TABLES as $table) {
            $rows = $payload[$table] ?? [];
            $sum += is_array($rows) ? count($rows) : 0;
        }

        return $sum;
    }

    /**
     * Assert that decoding `$bundle` throws an InvalidArgumentException
     * whose message contains the expected substring. Used to keep each
     * rejection-path test to a single line.
     */
    private function expectBundleError(string $bundle, string $messageSubstring): void
    {
        $error = captureThrows(
            static fn () => (new GraphBundleDecoder())->decodeAndValidate($bundle),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString($messageSubstring, $error->getMessage());
    }
}
