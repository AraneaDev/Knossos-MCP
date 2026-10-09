<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use JsonException;

/**
 * Reads the project's JSON configuration, tolerating JSONC.
 *
 * Comments and trailing commas are accepted because `knossos.jsonc` is
 * hand-edited, and rejecting a trailing comma would be a hostile way to report a
 * configuration problem.
 */
final class JsonConfig
{
    private function __construct() {}

    /** The UTF-8 byte order mark some editors write at the start of a file. */
    private const BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    /**
     * Decode JSON, optionally tolerating comments and trailing commas for JSONC.
     *
     * One leading UTF-8 byte order mark is skipped, since json_decode rejects
     * it and editors on some platforms write it. The root must be an object:
     * that is decided from the first significant byte rather than from the
     * decoded value, because PHP decodes `{}` and `[]` to the same empty array.
     *
     * @return array<string, mixed>
     */
    public static function decode(string $contents, bool $allowComments = false): array
    {
        if (str_starts_with($contents, self::BYTE_ORDER_MARK)) {
            $contents = substr($contents, strlen(self::BYTE_ORDER_MARK));
        }
        if ($allowComments) {
            $contents = self::stripComments($contents);
            $contents = self::stripTrailingCommas($contents);
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new DiscoveryException('Invalid JSON configuration: ' . $error->getMessage(), previous: $error);
        }

        if (!is_array($decoded) || !str_starts_with(ltrim($contents), '{')) {
            throw new DiscoveryException('Configuration root must be a JSON object.');
        }

        return $decoded;
    }

    /** Remove comments without disturbing string contents. */

    private static function stripComments(string $input): string
    {
        $output = '';
        $length = strlen($input);
        $inString = false;
        $escaped = false;

        for ($index = 0; $index < $length; ++$index) {
            $character = $input[$index];
            if ($inString) {
                $output .= $character;
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($character === '"') {
                $inString = true;
                $output .= $character;
                continue;
            }

            $next = $index + 1 < $length ? $input[$index + 1] : '';
            if ($character === '/' && $next === '/') {
                $index += 2;
                while ($index < $length && $input[$index] !== "\n") {
                    ++$index;
                }
                $output .= "\n";
                continue;
            }

            if ($character === '/' && $next === '*') {
                $index += 2;
                while ($index + 1 < $length && !($input[$index] === '*' && $input[$index + 1] === '/')) {
                    $output .= $input[$index] === "\n" ? "\n" : ' ';
                    ++$index;
                }
                ++$index;
                continue;
            }

            $output .= $character;
        }

        return $output;
    }
    /** Remove trailing commas, which JSON rejects but hand-edited files routinely contain. */

    private static function stripTrailingCommas(string $input): string
    {
        $output = '';
        $length = strlen($input);
        $inString = false;
        $escaped = false;

        for ($index = 0; $index < $length; ++$index) {
            $character = $input[$index];
            if ($inString) {
                $output .= $character;
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($character === '"') {
                $inString = true;
                $output .= $character;
                continue;
            }

            if ($character === ',') {
                $lookahead = $index + 1;
                while ($lookahead < $length && ctype_space($input[$lookahead])) {
                    ++$lookahead;
                }
                if ($lookahead < $length && ($input[$lookahead] === '}' || $input[$lookahead] === ']')) {
                    continue;
                }
            }

            $output .= $character;
        }

        return $output;
    }
}
