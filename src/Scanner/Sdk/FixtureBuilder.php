<?php

declare(strict_types=1);

namespace Knossos\Scanner\Sdk;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\Origin;

final class FixtureBuilder
{
    private function __construct() {}

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    public static function node(
        string $id,
        string $kind,
        string $canonicalName,
        string $displayName,
        string $path,
        int $startLine = 1,
        ?int $endLine = null,
        Origin $origin = Origin::Ast,
        Confidence $confidence = Confidence::Certain,
        array $attributes = [],
    ): array {
        $evidence = new Evidence($path, $startLine, $endLine ?? $startLine);
        return [
            'local_id' => $id,
            'kind' => $kind,
            'canonical_name' => $canonicalName,
            'display_name' => $displayName,
            'origin' => $origin->value,
            'confidence' => $confidence->value,
            'evidence' => $evidence->jsonSerialize(),
            'attributes' => $attributes,
        ];
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    public static function edge(
        string $kind,
        string $source,
        string $target,
        string $path,
        int $startLine = 1,
        ?int $endLine = null,
        Origin $origin = Origin::Ast,
        Confidence $confidence = Confidence::Certain,
        array $attributes = [],
    ): array {
        $evidence = new Evidence($path, $startLine, $endLine ?? $startLine);
        return [
            'kind' => $kind,
            'source' => $source,
            'target' => $target,
            'origin' => $origin->value,
            'confidence' => $confidence->value,
            'evidence' => $evidence->jsonSerialize(),
            'attributes' => $attributes,
        ];
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $edges
     * @param list<array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    public static function contribution(string $ownerKey, array $nodes = [], array $edges = [], array $diagnostics = []): array
    {
        return ['owner_key' => $ownerKey, 'nodes' => $nodes, 'edges' => $edges, 'diagnostics' => $diagnostics];
    }
}
