<?php

declare(strict_types=1);

namespace Knossos\Bundle;

use InvalidArgumentException;

final class GraphBundleDecoder
{
    public const FORMAT = 'knossos.graph.bundle';
    public const VERSION = 2;
    public const MAX_COMPRESSED_BYTES = 10_000_000;
    public const MAX_UNCOMPRESSED_BYTES = 8_000_000;
    public const MAX_FACTS = 200_000;
    /**
     * Structural-token ceiling enforced before `json_decode`. Each JSON token
     * (`{`, `[`, `,`, `:`) maps to roughly one PHP zval, so counting them caps
     * the decoded array count — and therefore memory — even when a highly
     * compressible payload (e.g. `[1,1,1,…]`) stays under the uncompressed byte
     * cap. Legitimate bundles are dominated by long string literals (paths,
     * names, sha256 hashes) and stay far below this bound.
     */
    public const MAX_STRUCTURAL_TOKENS = 2_000_000;

    /** @return array{manifest: array<string, mixed>, payload: array<string, mixed>, fact_count: int, checksum: string} */
    public function decodeAndValidate(string $compressed): array
    {
        if ($compressed === '' || strlen($compressed) > self::MAX_COMPRESSED_BYTES) {
            throw new InvalidArgumentException('Bundle is empty or exceeds the compressed byte limit.');
        }
        $json = @gzdecode($compressed, self::MAX_UNCOMPRESSED_BYTES);
        if (!is_string($json)) {
            throw new InvalidArgumentException('Bundle is not valid bounded gzip data.');
        }
        if (self::structuralTokenCount($json) > self::MAX_STRUCTURAL_TOKENS) {
            throw new InvalidArgumentException('Bundle structural token density exceeds the safe decode limit.');
        }
        $bundle = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($bundle) || array_is_list($bundle) || array_keys($bundle) !== ['manifest', 'payload']) {
            throw new InvalidArgumentException('Bundle root is invalid.');
        }
        $manifest = $this->object($bundle['manifest'], 'manifest');
        $payload = $this->object($bundle['payload'], 'payload');
        $this->knownKeys($manifest, ['format', 'version', 'redaction', 'checksum', 'uncompressed_bytes', 'fact_count', 'created_at'], 'manifest');
        $this->knownKeys($payload, ['project_name', 'scan', 'files', 'nodes', 'edges', 'classifications', 'boundaries', 'memberships', 'diagnostics'], 'payload');
        if (($manifest['format'] ?? null) !== self::FORMAT || ($manifest['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Bundle format or schema version is unsupported.');
        }
        if (!in_array($manifest['redaction'] ?? null, ['none', 'paths', 'strict'], true)) {
            throw new InvalidArgumentException('Bundle redaction mode is invalid.');
        }
        $payloadJson = self::encodeCanonical($payload);
        $expectedChecksum = 'sha256:' . hash('sha256', $payloadJson);
        if (($manifest['checksum'] ?? null) !== $expectedChecksum) {
            throw new InvalidArgumentException('Bundle checksum validation failed.');
        }
        if (($manifest['uncompressed_bytes'] ?? null) !== strlen($payloadJson)) {
            throw new InvalidArgumentException('Bundle declared byte size is invalid.');
        }
        $factCount = $this->validateTables($payload);
        if ($factCount > self::MAX_FACTS || $factCount !== ($manifest['fact_count'] ?? null)) {
            throw new InvalidArgumentException('Bundle fact count is invalid or exceeds limits.');
        }

        return ['manifest' => $manifest, 'payload' => $payload, 'fact_count' => $factCount, 'checksum' => substr($expectedChecksum, 7)];
    }

    /**
     * Upper bound on the number of PHP zvals `json_decode` would allocate,
     * computed without decoding. Every container and separator token begins a
     * new value, so their combined count dominates the eventual array size.
     */
    private static function structuralTokenCount(string $json): int
    {
        return substr_count($json, '{')
            + substr_count($json, '[')
            + substr_count($json, ',')
            + substr_count($json, ':');
    }

    /** @param array<string, mixed> $payload */
    private function validateTables(array $payload): int
    {
        $factCount = 0;
        foreach (['files', 'nodes', 'edges', 'classifications', 'boundaries', 'memberships', 'diagnostics'] as $table) {
            if (!is_array($payload[$table] ?? null) || !array_is_list($payload[$table])) {
                throw new InvalidArgumentException('Bundle table ' . $table . ' must be a list.');
            }
            $factCount += count($payload[$table]);
        }
        return $factCount;
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $name): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Bundle ' . $name . ' must be an object.');
        }
        return $value;
    }

    /** @param array<string, mixed> $value @param list<string> $allowed */
    private function knownKeys(array $value, array $allowed, string $scope): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Bundle ' . $scope . ' contains unknown keys: ' . implode(', ', $unknown) . '.');
        }
    }

    public static function encodeCanonical(mixed $value): string
    {
        return json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            $item = self::canonical($item);
        }
        return $value;
    }
}
