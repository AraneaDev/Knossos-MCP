<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use JsonException;

final class JsonConfig
{
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function decode(string $contents, bool $allowComments = false): array
    {
        if ($allowComments) {
            $contents = self::stripComments($contents);
            $contents = self::stripTrailingCommas($contents);
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new DiscoveryException('Invalid JSON configuration: ' . $error->getMessage(), previous: $error);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new DiscoveryException('Configuration root must be a JSON object.');
        }

        return $decoded;
    }

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
