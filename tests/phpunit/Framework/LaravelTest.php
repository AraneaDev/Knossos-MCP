<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class LaravelTest extends KnossosTestCase
{
    #[Group('laravel')]
    public function testLaravelEnricherExtractsStaticRoutesGroupsAndFrameworkRelations(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/laravel';
        $discovery = (new ProjectDiscoverer(new DiscoveryConfig([$root])))->discover($root);
        $files = array_map(fn($file): string => $file->relativePath, $discovery->files);
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => $files,
            'frameworks' => ['laravel'],
        ]));
        $client->shutdown();
        $nodes = array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions));
        $edges = array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions));
        $diagnostics = array_merge(...array_map(fn(ScanContribution $item): array => $item->diagnostics, $contributions));
        $routes = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'route'));
        $route = $routes[0];
        assertSame('GET /shop/checkout => App\\Http\\Controllers\\CheckoutController::show', $route->canonicalName);
        assertSame(['web', 'auth', 'verified'], $route->attributes['middleware']);
        assertSame('shop.checkout', $route->attributes['name']);
        $matchRoute = array_values(array_filter(
            $routes,
            fn(NodeFact $node): bool => $node->canonicalName === 'GET|POST /matched => App\\Http\\Controllers\\CheckoutController::show',
        ));
        assertSame(1, count($matchRoute));
        assertSame(['GET', 'POST'], $matchRoute[0]->attributes['methods']);
        assertSame('/matched', $matchRoute[0]->attributes['uri']);
        assertSame('App\\Http\\Controllers\\CheckoutController::show', $matchRoute[0]->attributes['action']);

        $edgeTuples = array_map(
            fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
            $edges,
        );
        assertArrayContains([
            'routes_to',
            'php:route:' . $route->canonicalName,
            'php:method:App\\Http\\Controllers\\CheckoutController::show',
        ], $edgeTuples);
        assertArrayContains([
            'dispatches',
            'php:method:App\\Http\\Controllers\\CheckoutController::show',
            'php:class:App\\Events\\CheckoutCompleted',
        ], $edgeTuples);
        assertSame(3, count(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'uses_middleware')));
        assertSame(1, count(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'binds')));
        assertSame(1, count(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'listens_to')));
        assertSame(1, count(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'handles')));
        assertSame(1, count(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'observes')));
        assertSame(
            ['LARAVEL_DYNAMIC_ROUTE_URI', 'LARAVEL_DYNAMIC_ROUTE', 'LARAVEL_DYNAMIC_ROUTE_URI'],
            array_map(fn(Diagnostic $diagnostic): string => $diagnostic->code, $diagnostics),
        );

        $plain = $this->phpWorkerClient();
        $plainRoutes = iterator_to_array($plain->scan(['root' => $root, 'files' => ['routes/web.php']]));
        $plain->shutdown();
        assertSame([], array_values(array_filter(
            $plainRoutes[0]->nodes,
            fn(NodeFact $node): bool => $node->kind === 'route',
        )));
    }

    #[Group('laravel')]
    public function testLaravelScanPersistsExplicitPathAndNamingRoleConfidence(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-laravel-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate Laravel database.');
        }
        $root = self::repositoryRoot() . '/tests/Fixtures/laravel';
        try {
            $pdo = SqliteConnection::open($path);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, 'Laravel Fixture');
            assertSame(3, $result->data['diagnostics']);
            assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route'")->fetchColumn());
            assertSame(3, (int) $pdo->query("SELECT COUNT(*) FROM edges WHERE kind = 'uses_middleware'")->fetchColumn());
            assertSame(1, (int) $pdo->query(
                "SELECT COUNT(*) FROM classifications WHERE role = 'laravel.controller' AND origin = 'framework_convention' AND confidence = 'certain'",
            )->fetchColumn());
            assertSame(1, (int) $pdo->query(
                "SELECT COUNT(*) FROM classifications WHERE role = 'laravel.event' AND origin = 'framework_convention' AND confidence = 'probable'",
            )->fetchColumn());
            assertSame(1, (int) $pdo->query(
                "SELECT COUNT(*) FROM classifications WHERE role = 'laravel.queued' AND confidence = 'certain'",
            )->fetchColumn());
            foreach (['laravel.command', 'laravel.middleware', 'laravel.repository', 'laravel.listener', 'laravel.policy', 'laravel.model', 'laravel.provider'] as $role) {
                $statement = $pdo->prepare('SELECT COUNT(*) FROM classifications WHERE role = :role');
                $statement->execute(['role' => $role]);
                assertSame(true, (int) $statement->fetchColumn() >= 1);
            }
            $architecture = new ArchitectureQueryService($pdo);
            $flow = $architecture->explainFlow(
                $result->projectId,
                'GET /shop/checkout',
                'CheckoutCompleted',
            );
            assertSame(['routes_to', 'dispatches'], array_column($flow->data['paths'][0]['hops'], 'kind'));
            assertSame(3, $flow->data['paths'][0]['score']['minimum_confidence']);
            $impact = $architecture->impactAnalysis($result->projectId, 'CheckoutCompleted');
            assertSame(true, count($impact->data['boundaries']) >= 1);
            assertSame(true, count(array_filter(
                $impact->data['entry_points'],
                fn(array $entry): bool => $entry['node']['kind'] === 'route',
            )) >= 1);
        } finally {
            unset($pdo);
            foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }
}
