<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Application;
use Knossos\Cli\CliOptionParser;
use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\RootGuard;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
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
