<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class NestJsTest extends KnossosTestCase
{
    #[Group('nestjs')]
    public function testNestjsEnricherExtractsDecoratorRolesModulesAndRoutesWithoutBootingTheApp(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/nest';
        $files = ['src/app.module.ts', 'src/cats.controller.ts', 'src/cats.service.ts'];
        $client = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => $files, 'config_files' => ['tsconfig.json']]));
        $client->shutdown();
        $nodes = array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions));
        $edges = array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions));
        $roles = [];
        foreach ($nodes as $node) {
            foreach ($node->attributes['nestjs_roles'] ?? [] as $role) {
                $roles[] = $role;
            }
        }
        sort($roles);
        assertSame(['nestjs.controller', 'nestjs.module', 'nestjs.provider'], $roles);
        $routes = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'route'));
        assertSame(2, count($routes));
        assertSame(['GET /cats', 'POST /cats/adopt'], array_column($routes, 'displayName'));
        assertSame(2, count(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'routes_to')));
        assertSame(2, count(array_filter($edges, fn(EdgeFact $edge): bool => in_array($edge->attributes['nestjs_module_field'] ?? null, ['controllers', 'providers'], true))));
        assertSame(1, count(array_filter($edges, fn(EdgeFact $edge): bool => ($edge->attributes['nestjs_module_field'] ?? null) === 'exports')));

        $database = tempnam(sys_get_temp_dir(), 'knossos-nest-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate NestJS database.');
        }
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $scan = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, 'Nest Fixture');
            assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route'")->fetchColumn());
            assertSame(3, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role LIKE 'nestjs.%'")->fetchColumn());
            assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM edges WHERE kind = 'routes_to'")->fetchColumn());
            assertSame(true, $scan->data['nodes'] >= 10);
        } finally {
            unset($pdo);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }
}
