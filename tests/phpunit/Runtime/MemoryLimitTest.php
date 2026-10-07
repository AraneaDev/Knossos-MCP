<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use Knossos\Runtime\MemoryLimit;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MemoryLimitTest extends KnossosTestCase
{
    /** A host below the default is raised to the default. */
    public function testALowHostLimitIsRaisedToTheDefault(): void
    {
        $limit = MemoryLimit::resolve('128M', null);

        self::assertSame('1G', $limit->value);
        self::assertSame('default', $limit->source);
        self::assertNull($limit->rejected);
    }

    /** A host above the default keeps its own value. */
    public function testAHigherHostLimitIsKept(): void
    {
        $limit = MemoryLimit::resolve('2G', null);

        self::assertSame('2G', $limit->value);
        self::assertSame('php.ini', $limit->source);
    }

    /** Unlimited on the host stays unlimited. */
    public function testAnUnlimitedHostIsKept(): void
    {
        $limit = MemoryLimit::resolve('-1', null);

        self::assertSame('-1', $limit->value);
        self::assertSame('php.ini', $limit->source);
    }

    /** A host exactly at the default reports the default. */
    public function testAHostAtTheDefaultIsReportedAsDefault(): void
    {
        self::assertSame('default', MemoryLimit::resolve('1024M', null)->source);
    }

    /** An explicit environment value wins even over a higher host value. */
    public function testTheEnvironmentOverridesAHigherHostValue(): void
    {
        $limit = MemoryLimit::resolve('2G', '512M');

        self::assertSame('512M', $limit->value);
        self::assertSame('KNOSSOS_MEMORY_LIMIT', $limit->source);
    }

    /** The environment may ask for unlimited. */
    public function testTheEnvironmentMayRequestUnlimited(): void
    {
        $limit = MemoryLimit::resolve('128M', '-1');

        self::assertSame('-1', $limit->value);
        self::assertSame('KNOSSOS_MEMORY_LIMIT', $limit->source);
    }

    /** An invalid environment value falls back to the rule and is reported. */
    public function testAnInvalidEnvironmentValueFallsBackAndIsReported(): void
    {
        $limit = MemoryLimit::resolve('128M', 'lots');

        self::assertSame('1G', $limit->value);
        self::assertSame('default', $limit->source);
        self::assertSame('lots', $limit->rejected);
        self::assertSame('2G', MemoryLimit::resolve('2G', 'lots')->value);
    }

    /** An empty environment value counts as unset. */
    public function testAnEmptyEnvironmentValueIsUnset(): void
    {
        $limit = MemoryLimit::resolve('128M', '');

        self::assertSame('default', $limit->source);
        self::assertNull($limit->rejected);
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function sizes(): iterable
    {
        yield 'plain bytes' => ['1048576', 1048576];
        yield 'kilobytes' => ['512K', 512 * 1024];
        yield 'megabytes' => ['128M', 128 * 1024 ** 2];
        yield 'gigabytes lower case' => ['2g', 2 * 1024 ** 3];
        yield 'unlimited' => ['-1', -1];
        yield 'zero' => ['0', null];
        yield 'words' => ['lots', null];
        yield 'unknown suffix' => ['5T', null];
        yield 'negative with suffix' => ['-1M', null];
        yield 'empty' => ['', null];
    }

    /** Shorthand parsing covers K, M, G, plain bytes and -1. */
    #[DataProvider('sizes')]
    public function testBytesParsesShorthand(string $size, ?int $expected): void
    {
        self::assertSame($expected, MemoryLimit::bytes($size));
    }

    /** apply() sets the ini value, reports it through applied(), and a subprocess sees it. */
    public function testBinKnossosAppliesTheLimit(): void
    {
        $binary = self::repositoryRoot() . '/bin/knossos';
        $default = $this->doctorMemoryCheck([PHP_BINARY, '-d', 'memory_limit=128M', $binary, 'doctor', '--json'], null);
        self::assertSame('ok', $default['status']);
        self::assertSame('1G (default)', $default['detail']);

        $explicit = $this->doctorMemoryCheck([PHP_BINARY, '-d', 'memory_limit=128M', $binary, 'doctor', '--json'], '768M');
        self::assertSame('768M (KNOSSOS_MEMORY_LIMIT)', $explicit['detail']);

        $invalid = $this->doctorMemoryCheck([PHP_BINARY, '-d', 'memory_limit=128M', $binary, 'doctor', '--json'], 'lots');
        self::assertSame('error', $invalid['status']);
        self::assertStringContainsString('1G (default); KNOSSOS_MEMORY_LIMIT=lots is not a valid size', $invalid['detail']);
    }

    /**
     * Run the CLI and return its php.memory_limit check.
     *
     * @param non-empty-list<string> $command
     * @return array{name: string, status: string, detail: string}
     */
    private function doctorMemoryCheck(array $command, ?string $limit): array
    {
        $environment = array_merge(getenv(), ['KNOSSOS_DATA_DIR' => sys_get_temp_dir() . '/knossos-memory-' . uniqid('', true)]);
        unset($environment['KNOSSOS_MEMORY_LIMIT']);
        if ($limit !== null) {
            $environment['KNOSSOS_MEMORY_LIMIT'] = $limit;
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::repositoryRoot(), $environment);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($process);
        /** @var array{checks: list<array{name: string, status: string, detail: string}>} $report */
        $report = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        foreach ($report['checks'] as $check) {
            if ($check['name'] === 'php.memory_limit') {
                return $check;
            }
        }
        self::fail('doctor did not report php.memory_limit');
    }
}
