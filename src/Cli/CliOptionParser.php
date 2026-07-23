<?php

declare(strict_types=1);

namespace Knossos\Cli;

use InvalidArgumentException;

final class CliOptionParser
{
    /** @param list<string> $arguments @return array{0: list<string>, 1: array<string, list<string>>} */
    public function parse(array $arguments): array
    {
        $positionals = [];
        $options = [];
        $endOfOptions = false;
        foreach ($arguments as $argument) {
            if ($endOfOptions || !str_starts_with($argument, '--')) {
                $positionals[] = $argument;
                continue;
            }
            if ($argument === '--') {
                // Standard end-of-options marker: everything after it is a
                // positional argument, even if it starts with `--`.
                $endOfOptions = true;
                continue;
            }
            $parts = explode('=', substr($argument, 2), 2);
            $name = $parts[0];
            if ($name === '') {
                throw new InvalidArgumentException('Invalid empty option.');
            }
            $options[$name][] = $parts[1] ?? 'true';
        }
        return [$positionals, $options];
    }

    /**
     * Rejects any option name not present in the command's allowlist so typos
     * apply an explicit error rather than silently falling back to defaults.
     *
     * @param array<string, list<string>> $options
     * @param list<string> $allowed
     */
    public function validate(array $options, array $allowed): void
    {
        foreach (array_keys($options) as $name) {
            if (!in_array($name, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option: --%s', $name));
            }
        }
    }

    /**
     * Resolves a boolean flag, treating an explicit `--flag=false|0|no|off` as
     * disabled (last occurrence wins) rather than the mere presence of the key.
     *
     * @param array<string, list<string>> $options
     */
    public function flag(array $options, string $name): bool
    {
        if (!isset($options[$name]) || $options[$name] === []) {
            return false;
        }
        $value = strtolower((string) $options[$name][array_key_last($options[$name])]);

        return !in_array($value, ['false', '0', 'no', 'off'], true);
    }

    /** @param array<string, list<string>> $options */
    public function single(array $options, string $name): ?string
    {
        if (!isset($options[$name])) {
            return null;
        }
        if (count($options[$name]) !== 1 || $options[$name][0] === '') {
            throw new InvalidArgumentException(sprintf('--%s must have one non-empty value.', $name));
        }
        return $options[$name][0];
    }

    /** @param array<string, list<string>> $options */
    public function integer(array $options, string $name, int $default, int $minimum, int $maximum): int
    {
        $value = $this->single($options, $name);
        if ($value === null) {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($parsed) || $parsed < $minimum || $parsed > $maximum) {
            throw new InvalidArgumentException(sprintf('--%s must be between %d and %d.', $name, $minimum, $maximum));
        }
        return $parsed;
    }

    /** @param list<string> $values @return list<array<string, string>> */
    public function boundaries(array $values): array
    {
        $boundaries = [];
        foreach ($values as $value) {
            $parts = explode(':', $value, 3);
            if (count($parts) !== 3 || $parts[0] === '' || $parts[2] === '' || !in_array($parts[1], ['path', 'namespace'], true)) {
                throw new InvalidArgumentException('--boundary uses NAME:path:PREFIX or NAME:namespace:PREFIX.');
            }
            $boundaries[] = ['name' => $parts[0], $parts[1] . '_prefix' => $parts[2]];
        }
        return $boundaries;
    }
}
