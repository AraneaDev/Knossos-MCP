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

final class TypescriptAppTest extends KnossosTestCase
{
    #[Group('typescript-app')]
    public function testTypescriptApplicationEnrichmentCoversNextReactVueStateAndClientEndpoints(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/typescript-app';
        $files = [
            'app/api/orders/route.ts',
            'app/layout.tsx',
            'app/page.tsx',
            'src/client.ts',
            'src/hooks.ts',
            'src/store.ts',
            'src/vue.ts',
        ];
        $client = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => $files,
            'config_files' => ['tsconfig.json'],
        ]));
        $client->shutdown();
        $nodes = array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions));
        $edges = array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions));
        $roles = [];
        foreach ($nodes as $node) {
            foreach ($node->attributes['typescript_framework_roles'] ?? [] as $role) {
                $roles[] = $role;
            }
        }
        foreach (['nextjs.layout', 'nextjs.page', 'nextjs.route_handler', 'nextjs.server_action', 'react.component', 'react.hook', 'state.store', 'vue.component', 'vue.composable'] as $role) {
            assertArrayContains($role, $roles);
        }
        $route = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'route'))[0];
        assertSame('GET /api/orders => app/api/orders/route.ts#GET', $route->canonicalName);
        $endpoints = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'endpoint'));
        assertSame(['GET /api/orders', 'POST /api/orders'], array_map(fn(NodeFact $node): string => $node->canonicalName, $endpoints));
        assertSame(1, count(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'uses_hook')));
        assertSame(2, count(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'calls_endpoint')));

        $database = tempnam(sys_get_temp_dir(), 'knossos-typescript-app-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate TypeScript app database.');
        }
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, 'TypeScript App');
            assertSame(7, $result->data['parsed_files']);
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'nextjs.route_handler'")->fetchColumn());
            assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'react.component'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'vue.component'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'state.store'")->fetchColumn());
        } finally {
            unset($pdo);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                @unlink($candidate);
            }
        }
    }
}
