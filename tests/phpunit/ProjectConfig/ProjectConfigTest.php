<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\ProjectConfig;

use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\DiscoveryException;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class ProjectConfigTest extends KnossosTestCase
{
    #[Group('project-config')]
    public function testCheckedInProjectConfigurationValidatesMergesAndYieldsToExplicitOverrides(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/configured';
        $configuration = ProjectConfigurationLoader::load($root, [$root]);
        assertSame('knossos.jsonc', $configuration->path);
        assertSame(['ignored/**'], $configuration->ignores);
        assertSame(['symfony'], $configuration->frameworks);
        assertSame(40_000, $configuration->workerTimeoutMs);
        assertSame(2, $configuration->snapshotRetention);
        assertSame(['new_cycles' => 0, 'error_diagnostics' => 0], $configuration->qualityBudgets);

        $database = tempnam(sys_get_temp_dir(), 'knossos-configured-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate configured project database.');
        }
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $first = $service->scan($root, 'Configured Fixture');
            assertSame('knossos.jsonc', $first->data['configuration']['source']);
            assertSame(40_000, $first->data['worker_execution']['request_timeout_ms']);
            assertSame(120_000, $first->data['worker_execution']['maximum_request_timeout_ms']);
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route'")->fetchColumn());
            assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM files WHERE relative_path LIKE 'ignored/%'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM boundaries WHERE name = 'Configured Source' AND source = 'explicit'")->fetchColumn());

            $overridden = $service->scan($root, 'Configured Fixture', 100, 100_000, [], 'full', snapshotRetention: 0, workerTimeoutMs: 30_000);
            assertSame(30_000, $overridden->data['worker_execution']['request_timeout_ms']);
            assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM boundaries WHERE name = 'Configured Source' AND source = 'explicit'")->fetchColumn());
        } finally {
            unset($service, $pdo);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                @unlink($candidate);
            }
        }

        $invalid = sys_get_temp_dir() . '/knossos-invalid-config-' . bin2hex(random_bytes(6));
        if (!mkdir($invalid, 0o700)) {
            throw new RuntimeException('Unable to create invalid configuration fixture.');
        }
        try {
            file_put_contents($invalid . '/knossos.json', '{"version":1,"unknown":true}');
            $error = captureThrows(fn() => ProjectConfigurationLoader::load($invalid, [$invalid]), DiscoveryException::class);
            assertContains('PROJECT_CONFIG_UNKNOWN_KEY', $error->getMessage());
            file_put_contents($invalid . '/knossos.json', '{"version":1,"ignores":["../outside"]}');
            $error = captureThrows(fn() => ProjectConfigurationLoader::load($invalid, [$invalid]), DiscoveryException::class);
            assertContains('PROJECT_CONFIG_UNSAFE', $error->getMessage());
            $invalidCases = [
                ['{"version":2}', 'VERSION_UNSUPPORTED'],
                ['{"version":1,"frameworks":["unsupported"]}', 'unsupported framework'],
                ['{"version":1,"limits":[1]}', 'limits must be an object'],
                ['{"version":1,"limits":{"max_files":0}}', 'max_files must be between'],
                ['{"version":1,"limits":{"worker_timeout_ms":999}}', 'worker_timeout_ms must be between'],
                ['{"version":1,"ignores":"invalid"}', 'ignores must be a bounded list'],
                ['{"version":1,"ignores":[""]}', 'ignores must contain non-empty strings'],
                ['{"version":1,"boundaries":"invalid"}', 'boundaries must be a bounded list'],
                ['{"version":1,"boundaries":[[]]}', 'boundaries entries must be objects'],
                ['{"version":1,"boundaries":[{"name":""}]}', 'boundary name must be non-empty'],
                ['{"version":1,"boundaries":[{"name":"Core"}]}', 'boundary must declare'],
                ['{"version":1,"boundaries":[{"name":"Core","path_prefix":"/root"}]}', 'path_prefix must be project-relative'],
                ['{"version":1,"boundaries":[{"name":"Core","namespace_prefix":7}]}', 'namespace_prefix must be a string'],
                ['{"version":1,"policies":[{"id":"","from_boundary":"Core","allow_targets":[]}]}', 'policies require non-empty'],
                ['{"version":1,"policies":[{"id":"core","from_boundary":"Core"}]}', 'policies require allow_targets'],
                ['{"version":1,"quality_budgets":{"new_cycles":-1}}', 'quality budget new_cycles'],
            ];
            foreach ($invalidCases as [$document, $message]) {
                file_put_contents($invalid . '/knossos.json', $document);
                $error = captureThrows(fn() => ProjectConfigurationLoader::load($invalid, [$invalid]), DiscoveryException::class);
                assertContains($message, $error->getMessage());
            }
            file_put_contents($invalid . '/knossos.jsonc', '{"version":1}');
            $error = captureThrows(fn() => ProjectConfigurationLoader::load($invalid, [$invalid]), DiscoveryException::class);
            assertContains('PROJECT_CONFIG_AMBIGUOUS', $error->getMessage());
        } finally {
            @unlink($invalid . '/knossos.json');
            @unlink($invalid . '/knossos.jsonc');
            @rmdir($invalid);
        }
    }
}
