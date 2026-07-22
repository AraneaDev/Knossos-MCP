<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Application;
use Knossos\Cli\CliErrorRenderer;
use Knossos\Cli\CliHelpRenderer;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\RootGuard;
use Knossos\Scan\ScanBusyException;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use PDOException;
use RuntimeException;

final class CliTest extends KnossosTestCase
{
    #[Group('cli')]
    public function testCliFailuresExposeStableAutomationDiagnostics(): void
    {
        [$exit, , $stderr] = $this->runFixtureCommandOutput([PHP_BINARY, self::repositoryRoot() . '/bin/knossos', 'unknown-command']);
        assertSame(2, $exit);
        assertContains('KNOSSOS_INVALID_ARGUMENT:', $stderr);
    }

    #[Group('cli')]
    public function testCliOptionParsingPreservesRepeatedValuesFlagsAndPositionalOrder(): void
    {
        $parser = new CliOptionParser();
        [$positionals, $options] = $parser->parse(['project', '--edge-kind=calls', 'target', '--edge-kind=imports', '--json']);
        assertSame(['project', 'target'], $positionals);
        assertSame(['calls', 'imports'], $options['edge-kind']);
        assertSame(['true'], $options['json']);
        assertSame(12, $parser->integer(['limit' => ['12']], 'limit', 20, 1, 100));
        assertThrows(fn() => $parser->single(['limit' => ['1', '2']], 'limit'), InvalidArgumentException::class);
    }

    // ── CliOptionParser: parse() ────────────────────────────────────────

    #[Group('cli')]
    public function testParseAcceptsPlainPositionalArguments(): void
    {
        $parser = new CliOptionParser();
        [$positionals, $options] = $parser->parse(['foo', 'bar', 'baz']);

        assertSame(['foo', 'bar', 'baz'], $positionals);
        assertSame([], $options);
    }

    #[Group('cli')]
    public function testParseAcceptsEmptyArgumentList(): void
    {
        $parser = new CliOptionParser();
        [$positionals, $options] = $parser->parse([]);

        assertSame([], $positionals);
        assertSame([], $options);
    }

    #[Group('cli')]
    public function testParseTreatsFlagWithoutValueAsTrue(): void
    {
        $parser = new CliOptionParser();
        [$positionals, $options] = $parser->parse(['--verbose', '--debug']);

        assertSame([], $positionals);
        assertSame(['verbose' => ['true'], 'debug' => ['true']], $options);
    }

    #[Group('cli')]
    public function testParseRejectsEmptyOptionName(): void
    {
        $parser = new CliOptionParser();

        assertThrows(
            static fn () => $parser->parse(['--=value']),
            InvalidArgumentException::class,
        );
    }

    #[Group('cli')]
    public function testParseAcceptsOptionWithEmptyValueToken(): void
    {
        $parser = new CliOptionParser();
        [$positionals, $options] = $parser->parse(['--limit=']);

        assertSame(['limit' => ['']], $options);
    }

    #[Group('cli')]
    public function testParseHandlesMixedPositionalsAndOptionsPreservingOrder(): void
    {
        $parser = new CliOptionParser();
        [$positionals, $options] = $parser->parse([
            'first',
            '--name=alice',
            'second',
            '--name=bob',
            '--flag',
            'third',
        ]);

        assertSame(['first', 'second', 'third'], $positionals);
        assertSame(['name' => ['alice', 'bob'], 'flag' => ['true']], $options);
    }

    // ── CliOptionParser: single() ───────────────────────────────────────

    #[Group('cli')]
    public function testSingleReturnsNullForMissingOption(): void
    {
        $parser = new CliOptionParser();

        assertSame(null, $parser->single([], 'missing'));
    }

    #[Group('cli')]
    public function testSingleReturnsValueWhenExactlyOneValuePresent(): void
    {
        $parser = new CliOptionParser();

        assertSame('hello', $parser->single(['name' => ['hello']], 'name'));
    }

    #[Group('cli')]
    public function testSingleRejectsMultipleValues(): void
    {
        $parser = new CliOptionParser();

        assertThrows(
            static fn () => $parser->single(['name' => ['a', 'b']], 'name'),
            InvalidArgumentException::class,
        );
    }

    #[Group('cli')]
    public function testSingleRejectsEmptyStringValue(): void
    {
        $parser = new CliOptionParser();

        assertThrows(
            static fn () => $parser->single(['name' => ['']], 'name'),
            InvalidArgumentException::class,
        );
    }

    // ── CliOptionParser: integer() ──────────────────────────────────────

    #[Group('cli')]
    public function testIntegerReturnsDefaultWhenOptionMissing(): void
    {
        $parser = new CliOptionParser();

        assertSame(42, $parser->integer([], 'limit', 42, 1, 100));
    }

    #[Group('cli')]
    public function testIntegerReturnsParsedValueWithinRange(): void
    {
        $parser = new CliOptionParser();

        assertSame(7, $parser->integer(['limit' => ['7']], 'limit', 1, 1, 10));
    }

    #[Group('cli')]
    public function testIntegerReturnsBoundaryMinimum(): void
    {
        $parser = new CliOptionParser();

        assertSame(1, $parser->integer(['limit' => ['1']], 'limit', 5, 1, 100));
    }

    #[Group('cli')]
    public function testIntegerReturnsBoundaryMaximum(): void
    {
        $parser = new CliOptionParser();

        assertSame(100, $parser->integer(['limit' => ['100']], 'limit', 5, 1, 100));
    }

    #[Group('cli')]
    public function testIntegerRejectsValueBelowMinimum(): void
    {
        $parser = new CliOptionParser();

        assertThrows(
            static fn () => $parser->integer(['limit' => ['0']], 'limit', 5, 1, 100),
            InvalidArgumentException::class,
        );
    }

    #[Group('cli')]
    public function testIntegerRejectsValueAboveMaximum(): void
    {
        $parser = new CliOptionParser();

        assertThrows(
            static fn () => $parser->integer(['limit' => ['101']], 'limit', 5, 1, 100),
            InvalidArgumentException::class,
        );
    }

    #[Group('cli')]
    public function testIntegerRejectsNonIntegerString(): void
    {
        $parser = new CliOptionParser();

        assertThrows(
            static fn () => $parser->integer(['limit' => ['not-a-number']], 'limit', 5, 1, 100),
            InvalidArgumentException::class,
        );
    }

    #[Group('cli')]
    public function testIntegerRejectsFloatString(): void
    {
        $parser = new CliOptionParser();

        assertThrows(
            static fn () => $parser->integer(['limit' => ['3.14']], 'limit', 5, 1, 100),
            InvalidArgumentException::class,
        );
    }

    // ── CliOptionParser: boundaries() ───────────────────────────────────

    #[Group('cli')]
    public function testBoundariesReturnsEmptyForEmptyList(): void
    {
        $parser = new CliOptionParser();

        assertSame([], $parser->boundaries([]));
    }

    #[Group('cli')]
    public function testBoundariesParsesPathTypeBoundary(): void
    {
        $parser = new CliOptionParser();

        $result = $parser->boundaries(['api:path:src/Api/']);

        assertSame([['name' => 'api', 'path_prefix' => 'src/Api/']], $result);
    }

    #[Group('cli')]
    public function testBoundariesParsesNamespaceTypeBoundary(): void
    {
        $parser = new CliOptionParser();

        $result = $parser->boundaries(['domain:namespace:App\\Domain\\']);

        assertSame([['name' => 'domain', 'namespace_prefix' => 'App\\Domain\\']], $result);
    }

    #[Group('cli')]
    public function testBoundariesParsesMultipleBoundarySpecs(): void
    {
        $parser = new CliOptionParser();

        $result = $parser->boundaries(['api:path:src/Api/', 'domain:namespace:App\\Domain\\']);

        assertSame(2, count($result));
        assertSame([['name' => 'api', 'path_prefix' => 'src/Api/'], ['name' => 'domain', 'namespace_prefix' => 'App\\Domain\\']], $result);
    }

    #[Group('cli')]
    public function testBoundariesRejectsTooFewParts(): void
    {
        $parser = new CliOptionParser();

        assertThrows(
            static fn () => $parser->boundaries(['name:path']),
            InvalidArgumentException::class,
        );
    }

    #[Group('cli')]
    public function testBoundariesRejectsEmptyName(): void
    {
        $parser = new CliOptionParser();

        assertThrows(
            static fn () => $parser->boundaries([':path:src/']),
            InvalidArgumentException::class,
        );
    }

    #[Group('cli')]
    public function testBoundariesRejectsEmptyPrefix(): void
    {
        $parser = new CliOptionParser();

        assertThrows(
            static fn () => $parser->boundaries(['name:path:']),
            InvalidArgumentException::class,
        );
    }

    #[Group('cli')]
    public function testBoundariesRejectsUnknownType(): void
    {
        $parser = new CliOptionParser();

        assertThrows(
            static fn () => $parser->boundaries(['name:unknown:prefix']),
            InvalidArgumentException::class,
        );
    }

    #[Group('cli')]
    public function testBoundariesRejectsTypeWithInvalidCase(): void
    {
        $parser = new CliOptionParser();

        assertThrows(
            static fn () => $parser->boundaries(['name:Path:src/']),
            InvalidArgumentException::class,
        );
    }

    #[Group('cli')]
    public function testCliRouterKeepsHelpVersionAndUnknownCommandBehaviorStable(): void
    {
        $binary = self::repositoryRoot() . '/bin/knossos';
        [$versionExit, $versionOutput] = $this->runFixtureCommandOutput([PHP_BINARY, $binary, '--version', '--json']);
        assertSame(0, $versionExit);
        assertSame(['name' => 'knossos', 'version' => Application::VERSION], json_decode(trim($versionOutput), true, 512, JSON_THROW_ON_ERROR));
        [$helpExit, $helpOutput] = $this->runFixtureCommandOutput([PHP_BINARY, $binary, '--help']);
        assertSame(0, $helpExit);
        assertContains('Knossos architecture intelligence', $helpOutput);
    }

    #[Group('cli')]
    public function testListProjectsCliExposesTheCatalogueWithoutRootsByDefault(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-catalogue-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate catalogue database.');
        }
        try {
            $pdo = SqliteConnection::open($path);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            (new SqliteGraphRepository($pdo))->saveProject(
                StableId::project('catalogue-cli'),
                'Catalogue CLI',
                self::repositoryRoot() . '/tests/Fixtures/mixed',
            );
            unset($pdo);

            [$exit, $stdout, $stderr] = $this->runFixtureCommandOutput([
                PHP_BINARY,
                self::repositoryRoot() . '/bin/knossos',
                'list-projects',
                '--db=' . $path,
                '--json',
            ]);
            assertSame(0, $exit);
            assertSame('', $stderr);
            $payload = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
            assertSame('Catalogue CLI', $payload['data']['projects'][0]['name']);
            assertSame(false, array_key_exists('root', $payload['data']['projects'][0]));
        } finally {
            foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }

    #[Group('cli')]
    public function testRootguardResolvesRelativeAllowedRootsAgainstTheWorkingDirectory(): void
    {
        $guard = new RootGuard(['.']);
        assertSame(str_replace('\\', '/', (string) realpath(getcwd())), $guard->resolve('.'));

        $parent = new RootGuard(['..']);
        $resolved = $parent->resolve(self::repositoryRoot());
        assertSame(str_replace('\\', '/', (string) realpath(self::repositoryRoot())), $resolved);

        $narrow = new RootGuard([self::repositoryRoot() . '/src']);
        assertThrows(fn() => $narrow->resolve(self::repositoryRoot() . '/tests'), DiscoveryException::class);
    }

    #[Group('cli')]
    public function testServeRefusesToStartWithoutAnExplicitAllowedRoot(): void
    {
        $binary = self::repositoryRoot() . '/bin/knossos';
        $previous = getenv('KNOSSOS_ALLOWED_ROOTS');
        putenv('KNOSSOS_ALLOWED_ROOTS');
        try {
            [$exit, , $stderr] = $this->runFixtureCommandOutput([PHP_BINARY, $binary, 'serve']);
            assertSame(2, $exit);
            assertContains('--allow-root', $stderr);
        } finally {
            if (is_string($previous)) {
                putenv('KNOSSOS_ALLOWED_ROOTS=' . $previous);
            }
        }
    }

    #[Group('cli')]
    public function testCommittedMcpRegistrationIsPortableAndExplicitlyScoped(): void
    {
        $path = self::repositoryRoot() . '/.mcp.json';
        $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $server = $config['mcpServers']['knossos'];
        assertSame('php', $server['command']);
        // RootGuard::resolve() realpath()s each configured root against the process
        // working directory, so args must stay relative to remain portable across checkouts.
        assertSame(['bin/knossos', 'serve', '--allow-root=.'], $server['args']);
    }

    #[Group('cli')]
    public function testComposeFilePinsTheRuntimeStageAndNeverExposesAPublicPort(): void
    {
        $compose = (string) file_get_contents(self::repositoryRoot() . '/docker-compose.yml');

        // The Dockerfile's final stage is `quality`; every service must pin `runtime`.
        assertSame(1, substr_count($compose, 'target: runtime'));
        assertSame(false, str_contains($compose, 'target: quality'));

        // Both server services are opt-in, so `docker compose up` starts nothing that listens.
        assertContains('profiles:', $compose);
        assertContains('- mcp', $compose);
        assertContains('- http', $compose);

        // Source is mounted read-only, and the HTTP token is required rather than defaulted.
        assertContains('read_only: true', $compose);
        assertContains('KNOSSOS_HTTP_BEARER_TOKEN:?', $compose);

        // No absolute developer paths leak into a committed file.
        assertSame(false, str_contains($compose, '/root/'));

        // Docker-free backstop for the resolved-config port check below, which skips
        // when compose is unavailable: every published port entry must bind loopback.
        $portEntries = [];
        $portsIndent = null;
        foreach (explode("\n", $compose) as $line) {
            if (preg_match('/^(\s*)ports:\s*$/', $line, $matches) === 1) {
                $portsIndent = strlen($matches[1]);
                continue;
            }
            if ($portsIndent === null || trim($line) === '') {
                continue;
            }
            if (preg_match('/^(\s*)-\s*(\S.*?)\s*$/', $line, $matches) === 1 && strlen($matches[1]) > $portsIndent) {
                $portEntries[] = trim($matches[2], "\"'");
                continue;
            }
            $portsIndent = null;
        }

        assertNotSame([], $portEntries);
        foreach ($portEntries as $portEntry) {
            assertSame(true, str_starts_with($portEntry, '127.0.0.1:'));
        }
    }

    #[Group('cli')]
    public function testComposeConfigurationParsesAndKeepsServersBehindProfiles(): void
    {
        [$probeExit] = $this->runFixtureCommandOutput(['docker', 'compose', 'version']);
        if ($probeExit !== 0) {
            return; // Docker is not available in this environment; the text test above still applies.
        }

        $root = self::repositoryRoot();
        $previousToken = getenv('KNOSSOS_HTTP_BEARER_TOKEN');
        putenv('KNOSSOS_HTTP_BEARER_TOKEN=test-token-not-a-secret');
        try {
            [$exit, $stdout, $stderr] = $this->runFixtureCommandOutput(
                ['docker', 'compose', '--project-directory', $root, '-f', $root . '/docker-compose.yml', 'config', '--services'],
            );
        } finally {
            is_string($previousToken)
                ? putenv('KNOSSOS_HTTP_BEARER_TOKEN=' . $previousToken)
                : putenv('KNOSSOS_HTTP_BEARER_TOKEN');
        }

        if ($exit !== 0) {
            throw new RuntimeException('docker compose config failed: ' . $stderr);
        }

        // Only the default-profile service is listed without --profile flags.
        assertSame('knossos', trim($stdout));
        assertSame(false, str_contains($stdout, 'knossos-http'));
        assertSame(false, str_contains($stdout, 'knossos-mcp'));
    }

    #[Group('cli')]
    public function testComposeResolvedPortsAreLoopbackOnlyAcrossEveryProfile(): void
    {
        [$probeExit] = $this->runFixtureCommandOutput(['docker', 'compose', 'version']);
        if ($probeExit !== 0) {
            return; // Docker is not available in this environment; the text test above still applies.
        }

        $root = self::repositoryRoot();
        $previousToken = getenv('KNOSSOS_HTTP_BEARER_TOKEN');
        putenv('KNOSSOS_HTTP_BEARER_TOKEN=test-token-not-a-secret');
        try {
            [$exit, $stdout, $stderr] = $this->runFixtureCommandOutput([
                'docker', 'compose', '--project-directory', $root, '-f', $root . '/docker-compose.yml',
                '--profile', 'http', '--profile', 'mcp', 'config', '--format', 'json',
            ]);
        } finally {
            is_string($previousToken)
                ? putenv('KNOSSOS_HTTP_BEARER_TOKEN=' . $previousToken)
                : putenv('KNOSSOS_HTTP_BEARER_TOKEN');
        }

        if ($exit !== 0) {
            throw new RuntimeException('docker compose config failed: ' . $stderr);
        }

        /** @var array{services?: array<string, array{ports?: list<array{host_ip?: string}>}>} $config */
        $config = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $services = $config['services'] ?? [];
        $serviceNames = array_keys($services);
        sort($serviceNames);
        assertSame(['knossos', 'knossos-http', 'knossos-mcp'], $serviceNames);

        // Every published port, on every service resolved from every profile, must be loopback-only.
        $publishedPortCount = 0;
        foreach ($services as $service) {
            foreach ($service['ports'] ?? [] as $port) {
                ++$publishedPortCount;
                assertSame('127.0.0.1', $port['host_ip'] ?? null);
            }
        }

        // At least one port must actually be published, so this cannot pass vacuously.
        assertSame(true, $publishedPortCount > 0);
    }

    // ── CliErrorRenderer ──────────────────────────────────────────────────

    #[Group('cli')]
    public function testErrorRendererReturnsExitCode2ForAnyException(): void
    {
        // All exception types must return exit code 2.
        // Note: fwrite(STDERR, ...) cannot be captured with ob_start(),
        // so we only test the return code here. The diagnostic codes are
        // verified through subprocess tests (testCliFailuresExposeStableAutomationDiagnostics).
        foreach ([
            new RuntimeException('msg'),
            new InvalidArgumentException('msg'),
            new PDOException('msg'),
            new WorkerException('WORKER_TIMEOUT', 'msg'),
            new ScanBusyException('msg'),
            new DiscoveryException('msg'),
        ] as $error) {
            $exit = (new CliErrorRenderer())->render($error);
            assertSame(2, $exit);
        }
    }

    #[Group('cli')]
    public function testErrorRendererDefaultCaseCoversUnknownExceptionTypes(): void
    {
        // Any exception that doesn't match a specific type gets KNOSSOS_RUNTIME_ERROR.
        // Note: The default case in the match() also catches RuntimeException
        // (which is NOT a subclass of any of the other matched types).
        // This test verifies that an unrecognized exception type still returns
        // exit code 2 without crashing.
        $exit = (new CliErrorRenderer())->render(new \BadMethodCallException('unexpected'));
        assertSame(2, $exit);
    }

    // ── CliInputLoader ────────────────────────────────────────────────────

    #[Group('cli')]
    public function testInputLoaderPoliciesAcceptsValidJsonArray(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-policies-');
        file_put_contents($path, json_encode([['policy' => 'allow']]));

        $result = (new CliInputLoader())->policies($path);

        assertSame([['policy' => 'allow']], $result);
        unlink($path);
    }

    #[Group('cli')]
    public function testInputLoaderPoliciesRejectsMissingFile(): void
    {
        assertThrows(
            static fn () => (new CliInputLoader())->policies('/nonexistent/policies.json'),
            InvalidArgumentException::class,
        );
    }

    #[Group('cli')]
    public function testInputLoaderPoliciesRejectsOversizedFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-big-policies-');
        file_put_contents($path, str_repeat('x', 1_000_001));

        assertThrows(
            static fn () => (new CliInputLoader())->policies($path),
            InvalidArgumentException::class,
        );
        unlink($path);
    }

    #[Group('cli')]
    public function testInputLoaderJsonObjectAcceptsValidObject(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-json-');
        file_put_contents($path, json_encode(['key' => 'value']));

        $result = (new CliInputLoader())->jsonObject($path);

        assertSame(['key' => 'value'], $result);
        unlink($path);
    }

    #[Group('cli')]
    public function testInputLoaderJsonObjectRejectsList(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-json-list-');
        file_put_contents($path, json_encode([1, 2, 3]));

        assertThrows(
            static fn () => (new CliInputLoader())->jsonObject($path),
            InvalidArgumentException::class,
        );
        unlink($path);
    }

    #[Group('cli')]
    public function testInputLoaderBundleAcceptsValidFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-bundle-');
        $content = gzencode('{"test": true}');
        file_put_contents($path, $content);

        $result = (new CliInputLoader())->bundle($path);

        assertSame($content, $result);
        unlink($path);
    }

    #[Group('cli')]
    public function testInputLoaderBundleRejectsOversizedFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-big-bundle-');
        file_put_contents($path, str_repeat('x', 10_000_001));

        assertThrows(
            static fn () => (new CliInputLoader())->bundle($path),
            InvalidArgumentException::class,
        );
        unlink($path);
    }

    #[Group('cli')]
    public function testArchitectureSummaryJsonEmitsExactlyOneJsonDocument(): void
    {
        $root = self::repositoryRoot();
        $path = tempnam(sys_get_temp_dir(), 'knossos-architecture-summary-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate architecture-summary database.');
        }
        try {
            [$scanExit, $scanOut, $scanErr] = $this->runFixtureCommandOutput([
                PHP_BINARY, $root . '/bin/knossos', 'scan', $root . '/tests/Fixtures/php-scanner', '--db=' . $path, '--json',
            ]);
            assertSame(0, $scanExit);
            assertSame('', $scanErr);
            $scan = json_decode(trim($scanOut), true, 512, JSON_THROW_ON_ERROR);

            [$exit, $stdout, $stderr] = $this->runFixtureCommandOutput([
                PHP_BINARY, $root . '/bin/knossos', 'architecture-summary', $scan['project_id'], '--db=' . $path, '--json',
            ]);
            assertSame(0, $exit);
            assertSame('', $stderr);

            // The payload must decode. Two concatenated documents make json_decode fail.
            $decoded = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
            assertSame($scan['project_id'], $decoded['project_id']);

            // And it must be one line, like every other --json query command.
            assertSame(1, count(array_filter(explode("\n", trim($stdout)), fn(string $l): bool => trim($l) !== '')));
        } finally {
            foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }
}
