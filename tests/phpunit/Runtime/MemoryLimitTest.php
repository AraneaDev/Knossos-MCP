<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use Knossos\Runtime\DoctorService;
use Knossos\Runtime\MemoryLimit;
use Knossos\Store\SqliteConnection;
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
        yield 'zero gigabytes' => ['0G', null];
        yield 'words' => ['lots', null];
        yield 'unknown suffix' => ['5T', null];
        yield 'negative with suffix' => ['-1M', null];
        yield 'empty' => ['', null];
        yield 'surrounding spaces' => [' 512M ', 512 * 1024 ** 2];
        yield 'trailing newline' => ["512M\n", 512 * 1024 ** 2];
        yield 'overflowing gigabytes' => ['9999999999G', null];
        yield 'overflow at the boundary' => ['8589934592G', null];
        yield 'overflowing plain digits' => ['99999999999999999999', null];
    }

    /** Shorthand parsing covers K, M, G, plain bytes and -1, and rejects anything that does not fit an int. */
    #[DataProvider('sizes')]
    public function testBytesParsesShorthand(string $size, ?int $expected): void
    {
        self::assertSame($expected, MemoryLimit::bytes($size));
    }

    /** An overflowing environment value is an invalid one, not a crash. */
    public function testAnOverflowingEnvironmentValueIsRejected(): void
    {
        $limit = MemoryLimit::resolve('128M', '9999999999G');

        self::assertSame('1G', $limit->value);
        self::assertSame('9999999999G', $limit->rejected);
    }

    /** An unparseable host value is replaced by the default rather than kept. */
    public function testAnUnparseableHostValueFallsBackToTheDefault(): void
    {
        self::assertSame('default', MemoryLimit::resolve('9999999999G', null)->source);
    }

    /** apply() sets the ini value from the environment and applied() reports the decision. */
    public function testApplySetsTheLimitAndRemembersIt(): void
    {
        $this->withRestoredState(function (): void {
            putenv('KNOSSOS_MEMORY_LIMIT=2G');
            $limit = MemoryLimit::apply();

            self::assertSame('2G', ini_get('memory_limit'));
            self::assertSame($limit, MemoryLimit::applied());
            self::assertSame('KNOSSOS_MEMORY_LIMIT', $limit->source);
        });
    }

    /** A refused ini_set keeps the value PHP really has and records why. */
    public function testARefusedLimitIsReportedWithTheValueInForce(): void
    {
        $this->withRestoredState(function (): void {
            putenv('KNOSSOS_MEMORY_LIMIT=2G');
            $limit = MemoryLimit::apply(static fn(string $value): bool => false);

            self::assertSame((string) ini_get('memory_limit'), $limit->value);
            self::assertSame('php.ini', $limit->source);
            self::assertNotNull($limit->failure);
            self::assertStringContainsString('2G', $limit->failure);
        });
    }

    /** A limit below current usage is refused by PHP; that must leave stderr empty and the failure recorded. */
    public function testALimitBelowCurrentUsageDoesNotWarn(): void
    {
        [$stdout, $stderr] = $this->runPhp(
            ['-d', 'memory_limit=128M', '-r', 'require ' . var_export(self::repositoryRoot() . '/vendor/autoload.php', true)
                . '; $l = Knossos\\Runtime\\MemoryLimit::apply(); echo $l->source, "|", $l->failure;'],
            ['KNOSSOS_MEMORY_LIMIT' => '1K'],
        );

        self::assertSame('', $stderr);
        self::assertSame('php.ini|PHP refused a memory limit of 1K', $stdout);
    }

    /** The HTTP router raises a low host limit before it does anything else, as `php -S` runs it per request. */
    public function testHttpRouterRaisesTheLimitForARequest(): void
    {
        $router = self::repositoryRoot() . '/bin/http-router.php';
        [$stdout, $stderr] = $this->runPhp(
            ['-d', 'memory_limit=128M', '-r', '$_SERVER["REQUEST_URI"] = "/not-mcp"; include ' . var_export($router, true) . '; echo "|", ini_get("memory_limit");'],
            [],
        );

        self::assertSame('', $stderr);
        self::assertStringEndsWith('|1G', $stdout);
    }

    /**
     * Run PHP with an environment that has no KNOSSOS_MEMORY_LIMIT unless given, and return [stdout, stderr].
     *
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @return array{string, string}
     */
    private function runPhp(array $arguments, array $environment): array
    {
        $out = tempnam(sys_get_temp_dir(), 'knossos-memory-out');
        $err = tempnam(sys_get_temp_dir(), 'knossos-memory-err');
        try {
            $base = getenv();
            unset($base['KNOSSOS_MEMORY_LIMIT']);
            $process = proc_open([PHP_BINARY, ...$arguments], [1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, self::repositoryRoot(), array_merge($base, $environment));
            self::assertIsResource($process);
            proc_close($process);

            return [(string) file_get_contents($out), (string) file_get_contents($err)];
        } finally {
            @unlink($out);
            @unlink($err);
        }
    }

    /** doctor reports the effective limit, its source, and an invalid or refused request as an error. */
    public function testDoctorReportsWhatWasApplied(): void
    {
        $this->withRestoredState(function (): void {
            putenv('KNOSSOS_MEMORY_LIMIT=2G');
            MemoryLimit::apply();
            self::assertSame(['ok', '2G (KNOSSOS_MEMORY_LIMIT)'], $this->doctorMemoryCheck());

            putenv('KNOSSOS_MEMORY_LIMIT=lots');
            MemoryLimit::apply();
            [$status, $detail] = $this->doctorMemoryCheck();
            self::assertSame('error', $status);
            self::assertStringContainsString('KNOSSOS_MEMORY_LIMIT=lots is not a valid size', $detail);

            putenv('KNOSSOS_MEMORY_LIMIT=1K');
            MemoryLimit::apply();
            [$status, $detail] = $this->doctorMemoryCheck();
            self::assertSame('error', $status);
            self::assertStringContainsString('1K', $detail);
        });
    }

    /** The CLI entry point applies the limit before anything heavy runs. */
    public function testBinKnossosAppliesTheLimit(): void
    {
        self::assertSame('1G|default|', $this->entryPoint(['-d', 'memory_limit=128M'], null));
        self::assertSame('768M|KNOSSOS_MEMORY_LIMIT|', $this->entryPoint(['-d', 'memory_limit=128M'], '768M'));
    }

    /** The HTTP router runs under `php -S`, so it applies the limit itself, straight after the autoloader. */
    public function testHttpRouterAppliesTheLimitAfterTheAutoloader(): void
    {
        $source = (string) file_get_contents(self::repositoryRoot() . '/bin/http-router.php');
        $autoload = strpos($source, "vendor/autoload.php';");
        $apply = strpos($source, 'MemoryLimit::apply()');

        self::assertNotFalse($autoload);
        self::assertNotFalse($apply);
        self::assertGreaterThan($autoload, $apply);
        self::assertLessThan(strpos($source, 'RuntimeFactory::', $autoload) ?: PHP_INT_MAX, $apply);
    }

    /**
     * Run the real `bin/knossos --version` in a fresh PHP and return "value|source|rejected" as the
     * limit stood when the process ended.
     *
     * A prepended probe registers a shutdown function, which still runs after the entry point's
     * `exit`, so the whole startup path is exercised and no database or doctor run is needed.
     *
     * @param list<string> $phpOptions
     */
    private function entryPoint(array $phpOptions, ?string $limit): string
    {
        $root = self::repositoryRoot();
        $probe = tempnam(sys_get_temp_dir(), 'knossos-memory-probe');
        $out = tempnam(sys_get_temp_dir(), 'knossos-memory-out');
        $err = tempnam(sys_get_temp_dir(), 'knossos-memory-err');
        try {
            file_put_contents($probe, '<?php register_shutdown_function(static function (): void {'
                . ' $l = \\Knossos\\Runtime\\MemoryLimit::applied();'
                . ' file_put_contents(getenv("KNOSSOS_PROBE_OUT"), ini_get("memory_limit") . "|" . $l->source . "|" . $l->rejected); });');
            $environment = array_merge(getenv(), ['KNOSSOS_PROBE_OUT' => $out]);
            unset($environment['KNOSSOS_MEMORY_LIMIT']);
            if ($limit !== null) {
                $environment['KNOSSOS_MEMORY_LIMIT'] = $limit;
            }
            $process = proc_open(
                [PHP_BINARY, ...$phpOptions, '-d', 'auto_prepend_file=' . $probe, $root . '/bin/knossos', '--version'],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', $err, 'w']],
                $pipes,
                $root,
                $environment,
            );
            self::assertIsResource($process);
            self::assertSame(0, proc_close($process));
            self::assertSame('', (string) file_get_contents($err));

            return (string) file_get_contents($out);
        } finally {
            @unlink($probe);
            @unlink($out);
            @unlink($err);
        }
    }

    /**
     * The php.memory_limit check as [status, detail].
     *
     * @return array{string, string}
     */
    private function doctorMemoryCheck(): array
    {
        $service = new DoctorService(SqliteConnection::open(':memory:'), self::repositoryRoot(), ':memory:');
        foreach ($service->run()['checks'] as $check) {
            if ($check['name'] === 'php.memory_limit') {
                return [$check['status'], $check['detail']];
            }
        }
        self::fail('doctor did not report php.memory_limit');
    }

    /** Run a body that changes the process-wide limit, then put the limit, the environment and applied() back. */
    private function withRestoredState(callable $body): void
    {
        $original = (string) ini_get('memory_limit');
        $before = getenv('KNOSSOS_MEMORY_LIMIT');
        try {
            $body();
        } finally {
            putenv($before === false ? 'KNOSSOS_MEMORY_LIMIT' : 'KNOSSOS_MEMORY_LIMIT=' . $before);
            @ini_set('memory_limit', $original);
            MemoryLimit::apply();
            @ini_set('memory_limit', $original);
        }
    }
}
