<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Discovery\RootGuard;

/**
 * Reads a bounded window of scanned-project source at query time. The first
 * and only Query-side disk reader: every read is contained to the project
 * root via RootGuard, capped in size, and degrades to an 'unavailable'
 * status instead of throwing — evidence may lag the working tree.
 */
final readonly class SourceExcerptReader
{
    private const MAX_LINES = 40;
    private const MAX_FILE_BYTES = 2_000_000;

    /** @return array<string, mixed> */
    public function read(string $root, ?string $relativePath, ?int $startLine, ?int $endLine): array
    {
        if ($relativePath === null || $startLine === null) {
            return ['status' => 'unavailable', 'reason' => 'no_line_evidence'];
        }
        $realRoot = realpath($root);
        $resolved = $realRoot === false ? false : realpath($realRoot . '/' . $relativePath);
        if ($realRoot === false || $resolved === false || !RootGuard::contains($realRoot, $resolved)) {
            return ['status' => 'unavailable', 'reason' => 'outside_project_root_or_missing'];
        }
        if (!is_file($resolved) || (filesize($resolved) ?: PHP_INT_MAX) > self::MAX_FILE_BYTES) {
            return ['status' => 'unavailable', 'reason' => 'missing_or_oversized'];
        }
        $lines = @file($resolved);
        if ($lines === false) {
            return ['status' => 'unavailable', 'reason' => 'unreadable'];
        }
        $end = min($endLine ?? $startLine, $startLine + self::MAX_LINES - 1, count($lines));
        if ($end < $startLine) {
            return ['status' => 'unavailable', 'reason' => 'stale_line_evidence'];
        }
        return [
            'status' => 'included',
            'path' => $relativePath,
            'start_line' => $startLine,
            'end_line' => $end,
            'code' => implode('', array_slice($lines, $startLine - 1, $end - $startLine + 1)),
        ];
    }
}
