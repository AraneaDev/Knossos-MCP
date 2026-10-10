<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use InvalidArgumentException;

/**
 * The argument coercers every tool handler reads its arguments through.
 *
 * Each one checks a single argument's type and bounds and returns it in the
 * form the services match against, or throws InvalidArgumentException with the
 * message the caller sees. Nothing is coerced from the wrong type: a truthy
 * string is not a boolean and a bare string is not a list, because a silently
 * reinterpreted argument reads to a caller as one that had no effect.
 */
final class ToolArguments
{
    private function __construct() {}

    /**
     * Reject unknown or missing keys in a nested object argument. The top-level
     * argument check lives in {@see ToolService}, driven by ToolCatalog; this
     * is for shapes the catalog does not describe, such as a boundary entry.
     *
     * @param array<string, mixed> $arguments @param list<string> $required @param list<string> $optional
     */
    private static function keys(array $arguments, array $required, array $optional): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $arguments)) {
                throw new InvalidArgumentException(sprintf('Missing required argument: %s', $key));
            }
        }
        $unknown = array_diff(array_keys($arguments), [...$required, ...$optional]);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('Unknown argument: %s', reset($unknown)));
        }
    }

    /**
     * A required string argument, rejecting an empty value rather than treating
     * it as absent, and returning it trimmed — surrounding whitespace matched
     * no id in the database and surfaced as "not found" rather than "invalid".
     *
     * @param array<string, mixed> $arguments
     */
    public static function string(array $arguments, string $key): string
    {
        $value = self::normalized($arguments[$key] ?? null);
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty string.', $key));
        }
        return $value;
    }

    /**
     * A string argument with an advertised maxLength, counted in characters as
     * JSON Schema counts them (the limit used to be checked in bytes, or not at
     * all). Absent, it is $default when one is given. Trimmed like
     * {@see string()} unless $trim is false, for a value whose whitespace is
     * content.
     *
     * @param array<string, mixed> $arguments
     */
    public static function text(array $arguments, string $key, int $maxLength, bool $allowEmpty = false, ?string $default = null, bool $trim = true): string
    {
        // array_key_exists, not ??: an explicit null is a value of the wrong
        // type, not an absent key that takes the default.
        $value = array_key_exists($key, $arguments) ? $arguments[$key] : $default;
        if (!is_string($value) || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(sprintf('%s must be a string of at most %d characters.', $key, $maxLength));
        }
        $value = $trim ? trim($value) : $value;
        if (!$allowEmpty && $value === '') {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty string.', $key));
        }

        return $value;
    }

    /**
     * The one place an incoming string argument is normalised.
     *
     * Every helper here routes through this rather than spelling out its own
     * trim(): surrounding whitespace is invisible in a JSON payload, and every
     * value these helpers produce is then matched literally — an id looked up
     * in the database, an enum value, a path prefix — so ' proj_1' matched
     * nothing and surfaced as "not found" rather than "invalid". The rule had
     * been written out at four separate sites, with nothing structural stopping
     * a fifth helper from omitting it.
     *
     * A non-string collapses to the empty string so each caller rejects it with
     * its own message, which is what they did with the type check inline.
     */
    public static function normalized(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * An integer argument within its declared bounds, rejecting anything outside them.
     *
     * @param array<string, mixed> $arguments
     */
    public static function integer(array $arguments, string $key, int $default, int $minimum, int $maximum): int
    {
        $value = $arguments[$key] ?? $default;
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf('%s must be an integer between %d and %d.', $key, $minimum, $maximum));
        }
        return $value;
    }

    /**
     * A boolean argument, rejecting a truthy string rather than coercing it.
     *
     * @param array<string, mixed> $arguments
     */
    public static function boolean(array $arguments, string $key, bool $default): bool
    {
        $value = $arguments[$key] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a boolean.', $key));
        }
        return $value;
    }

    /**
     * A list-of-strings argument, rejecting a bare string so a caller cannot
     * pass one by mistake. Entries are trimmed for the same reason {@see string()}
     * trims: every list here holds enum values, ids, or paths matched literally,
     * so a padded entry silently matched nothing instead of being rejected.
     *
     * @param array<string, mixed> $arguments @return list<string>
     */
    public static function strings(array $arguments, string $key, int $maximum = 20): array
    {
        $value = $arguments[$key] ?? [];
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new InvalidArgumentException(sprintf('%s must be a list of at most %d strings.', $key, $maximum));
        }
        $trimmed = [];
        foreach ($value as $item) {
            $entry = self::normalized($item);
            if ($entry === '') {
                throw new InvalidArgumentException(sprintf('%s must contain non-empty strings.', $key));
            }
            $trimmed[] = $entry;
        }
        return $trimmed;
    }

    /**
     * Boundary definitions from the scan arguments, validated into the shape the
     * planner expects. The validated strings are written back rather than
     * discarded: BoundaryInference matches a prefix literally, so a padded
     * path_prefix would define a boundary that matches nothing.
     *
     * @param array<string, mixed> $arguments @return list<array<string, mixed>>
     */
    public static function boundariesArgument(array $arguments): array
    {
        $values = $arguments['boundaries'] ?? [];
        if (!is_array($values) || !array_is_list($values) || count($values) > 50) {
            throw new InvalidArgumentException('boundaries must be a list of at most 50 objects.');
        }
        $normalized = [];
        foreach ($values as $value) {
            if (!is_array($value) || array_is_list($value)) {
                throw new InvalidArgumentException('Each boundary must be an object.');
            }
            self::keys($value, ['name'], ['path_prefix', 'namespace_prefix']);
            $value['name'] = self::string($value, 'name');
            $matchers = (int) array_key_exists('path_prefix', $value) + (int) array_key_exists('namespace_prefix', $value);
            if ($matchers !== 1) {
                throw new InvalidArgumentException('Each boundary requires exactly one matcher.');
            }
            $matcher = array_key_exists('path_prefix', $value) ? 'path_prefix' : 'namespace_prefix';
            $value[$matcher] = self::string($value, $matcher);
            $normalized[] = $value;
        }
        return $normalized;
    }
}
