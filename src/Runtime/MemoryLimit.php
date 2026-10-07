<?php

declare(strict_types=1);

namespace Knossos\Runtime;

/**
 * The memory limit the core runs under, and where that value came from.
 *
 * The core does not inherit whatever the host's php.ini happens to say. It
 * runs at {@see self::DEFAULT_LIMIT} unless the host already allows more, and
 * an explicit `KNOSSOS_MEMORY_LIMIT` always wins, in either direction.
 */
final class MemoryLimit
{
    public const DEFAULT_LIMIT = '1G';
    public const ENVIRONMENT_VARIABLE = 'KNOSSOS_MEMORY_LIMIT';
    public const SOURCE_DEFAULT = 'default';
    public const SOURCE_ENVIRONMENT = 'KNOSSOS_MEMORY_LIMIT';
    public const SOURCE_INI = 'php.ini';

    private static ?self $applied = null;

    /**
     * @param string $value the limit in PHP shorthand, or `-1` for unlimited
     * @param string $source one of the SOURCE_* constants
     * @param ?string $rejected the environment value that was not a valid size, when there was one
     * @param ?string $failure why PHP refused the limit that was asked for, when it did
     */
    public function __construct(
        public readonly string $value,
        public readonly string $source,
        public readonly ?string $rejected = null,
        public readonly ?string $failure = null,
    ) {}

    /**
     * Decide the effective limit from the host's current value and the environment's.
     *
     * A valid environment value is used as given. Otherwise the result is the
     * larger of the host's value and the default, where `-1` is the largest of
     * all. An invalid environment value falls back to that rule and is kept in
     * {@see self::$rejected} so it can be reported.
     */
    public static function resolve(string $currentIni, ?string $environment): self
    {
        $rejected = null;
        if ($environment !== null && trim($environment) !== '') {
            $requested = trim($environment);
            if (self::bytes($requested) !== null) {
                return new self($requested, self::SOURCE_ENVIRONMENT);
            }
            $rejected = $requested;
        }
        $current = self::bytes($currentIni);
        $default = self::bytes(self::DEFAULT_LIMIT);
        if ($current !== null && ($current === -1 || $current > $default)) {
            return new self(trim($currentIni), self::SOURCE_INI, $rejected);
        }

        return new self(self::DEFAULT_LIMIT, self::SOURCE_DEFAULT, $rejected);
    }

    /**
     * Parse a PHP size shorthand into bytes: plain digits, a K, M or G suffix, or `-1`.
     *
     * @return ?int bytes, `-1` for unlimited, or null when the text is not a size
     */
    public static function bytes(string $size): ?int
    {
        if (preg_match('/^(-1|\d+)([KMG]?)$/i', trim($size), $match) !== 1) {
            return null;
        }
        if ($match[1] === '-1') {
            return $match[2] === '' ? -1 : null;
        }
        $multiplier = ['' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3][strtoupper($match[2])];
        // Compared as text first: digits beyond PHP_INT_MAX would cast to a float, and so would the product.
        $digits = ltrim($match[1], '0');
        $max = (string) PHP_INT_MAX;
        $fitsInt = strlen($digits) < strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) <= 0);
        if ($digits === '' || !$fitsInt || (int) $digits > intdiv(PHP_INT_MAX, $multiplier)) {
            return null;
        }

        return (int) $digits * $multiplier;
    }

    /**
     * Resolve the limit from this process's ini value and environment, set it, and remember the outcome.
     *
     * PHP refuses a limit below what the process already uses, and says so with a warning on stderr.
     * That warning is suppressed here and the refusal is kept in {@see self::$failure} instead, with the
     * value PHP really has, so `doctor` can report it and no stdio transport is polluted.
     *
     * @param ?callable(string): (string|false) $setIni replaces `ini_set('memory_limit', ...)`; for tests
     */
    public static function apply(?callable $setIni = null): self
    {
        $environment = getenv(self::ENVIRONMENT_VARIABLE);
        $limit = self::resolve((string) ini_get('memory_limit'), $environment === false ? null : $environment);
        $setIni ??= static fn(string $value): string|false => @ini_set('memory_limit', $value);
        if ($setIni($limit->value) === false) {
            $limit = new self(
                (string) ini_get('memory_limit'),
                self::SOURCE_INI,
                $limit->rejected,
                sprintf('PHP refused a memory limit of %s', $limit->value),
            );
        }

        return self::$applied = $limit;
    }

    /** What {@see self::apply()} decided, or the host's own value when it was never called. */
    public static function applied(): self
    {
        return self::$applied ?? new self((string) ini_get('memory_limit'), self::SOURCE_INI);
    }
}
