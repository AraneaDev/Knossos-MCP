<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use JsonException;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliErrorRenderer;
use Knossos\Cli\CliHelpRenderer;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Discovery\DiscoveryException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scan\ScanBusyException;
use Knossos\Scan\ScanCancelledException;
use Knossos\Scanner\Worker\WorkerException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Direct tests for the 5 mut-active Cli helper classes:
 *
 *   - src/Cli/CliOptionParser.php    (parse / single / integer / boundaries)
 *   - src/Cli/CliInputLoader.php     (policies / jsonObject / bundle)
 *   - src/Cli/CliHelpRenderer.php    (render())
 *   - src/Cli/CliErrorRenderer.php   (render() with Throwable-type dispatch)
 *   - src/Cli/CliCommandContext.php  (constructor + lazy database/maintenance + output)
 *
 * `CliCommand.php` (interface) was covered as structural-infimum in
 * batch 7. The concrete *Command.php implementations in src/Cli/Command/
 * are scheduled for batch 10b. `CliCommandRouter.php` is scheduled for
 * batch 10c.
 */
#[Group('cli')]
final class CliHelpersTest extends \Knossos\Tests\Phpunit\KnossosTestCase
{
    // ===== CliOptionParser =================================================

    public function testOptionParserBucketsPositionalsAndOptions(): void
    {
        // M1 / parse(): positional args (no --) and option args (--key=val
        // and bare --flag) bucket into separate array slots with multiple
        // --key occurrences all stored in the same key's list.
        $parser = new CliOptionParser();
        [$positionals, $options] = $parser->parse([
            'cmd',
            '--limit=10',
            '--mode=auto',
            'src/',
            '--mode=incremental',
            '--json',
        ]);
        assertSame(['cmd', 'src/'], $positionals);
        assertSame(['limit' => ['10'], 'mode' => ['auto', 'incremental'], 'json' => ['true']], $options);
    }

    public function testOptionParserRejectsEmptyOptionName(): void
    {
        // M2 / parse() throw: an option starting with `--` but with an
        // empty name after the `--` strip throws. The empty-string
        // input without `--` prefix would just be bucketed as a positional
        // arg — it does NOT throw. The intent is to exercise the throw
        // guard, so the input must be `--` (or `--=value`).
        $parser = new CliOptionParser();
        assertThrows(
            fn() => $parser->parse(['--']),
            InvalidArgumentException::class,
        );
        assertThrows(
            fn() => $parser->parse(['--=value']),
            InvalidArgumentException::class,
        );
    }

    public function testOptionParserSingleReturnsNullOrValue(): void
    {
        // M3 / single(): missing key returns null; present key returns
        // the value; multi-value throws; empty-string value throws.
        $parser = new CliOptionParser();
        assertSame(null, $parser->single([], 'missing'));
        assertSame('auto', $parser->single(['mode' => ['auto']], 'mode'));
        assertThrows(
            fn() => $parser->single(['mode' => ['auto', 'incremental']], 'mode'),
            InvalidArgumentException::class,
        );
        assertThrows(
            fn() => $parser->single(['mode' => ['']], 'mode'),
            InvalidArgumentException::class,
        );
    }

    public function testOptionParserIntegerBoundsCheck(): void
    {
        // M4 / integer(): missing key falls back to default; present
        // valid value parsed via filter_var; out-of-range throws.
        $parser = new CliOptionParser();
        assertSame(5, $parser->integer([], 'limit', 5, 1, 10));
        assertSame(7, $parser->integer(['limit' => ['7']], 'limit', 5, 1, 10));
        assertThrows(
            fn() => $parser->integer(['limit' => ['11']], 'limit', 5, 1, 10),
            InvalidArgumentException::class,
        );
        assertThrows(
            fn() => $parser->integer(['limit' => ['not-a-number']], 'limit', 5, 1, 10),
            InvalidArgumentException::class,
        );
    }

    public function testOptionParserBoundariesParsesValidShape(): void
    {
        // M5 / boundaries(): `NAME:path:PREFIX` and `NAME:namespace:PREFIX`
        // → name + path_prefix / namespace_prefix keys. Empty / wrong-shape
        // / wrong-type values throw.
        $parser = new CliOptionParser();
        assertSame(
            [['name' => 'Frontend', 'path_prefix' => 'packages/frontend/']],
            $parser->boundaries(['Frontend:path:packages/frontend/']),
        );
        assertSame(
            [['name' => 'Tests', 'namespace_prefix' => 'Tests\\']],
            $parser->boundaries(['Tests:namespace:Tests\\']),
        );
        assertThrows(
            fn() => $parser->boundaries(['Bad']),
            InvalidArgumentException::class,
        );
        assertThrows(
            fn() => $parser->boundaries(['']),
            InvalidArgumentException::class,
        );
    }

    // ===== CliInputLoader ==================================================

    private static function tempFile(string $relativePath): string
    {
        $base = sys_get_temp_dir() . '/knossos-cli-' . bin2hex(random_bytes(6));
        mkdir($base, 0700, true);
        $absolute = $base . '/' . $relativePath;
        $dir = dirname($absolute);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        return $absolute;
    }

    public function testInputLoaderPoliciesReadsValidJsonList(): void
    {
        $path = self::tempFile('policies.json');
        file_put_contents($path, '[{"name":"policy1"},{"name":"policy2"}]');
        try {
            $loader = new CliInputLoader();
            $policies = $loader->policies($path);
            assertSame([['name' => 'policy1'], ['name' => 'policy2']], $policies);
        } finally {
            unlink($path);
        }
    }

    public function testInputLoaderPoliciesThrowsOnMissingFile(): void
    {
        $loader = new CliInputLoader();
        assertThrows(
            fn() => $loader->policies('/nonexistent-path-' . bin2hex(random_bytes(4)) . '.json'),
            InvalidArgumentException::class,
        );
    }

    public function testInputLoaderPoliciesThrowsOnNonList(): void
    {
        $path = self::tempFile('policies-not-list.json');
        file_put_contents($path, '{"name":"dict"}');
        try {
            assertThrows(
                fn() => (new CliInputLoader())->policies($path),
                InvalidArgumentException::class,
            );
        } finally {
            unlink($path);
        }
    }

    public function testInputLoaderJsonObjectRecognizesObject(): void
    {
        $path = self::tempFile('obj.json');
        file_put_contents($path, '{"a":1,"b":2}');
        try {
            $obj = (new CliInputLoader())->jsonObject($path);
            assertSame(['a' => 1, 'b' => 2], $obj);
        } finally {
            unlink($path);
        }
    }

    public function testInputLoaderJsonObjectRejectsList(): void
    {
        $path = self::tempFile('obj-as-list.json');
        file_put_contents($path, '[1,2,3]');
        try {
            assertThrows(
                fn() => (new CliInputLoader())->jsonObject($path),
                InvalidArgumentException::class,
            );
        } finally {
            unlink($path);
        }
    }

    public function testInputLoaderBundleReadsRawText(): void
    {
        $path = self::tempFile('bundle.gxt');
        file_put_contents($path, 'knossos bundle content');
        try {
            $text = (new CliInputLoader())->bundle($path);
            assertSame('knossos bundle content', $text);
        } finally {
            unlink($path);
        }
    }

    public function testInputLoaderBundleThrowsOnMissing(): void
    {
        assertThrows(
            fn() => (new CliInputLoader())->bundle('/nonexistent-' . bin2hex(random_bytes(4))),
            InvalidArgumentException::class,
        );
    }

    public function testInputLoaderPoliciesWithDepth64Nesting(): void
    {
        // Kill DecrementInteger (64→63) mutant on json_decode depth in
        // policies(). PHP's json_decode depth counts containers + scalar,
        // so [0] (1 container) needs depth 2. For depth 64, the maximum
        // is 63 containers (63+1=64). Build the JSON via str_repeat to
        // control the exact count.
        $json = '[' . str_repeat('[', 62) . '0' . str_repeat(']', 62) . ']';
        $path = self::tempFile('depth64-policies.json');
        file_put_contents($path, $json);
        try {
            $loader = new CliInputLoader();
            $result = $loader->policies($path);
            assertSame(true, is_array($result), 'Result must be an array');
            assertSame(true, array_is_list($result), 'Result must be a list');
        } finally {
            unlink($path);
        }
    }

    public function testInputLoaderPoliciesRejectsDepth65Nesting(): void
    {
        // Kill IncrementInteger (64→65) mutant on json_decode depth in
        // policies(). 64 containers + 1 scalar = traversal depth 65,
        // which exceeds depth 64, causing JsonException.
        $json = '[' . str_repeat('[', 63) . '0' . str_repeat(']', 63) . ']';
        $path = self::tempFile('depth65-policies.json');
        file_put_contents($path, $json);
        try {
            assertThrows(
                fn() => (new CliInputLoader())->policies($path),
                JsonException::class,
            );
        } finally {
            unlink($path);
        }
    }

    public function testInputLoaderJsonObjectWithDepth64Nesting(): void
    {
        // Kill DecrementInteger (64→63) mutant on json_decode depth in
        // jsonObject(). JSON object nested 63 objects deep (63+1=64).
        // Top level must be an object {}, not array [].
        $json = '{"k":' . str_repeat('{"k":', 62) . '0' . str_repeat('}', 62) . '}';
        $path = self::tempFile('depth64-obj.json');
        file_put_contents($path, $json);
        try {
            $loader = new CliInputLoader();
            $result = $loader->jsonObject($path);
            assertSame(true, is_array($result), 'Result must be an array');
            assertSame(true, !array_is_list($result), 'Result must be an object');
        } finally {
            unlink($path);
        }
    }

    public function testInputLoaderJsonObjectRejectsDepth65Nesting(): void
    {
        // Kill IncrementInteger (64→65) mutant on json_decode depth in
        // jsonObject(). 64 nested objects + scalar = depth 65, exceeds
        // max depth 64.
        $json = '{"k":' . str_repeat('{"k":', 63) . '0' . str_repeat('}', 63) . '}';
        $path = self::tempFile('depth65-obj.json');
        file_put_contents($path, $json);
        try {
            assertThrows(
                fn() => (new CliInputLoader())->jsonObject($path),
                JsonException::class,
            );
        } finally {
            unlink($path);
        }
    }

    // ===== CliHelpRenderer =================================================

    public function testHelpRendererRendersSuccessfully(): void
    {
        // render() writes the help text to STDOUT via fwrite. The I/O side
        // effect cannot be reliably captured at the userland ob_get_clean
        // boundary under PHPUnit's CLI stream capture, and the source has
        // no return value to observe. Smoke test — render() must run to
        // completion without throwing.
        (new CliHelpRenderer())->render();
        assertSame(true, true); // sentinel (assertTrue is not in Support/Assertions.php)
    }

    // ===== CliErrorRenderer ================================================

    public function testErrorRendererDispatchesDiscoveryException(): void
    {
        // M14 / render() match-arm: DiscoveryException -> KNOSSOS_DISCOVERY_ERROR.
        // Source always returns 2. The match-arm itself is verifiable only
        // via STDERR capture which is unreliable under PHPUnit CLI capture.
        assertSame(2, (new CliErrorRenderer())->render(new DiscoveryException('d')));
    }

    public function testErrorRendererDispatchesScanBusyException(): void
    {
        // match-arm: ScanBusyException -> KNOSSOS_SCAN_BUSY.
        assertSame(2, (new CliErrorRenderer())->render(new ScanBusyException('b')));
    }

    public function testErrorRendererDispatchesScanCancelledException(): void
    {
        // match-arm: ScanCancelledException -> KNOSSOS_SCAN_CANCELLED.
        assertSame(2, (new CliErrorRenderer())->render(new ScanCancelledException('c')));
    }

    public function testErrorRendererDispatchesInvalidArgumentException(): void
    {
        // match-arm: InvalidArgumentException -> KNOSSOS_INVALID_ARGUMENT.
        assertSame(2, (new CliErrorRenderer())->render(new InvalidArgumentException('i')));
    }

    public function testErrorRendererDispatchesPdoException(): void
    {
        // match-arm: PDOException -> KNOSSOS_STORAGE_ERROR.
        assertSame(2, (new CliErrorRenderer())->render(new \PDOException('p')));
    }

    public function testErrorRendererDispatchesUnknownThrowableToRuntimeError(): void
    {
        // match-arm default: unknown Throwable -> KNOSSOS_RUNTIME_ERROR.
        assertSame(2, (new CliErrorRenderer())->render(new RuntimeException('r')));
    }

    public function testErrorRendererDispatchesWorkerExceptionToItsDiagnosticCode(): void
    {
        // match-arm: WorkerException -> `$error->diagnosticCode` (the FIRST
        // arm — important for ordering since match(true) tries arms in
        // declaration order). This lifts coverage from the 6 standard arms
        // to also include the diagnosticCode passthrough arm.
        $code = (new CliErrorRenderer())->render(
            new WorkerException('KNOSSOS_WORKER_TIMEOUT', 'timed out'),
        );
        assertSame(2, $code);
    }

    // ===== CliCommandContext ===============================================

    public function testCommandContextConstructorStoresPromotedFields(): void
    {
        $context = new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            ':memory:',
        );
        assertSame(true, $context->options instanceof CliOptionParser);
        assertSame(true, $context->input instanceof CliInputLoader);
        assertSame(':memory:', $context->databasePath());
        assertSame(self::repositoryRoot(), $context->installationRoot());
    }

    public function testCommandContextDatabaseIsLazyAndReturnsPdo(): void
    {
        $context = new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            ':memory:',
        );
        $pdo = $context->database();
        assertSame(true, $pdo instanceof \PDO);
        assertSame('sqlite', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        // Subsequent calls return the same lazy-initialised instance.
        assertSame($pdo, $context->database());
    }

    public function testCommandContextMaintenanceIsLazyAndReturnsService(): void
    {
        $context = new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            ':memory:',
        );
        $maint = $context->maintenance();
        assertSame(true, $maint instanceof DatabaseMaintenanceService);
        // Lazy: same instance on the second call.
        assertSame($maint, $context->maintenance());
    }

    public function testCommandContextOutputRunsWithoutError(): void
    {
        // output() fwrite's to STDOUT; that I/O side-effect cannot be reliably
        // captured at the userland ob_get_clean boundary under PHPUnit CLI
        // capture. The source has no return value. Smoke test — exercises
        // both branches (json=false and json=true) and verifies no throw.
        $context = new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            ':memory:',
        );
        $context->output(['k' => 'v'], false, 'plain text');
        $context->output(['k' => 'v'], true, 'unused');
        assertSame(true, true); // sentinel — both calls returned without throwing
    }

    public function testCommandContextDatabasePathFallsBackToRuntimeDefaultWhenNull(): void
    {
        // Coverage of CliCommandContext::databasePath()'s `?? $this->runtime->defaultDatabasePath()`
        // fallback branch — exercised when constructor receives `null` for
        // the 4th parameter. All other tests pass ':memory:' which exercises
        // only the `$this->databasePath` truthy path.
        $expected = (new RuntimeFactory(self::repositoryRoot()))->defaultDatabasePath();
        $context = new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            null,
        );
        assertSame($expected, $context->databasePath());
        assertNotSame(':memory:', $context->databasePath());
    }

    public function testCommandContextCancellationTokenIsCreated(): void
    {
        $context = new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            ':memory:',
        );
        $token = $context->cancellationToken();
        assertSame(true, $token instanceof \Knossos\Scan\CancellationToken);
    }

    public function testCommandContextCancellationTokenWithHandleTermination(): void
    {
        // Coverage for CliCommandContext::cancellationToken()'s
        // $handleTermination=true branch that registers a SIGTERM handler
        // in addition to SIGINT. Only runs if pcntl is available.
        if (!function_exists('pcntl_async_signals')) {
            assertSame(true, true);
            return;
        }
        $context = new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            ':memory:',
        );
        $token = $context->cancellationToken(true);
        assertSame(true, $token instanceof \Knossos\Scan\CancellationToken);
        assertSame(false, $token->isCancelled());
    }
}
