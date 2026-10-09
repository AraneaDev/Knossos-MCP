<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Targeted reads of TOML manifests (`pyproject.toml`, `Cargo.toml`).
 *
 * No TOML parser: each method scopes a regex to one table, which is all the
 * manifest readers ask of a file and keeps them self-contained.
 */
final class Toml
{
    /**
     * A string key from a `[header]` table's scope, or null.
     */
    public static function tableString(string $contents, string $header): ?string
    {
        if (preg_match('/^[ \t]*' . preg_quote($header, '/') . '[ \t]*(?:#.*)?\r?$/m', $contents, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $rest = substr($contents, $m[0][1] + strlen($m[0][0]));
        $end = preg_match('/^[ \t]*\[/m', $rest, $next, PREG_OFFSET_CAPTURE) === 1
            ? $next[0][1]
            : strlen($rest);
        $table = substr($rest, 0, $end);
        if (preg_match('/^\s*name\s*=\s*["\']([^"\']+)["\']/m', $table, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract [[bin]]/[[test]]/[[example]] array-of-table blocks from a TOML.
     *
     * @return list<array{name: string, path: string}>
     */
    public static function arrayTables(string $contents, string $header): array
    {
        $found = [];
        $offset = 0;
        $pattern = '/^[ \t]*' . preg_quote($header, '/') . '[ \t]*(?:#.*)?\r?$/m';
        while (preg_match($pattern, $contents, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1] + strlen($m[0][0]);
            $next = preg_match('/^[ \t]*\[/m', $contents, $n, PREG_OFFSET_CAPTURE, $start) === 1
                ? $n[0][1]
                : strlen($contents);
            $block = substr($contents, $start, $next - $start);
            $name = $path = '';
            if (preg_match('/^\s*name\s*=\s*["\']([^"\']+)["\']/m', $block, $nm) === 1) {
                $name = $nm[1];
            }
            if (preg_match('/^\s*path\s*=\s*["\']([^"\']+)["\']/m', $block, $pm) === 1) {
                $path = $pm[1];
            }
            $found[] = ['name' => $name, 'path' => $path];
            $offset = $next;
        }

        return $found;
    }

    /**
     * Crate names an inline dependency table renames with a `package` key.
     *
     * `web = { package = "actix-web", version = "4" }` depends on actix-web
     * under the local name `web`. Both are recorded: the alias is what source
     * code writes in a `use`, and the real crate name is what framework
     * detection in ScanPlanner matches on, so keeping only the alias hides the
     * dependency and silently gates worker enrichment off.
     *
     * @return list<string>
     */
    public static function inlinePackageNames(string $block): array
    {
        $names = [];
        $pattern = '/^[ \t]*["\']?[A-Za-z0-9_.-]+["\']?[ \t]*=[ \t]*\{[^}]*\bpackage[ \t]*=[ \t]*["\']([^"\']+)["\']/m';
        if (preg_match_all($pattern, $block, $m) > 0) {
            foreach ($m[1] as $name) {
                $names[] = strtolower($name);
            }
        }

        return $names;
    }

    /**
     * Every table header in a TOML document, without its brackets.
     *
     * @return list<string>
     */
    public static function headers(string $contents): array
    {
        if (preg_match_all('/^[ \t]*\[([^\]]+)\][ \t]*(?:#.*)?\r?$/m', $contents, $m) < 1) {
            return [];
        }

        return array_values(array_map(trim(...), $m[1]));
    }

    /**
     * The lowercased `key = ...` names at the top level of a table block.
     *
     * @return list<string>
     */
    public static function tableKeys(string $block): array
    {
        $keys = [];
        if (preg_match_all('/^[ \t]*["\']?([A-Za-z0-9_.-]+)["\']?[ \t]*=/m', $block, $m) > 0) {
            foreach ($m[1] as $key) {
                $keys[] = strtolower($key);
            }
        }

        return $keys;
    }

    /**
     * The raw text after a `[header]` line until the next table header.
     *
     * @return non-empty-string|null
     */
    public static function tableBlock(string $contents, string $header): ?string
    {
        $pattern = '/^[ \t]*' . preg_quote($header, '/') . '[ \t]*(?:#.*)?\r?$/m';
        if (preg_match($pattern, $contents, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $end = preg_match('/^[ \t]*\[/m', $contents, $n, PREG_OFFSET_CAPTURE, $start) === 1
            ? $n[0][1]
            : strlen($contents);

        return substr($contents, $start, $end - $start) . "\n";
    }

    /**
     * The quoted strings inside `key = [...]` lists in a table block.
     *
     * Brackets are counted outside strings only, so a dependency like
     * `fastapi[all]` does not end the list early. A null `$keys` accepts
     * every list key in the block (needed for optional-dependency groups
     * and script tables, whose group names are arbitrary).
     *
     * @param list<string>|null $keys
     * @return list<string>
     */
    public static function stringLists(string $block, ?array $keys): array
    {
        $result = [];
        $offset = 0;
        $length = strlen($block);
        while ($offset < $length) {
            $pattern = '/^[ \t]*([A-Za-z0-9_.-]+)[ \t]*=[ \t]*\[/m';
            if (preg_match($pattern, $block, $m, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                break;
            }
            $key = $m[1][0];
            if ($keys !== null && !in_array($key, $keys, true)) {
                $offset = $m[0][1] + strlen($m[0][0]);
                continue;
            }
            $open = $m[0][1] + strlen($m[0][0]) - 1; // position of '['
            $depth = 0;
            $inString = false;
            $i = $open;
            for (; $i < $length; ++$i) {
                $c = $block[$i];
                if ($c === '"' || $c === "'") {
                    if ($i === 0 || $block[$i - 1] !== '\\') {
                        $inString = !$inString;
                    }
                } elseif (!$inString) {
                    if ($c === '[') {
                        ++$depth;
                    } elseif ($c === ']') {
                        --$depth;
                        if ($depth === 0) {
                            ++$i; // stop just past the closing bracket
                            break;
                        }
                    }
                }
            }
            $list = substr($block, $open + 1, max(0, $i - $open - 2));
            if (preg_match_all('/["\']([^"\']+)["\']/', $list, $pms) > 0) {
                array_push($result, ...$pms[1]);
            }
            $offset = $i;
        }

        return $result;
    }
}
