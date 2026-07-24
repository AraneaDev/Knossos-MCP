<?php

declare(strict_types=1);

namespace Knossos\Mcp;

/**
 * Shared plumbing for compact-verbosity legend compressors (BoundaryLegend,
 * ComponentLegend): these are static-only utility classes with a private
 * constructor, and compress() always does the same thing -- walk the data
 * tree once, collecting hoisted entries into a legend as it goes. Extracted
 * because the two classes previously duplicated this boilerplate verbatim;
 * the class-specific hoisting logic stays in each class's own walk().
 */
trait LegendCompression
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function compress(array $data): array
    {
        $legend = [];
        $compressed = self::walk($data, $legend);
        return [$compressed, $legend];
    }
}
