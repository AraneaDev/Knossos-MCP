<?php

declare(strict_types=1);

// Global assertion helper functions that mirror the originals from tests/run.php.
// These exist so unqualified calls (e.g. inside anonymous classes, where self::
// refers to the anonymous class, not the test class) resolve via PHP's function
// namespace fallback, exactly as they did in the un-namespaced tests/run.php.
//
// The function_exists guards make this file safe to load multiple times — both
// the PHPUnit bootstrap and tests/run.php's own require of vendor/autoload.php
// may load it, and tests/run.php defines its own versions. The guards ensure
// no "Cannot redeclare" fatal errors regardless of load order.

use PHPUnit\Framework\Assert;

if (!function_exists('assertSame')) {
    function assertSame(mixed $expected, mixed $actual): void
    {
        Assert::assertSame($expected, $actual);
    }
}

if (!function_exists('assertNotSame')) {
    function assertNotSame(mixed $unexpected, mixed $actual): void
    {
        Assert::assertNotSame($unexpected, $actual);
    }
}

if (!function_exists('assertContains')) {
    function assertContains(string $needle, string $haystack): void
    {
        Assert::assertStringContainsString($needle, $haystack);
    }
}

if (!function_exists('assertArrayContains')) {
    function assertArrayContains(mixed $needle, array $haystack): void
    {
        Assert::assertContains($needle, $haystack);
    }
}

if (!function_exists('assertThrows')) {
    /** @param class-string<\Throwable> $expected */
    function assertThrows(callable $callback, string $expected): void
    {
        $error = captureThrows($callback, $expected);
        Assert::assertInstanceOf($expected, $error);
    }
}

if (!function_exists('captureThrows')) {
    /** @param class-string<\Throwable> $expected */
    function captureThrows(callable $callback, string $expected): \Throwable
    {
        try {
            $callback();
        } catch (\Throwable $error) {
            if ($error instanceof $expected) {
                return $error;
            }
            Assert::fail(sprintf('Expected %s, got %s.', $expected, $error::class));
        }
        Assert::fail(sprintf('Expected %s to be thrown.', $expected));
    }
}

if (!function_exists('canonicalJsonValue')) {
    function canonicalJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(canonicalJsonValue(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            $item = canonicalJsonValue($item);
        }
        return $value;
    }
}
