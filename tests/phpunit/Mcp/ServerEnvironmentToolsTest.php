<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Discovery\AllowedRoots;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\Protocol\Profile20260728;
use Knossos\Mcp\Protocol\ProtocolNegotiator;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolInputException;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\StalenessProbe;
use Knossos\Runtime\ServerEnvironment;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * `server_info` exists so an agent can answer "what may I ask this server for?"
 * without shell access — the question that a bare allow-root rejection leaves
 * unanswerable.
 */
final class ServerEnvironmentToolsTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testServerInfoReportsRootsTheirOriginAndTheFileToExtend(): void
    {
        $granted = $this->makeTempDir();
        $file = $this->makeTempDir() . '/roots.json';
        file_put_contents($file, json_encode(['roots' => [$granted]], JSON_THROW_ON_ERROR));
        $tools = $this->tools(new AllowedRoots([], $file));

        $data = $tools->call('server_info', [])->data;

        assertSame('knossos', $data['name']);
        assertSame(ProtocolNegotiator::supported(), $data['protocol_versions']);
        assertSame([$granted], array_column($data['allowed_roots'], 'path'));
        assertSame([AllowedRoots::SOURCE_FILE], array_column($data['allowed_roots'], 'source'));
        // The path to extend is the actionable part; without it an agent knows
        // it is blocked but not how to become unblocked.
        assertSame($file, $data['roots_file']);
    }

    #[Group('mcp')]
    public function testServerInfoWarnsWhenNothingIsScannable(): void
    {
        $file = $this->makeTempDir() . '/roots.json';
        $result = $this->tools(new AllowedRoots([], $file))->call('server_info', []);

        assertSame([], $result->data['allowed_roots']);
        assertSame(1, count($result->warnings));
        // The warning has to carry the remedy, not just the diagnosis.
        $this->assertStringContainsString($file, $result->warnings[0]);
        $this->assertStringContainsString('re-read per request', $result->warnings[0]);
    }

    #[Group('mcp')]
    public function testServerInfoWarnsAboutRootsThatDoNotExistHere(): void
    {
        $ghost = sys_get_temp_dir() . '/knossos-ghost-' . uniqid('', true);
        $file = $this->makeTempDir() . '/roots.json';
        file_put_contents($file, json_encode(['roots' => [$ghost]], JSON_THROW_ON_ERROR));

        $result = $this->tools(new AllowedRoots([], $file))->call('server_info', []);

        // The signature of a host path given to a containerised server, or a
        // roots file copied from another machine.
        assertSame([$ghost], $result->data['unreachable_roots']);
        $this->assertStringContainsString('do not exist on this server', $result->warnings[0]);
    }

    #[Group('mcp')]
    public function testEnvironmentToolsAreOnlyAdvertisedWhenTheServerCanAnswerThem(): void
    {
        $withNames = array_column($this->tools(new AllowedRoots([]))->definitions(), 'name');
        $withoutNames = array_column($this->tools(null)->definitions(), 'name');

        assertArrayContains('server_info', $withNames);
        assertArrayContains('diagnose_runtime', $withNames);
        // Listing a tool that would only ever error is worse than not listing it.
        assertSame(false, in_array('server_info', $withoutNames, true));
        assertSame(false, in_array('diagnose_runtime', $withoutNames, true));
        assertSame(count($withNames) - 2, count($withoutNames));
    }

    #[Group('mcp')]
    public function testEnvironmentToolsRejectArgumentsAndUnwiredServers(): void
    {
        $tools = $this->tools(new AllowedRoots([]));

        self::assertThrowsWith(static fn() => $tools->call('server_info', ['path' => '/tmp']), ToolInputException::class);
        self::assertThrowsWith(
            fn() => $this->tools(null)->call('server_info', []),
            \InvalidArgumentException::class,
        );
    }

    #[Group('mcp')]
    public function testLegacyRevisionCanBeWithdrawnWithoutCodeChanges(): void
    {
        putenv('KNOSSOS_LEGACY_PROTOCOL=0');
        try {
            // Advertising a revision the negotiator would then refuse is worse
            // than not advertising it, so the supported set must shrink too.
            assertSame([Profile20260728::VERSION], ProtocolNegotiator::supported());
            assertSame(false, ProtocolNegotiator::legacyEnabled());
        } finally {
            putenv('KNOSSOS_LEGACY_PROTOCOL');
        }

        assertSame(true, ProtocolNegotiator::legacyEnabled());
        assertSame(['2026-07-28', '2025-11-25'], ProtocolNegotiator::supported());
    }

    private function tools(?AllowedRoots $roots): ToolService
    {
        $pdo = $this->database();
        $environment = $roots === null
            ? null
            : new ServerEnvironment($roots, $this->databasePath, self::repositoryRoot(), $pdo);

        return new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), $roots ?? []),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
            $environment,
        );
    }

    private string $databasePath = ':memory:';
    private ?PDO $pdo = null;

    private function database(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = SqliteConnection::open(':memory:');
            (new MigrationRunner($this->pdo, self::repositoryRoot() . '/migrations'))->migrate();
        }

        return $this->pdo;
    }

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->tempDirs = [];
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/knossos-serverinfo-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }
}
