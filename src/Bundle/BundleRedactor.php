<?php

declare(strict_types=1);

namespace Knossos\Bundle;

use SensitiveParameter;

/**
 * Rewrites a bundle's tables so no discovered path survives in any column.
 *
 * Every column that can spell a path goes through one {@see RedactionMap}:
 * file paths, names, owner keys, messages, boundary names, and every string
 * and key inside attributes and matchers. Every id and every reference to one
 * is re-keyed with the same salt, because a stable id is an unsalted hash of
 * guessable inputs such as the path; the importer remaps ids anyway, so
 * nothing downstream depends on their values. Strict mode also empties
 * attributes, drops messages and salts content hashes, since the hash of a
 * well-known file names that file.
 */
final readonly class BundleRedactor
{
    /** Columns holding an id or a reference to one. */
    private const ID_COLUMNS = ['id', 'parent_id', 'file_id', 'source_id', 'target_id', 'node_id', 'boundary_id'];

    /** Columns of free text that can contain a path. */
    private const TEXT_COLUMNS = ['relative_path', 'canonical_name', 'owner_key', 'name', 'message'];

    /** Columns holding a JSON document whose strings can contain a path. */
    private const JSON_COLUMNS = ['attributes_json', 'matcher_json'];

    /** Bind the map that every column of one export is redacted through. */
    private function __construct(private RedactionMap $map, private bool $strict) {}

    /**
     * Redact every table of one export with a salt used for nothing else.
     *
     * @param array<string, list<array<string, mixed>>> $tables @return array<string, list<array<string, mixed>>>
     */
    public static function redact(array $tables, bool $strict, #[SensitiveParameter] string $salt): array
    {
        $redactor = new self(RedactionMap::fromPayload($tables, $salt), $strict);
        foreach ($tables as $table => $rows) {
            $rows = array_map($redactor->row(...), $rows);
            usort($rows, static fn(array $left, array $right): int => strcmp(self::sortKey($left), self::sortKey($right)));
            $tables[$table] = $rows;
        }
        return $tables;
    }

    /**
     * Where a row sorts once its ids are re-keyed: by id, or, for a
     * membership, by boundary then node, the order the export reads them in.
     *
     * @param array<string, mixed> $row
     */
    private static function sortKey(array $row): string
    {
        return (string) ($row['id'] ?? $row['boundary_id'] . "\0" . $row['node_id']);
    }

    /**
     * One row with every id re-keyed and every path-bearing column redacted.
     *
     * @param array<string, mixed> $row @return array<string, mixed>
     */
    private function row(array $row): array
    {
        $redacted = $row;
        foreach ($row as $column => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (in_array($column, self::ID_COLUMNS, true)) {
                $redacted[$column] = $this->map->id($value);
            } elseif (in_array($column, self::TEXT_COLUMNS, true)) {
                $redacted[$column] = $this->strict && $column === 'message' ? '[redacted]' : $this->map->scrub($value);
            } elseif (in_array($column, self::JSON_COLUMNS, true)) {
                $redacted[$column] = $this->strict && $column === 'attributes_json' ? '{}' : $this->json($value);
            } elseif ($column === 'display_name') {
                $redacted[$column] = $this->displayName($value, $row['canonical_name'] ?? null);
            } elseif ($column === 'content_hash' && $this->strict) {
                $redacted[$column] = $this->map->hashContent($value);
            }
        }
        return $redacted;
    }

    /**
     * A display name, which for a module is the last segment of its path or
     * its dotted id (`ledger.ts`, `secret`): that segment is replaced by the
     * last segment of the canonical name's token.
     */
    private function displayName(string $display, mixed $canonical): string
    {
        $scrubbed = $this->map->scrub($display);
        $token = is_string($canonical) ? $this->map->token($canonical) : null;
        if ($token !== null && $scrubbed === $display && (str_ends_with($canonical, '/' . $display) || str_ends_with($canonical, '.' . $display))) {
            return basename($token);
        }
        return $scrubbed;
    }

    /**
     * A JSON document with every string and key redacted, re-encoded only when something changed.
     *
     * A document that names no path keeps its exact bytes, so an attribute
     * the importer already accepts is never re-shaped on the way out.
     */
    private function json(string $json): string
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $scrubbed = $this->scrubValue($decoded);
        return $scrubbed === $decoded ? $json : GraphBundleDecoder::encodeCanonical($scrubbed);
    }

    /** Every string in a decoded JSON value, keys included, redacted. */
    private function scrubValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->map->scrub($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $scrubbed = [];
        foreach ($value as $key => $item) {
            $scrubbed[is_string($key) ? $this->map->scrub($key) : $key] = $this->scrubValue($item);
        }
        return $scrubbed;
    }
}
