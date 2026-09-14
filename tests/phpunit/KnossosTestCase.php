<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

abstract class KnossosTestCase extends TestCase
{
    use Support\AmbientRoots;
    use Support\Fixtures;
    use Support\Processes;
    use Support\TempTrees;
    use Support\WorkerClients;

    /**
     * Project repository root (where composer.json lives). Ported helpers were
     * originally written as dirname(__DIR__) from tests/run.php (in tests/);
     * from tests/phpunit/ the equivalent depth is two levels.
     */
    protected static function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Run $operation with error_log() pointed at a temporary file and return
     * what it logged, so a deliberately provoked failure does not print into
     * the suite's output. The previous setting is restored even when
     * $operation throws, so nothing leaks into a later test.
     *
     * @param callable(): void $operation
     */
    protected function errorLogOf(callable $operation): string
    {
        $capture = tempnam(sys_get_temp_dir(), 'knossos-errorlog-');
        if ($capture === false) {
            throw new RuntimeException('Unable to allocate an error-log capture file.');
        }
        $previous = ini_get('error_log');
        ini_set('error_log', $capture);
        try {
            $operation();
        } finally {
            $previous === false ? ini_restore('error_log') : ini_set('error_log', $previous);
            $logged = (string) @file_get_contents($capture);
            @unlink($capture);
        }

        return $logged;
    }

    /**
     * Mirrors the original assertThrows(): the callback must throw an instance
     * of $expected. The second argument is a throwable class name, not a
     * message substring — preserving the tests/run.php semantics verbatim so
     * the 121 ported tests need no semantic change.
     *
     * @param class-string<Throwable> $expected
     */
    protected static function assertThrowsWith(callable $callback, string $expected): void
    {
        $error = self::captureThrown($callback, $expected);
        // Explicit assertion so PHPUnit counts it (captureThrown returns without
        // asserting on the success path, which would otherwise be flagged risky).
        // Uses assertInstanceOf to preserve the original instanceof semantics
        // (subclass-accepting), not assertSame which would require exact match.
        self::assertInstanceOf($expected, $error);
    }

    /**
     * @param class-string<Throwable> $expected
     */
    protected static function captureThrown(callable $callback, string $expected): Throwable
    {
        try {
            $callback();
        } catch (Throwable $error) {
            if ($error instanceof $expected) {
                return $error;
            }

            self::fail(sprintf('Expected %s, got %s.', $expected, $error::class));
        }

        self::fail(sprintf('Expected %s to be thrown.', $expected));
    }

    protected static function assertArrayContainsValue(mixed $needle, array $haystack): void
    {
        self::assertContains($needle, $haystack);
    }

    /**
     * Recursively ksorts associative arrays so JSON comparisons are
     * order-independent. Lists (array_is_list) keep their order, matching the
     * original canonicalJsonValue body from tests/run.php verbatim.
     */
    protected static function canonicalJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalJsonValue(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            $item = self::canonicalJsonValue($item);
        }

        return $value;
    }
}
